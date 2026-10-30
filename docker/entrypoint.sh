#!/bin/bash
set -eu

cd /var/www/html

role="${DADA_ROLE:-all}"
case "$role" in
    web|worker|scheduler|reverb|all|init) ;;
    *) echo "DADA_ROLE non valido: '$role' (web|worker|scheduler|reverb|all|init)" >&2; exit 64 ;;
esac

# Install/update (migrazioni + seeder del primo admin): di default solo `init`,
# che poi esce, e `all`, che e' l'unico container dello stack.
case "$role" in
    init|all) install_default=true ;;
    *) install_default=false ;;
esac
install="${DADA_INSTALL:-$install_default}"

mkdir -p storage/app/private/ssh storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

if [ "$role" = "web" ] || [ "$role" = "all" ]; then
    export REVERB_UPSTREAM="${REVERB_UPSTREAM:-127.0.0.1:8080}"
    envsubst '${REVERB_UPSTREAM}' < /etc/dada/nginx.conf.template > /tmp/nginx.conf
fi

if [ "$install" = "true" ]; then
    php artisan migrate --force
    php artisan db:seed --force
fi

if [ "$role" = "init" ]; then
    exit 0
fi

php artisan optimize

# supervisord.conf = intestazione + i programmi del ruolo.
{
    cat <<'CONF'
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid

CONF
    case "$role" in
        all) programs="web worker scheduler reverb" ;;
        *) programs="$role" ;;
    esac
    for p in $programs; do
        cat "/etc/dada/supervisord/$p.conf"
        echo
    done
} > /tmp/supervisord.conf

# Per i ruoli senza HTTP l'HEALTHCHECK accetta questo file.
if [ "$role" != "web" ] && [ "$role" != "all" ]; then
    touch /tmp/healthy
fi

exec /usr/bin/supervisord -c /tmp/supervisord.conf
