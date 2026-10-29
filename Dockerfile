# syntax=docker/dockerfile:1

# ---- base: PHP 8.5 FPM + system packages + PHP extensions (shared by all stages)
# opcache is built into PHP 8.5 (no docker-php-ext-install needed)
FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache nginx supervisor openssh-client sshpass gettext bash \
        libpq icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql intl zip bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

# ---- vendor: composer dependencies (real pcntl/posix present, no platform flags needed)
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --no-progress

# ---- assets: Vite build. The Wayfinder plugin runs `php artisan wayfinder:generate`,
# so this stage needs PHP + vendor + the app source, plus Node.
FROM base AS assets
RUN apk add --no-cache nodejs npm
ARG VITE_APP_NAME=dada
# No VITE_REVERB_* here: the browser gets the Reverb client config at runtime
# (shared Inertia prop `reverb`: REVERB_APP_KEY + optional REVERB_CLIENT_*).
ENV VITE_APP_NAME=$VITE_APP_NAME
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN npm run build

# ---- final image
FROM base AS final

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-dada.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/nginx.conf.template /etc/dada/nginx.conf.template
COPY docker/supervisord/ /etc/dada/supervisord/
COPY docker/entrypoint.sh /usr/local/bin/dada-entrypoint
RUN chmod +x /usr/local/bin/dada-entrypoint

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build

RUN mkdir -p storage/app/private/ssh storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER www-data

ENV DADA_ROLE=all
EXPOSE 8000 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD [ -f /tmp/healthy ] || wget -qO- http://127.0.0.1:8000/up >/dev/null || exit 1

ENTRYPOINT ["dada-entrypoint"]
