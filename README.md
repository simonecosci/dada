# dada

Continuous Delivery di stack Docker su server propri.

## Sviluppo

```bash
composer install
npm install
composer run dev
php artisan test
```

## Installazione con Docker

L'immagine (`ghcr.io/simonecosci/dada`) si avvia con `DADA_ROLE=web|worker|scheduler|reverb|all` (default `all`):

| Ruolo       | Processi                                                                                                              |
| ----------- | --------------------------------------------------------------------------------------------------------------------- |
| `web`       | nginx + php-fpm sulla porta 8000 (proxy websocket `/app` e `/apps` verso `REVERB_UPSTREAM`, default `127.0.0.1:8080`) |
| `worker`    | `php artisan horizon` (code `default` e `deploy`)                                                                     |
| `scheduler` | `php artisan schedule:work`                                                                                           |
| `reverb`    | `php artisan reverb:start --host=0.0.0.0 --port=8080`                                                                 |
| `all`       | tutti i precedenti nello stesso container                                                                             |

All'avvio l'entrypoint genera la configurazione nginx, esegue `php artisan migrate --force` (solo se `DADA_MIGRATE=true`; default `true` per `web` e `all`, `false` per gli altri ruoli), esegue `php artisan optimize` e avvia `supervisord` in foreground.

```bash
cp docker-compose.example.yml docker-compose.yml
cp .env.example .env   # imposta APP_KEY, DB_PASSWORD, REVERB_APP_KEY, REVERB_APP_SECRET
docker compose up -d
docker compose exec app php artisan dada:admin tu@example.com
```

`APP_KEY` si genera con `docker compose run --rm app php artisan key:generate --show`; le credenziali Reverb con `openssl rand -hex 16` (key) e `openssl rand -hex 32` (secret). Le variabili `DADA_*` sono documentate in `.env.example`.

### Build dell'immagine

```bash
docker build -t dada:dev .
```

Le variabili `VITE_REVERB_*` vengono incorporate nel bundle JS in fase di build; per un'immagine con valori diversi dai default si passano come build arg:

```bash
docker build -t dada:dev \
  --build-arg VITE_REVERB_APP_KEY=<REVERB_APP_KEY> \
  --build-arg VITE_REVERB_HOST=dada.example.com \
  --build-arg VITE_REVERB_PORT=443 \
  --build-arg VITE_REVERB_SCHEME=https .
```

Default: host `localhost`, porta `8000`, schema `http`, key vuota. La chiave Reverb e' pubblica (finisce nel client), non e' il secret. Lo stage degli asset include PHP e `vendor` perche' il plugin Wayfinder di Vite esegue `php artisan wayfinder:generate`.

Per uno smoke test locale usa un `docker-compose.override.yml` (ignorato da git) che imposta `image: dada:dev` sui servizi `app`, `worker`, `scheduler` e `reverb`.
