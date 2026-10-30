# dada

Continuous delivery of Docker stacks to your own servers.

dada is an open source web app to define **projects** (e.g. `wordpress`) with **versions**, install them on the hosts of a **deployment**, and deploy them over SSH. For every host dada generates the deploy files: `docker-compose.yml`, `.env`, `.env.<project>` and restart scripts. It uploads them and runs the remote commands in background jobs with live logs.

## Features

- **Catalog.**
    - Projects have versions. You can clone a version or publish it, and a published version is immutable.
    - Each version holds its compose services (edited as YAML, stored as structured data) and its variables.
    - A variable can be required or secret, and has a scope: `container` (goes into `.env.<slug>`), `compose` (goes into `.env`) or `host-global`.
- **Infrastructure.**
    - Credentials: password, SSH key or registry. They are encrypted at rest and never returned in clear text.
    - Hosts can sit behind chained jump hosts, use a sudo prefix and have health checks.
    - The host key is trusted on first use: it is stored at the first connection, and a later change fails the connection.
- **Deployments.**
    - Each deployment has a team with per-deployment roles: Admin, Operator, Viewer, plus a Maintainer permission for the catalog.
    - An installation is an editable copy of a version on one host of the deployment.
    - The orchestrator is Docker Compose or Docker Swarm, chosen per host.
- **Generation.**
    - Files are previewed before they are uploaded, with secrets masked for users who cannot reveal them.
    - The YAML is built as a data structure, never from string templates.
    - Generation stops with a clear error on any conflict or invalid input.
- **Deploy.**
    - Only one deploy runs per deployment at a time.
    - Before a deploy, dada takes a backup and checks free disk space.
    - It logs in to private registries and removes the services of uninstalled installations.
    - Logs stream live over websockets, with secrets masked.
- **Restart.**
    - Each installation gets a `<slug>.sh start|stop|restart|status [service|all]` script.
    - Each host gets a `restart.sh` that restarts every installation on it.

Planned next: version upgrades with a side-by-side diff, rollback, project commands from the UI, scheduled deploys, a web SSH console with session recording, monitoring and maintenance reminders, and an MCP server for LLM clients.

## Running with Docker

The image is published as `ghcr.io/simonecosci/dada`.

```bash
cp docker-compose.example.yml docker-compose.yml
cp .env.example .env
```

Fill in `.env`:

| Variable                               | How to get it                                                                           |
| -------------------------------------- | --------------------------------------------------------------------------------------- |
| `APP_KEY`                              | `docker run --rm --entrypoint php ghcr.io/simonecosci/dada artisan key:generate --show` |
| `DB_PASSWORD`                          | any strong password                                                                     |
| `REVERB_APP_KEY` / `REVERB_APP_SECRET` | `openssl rand -hex 16` / `openssl rand -hex 32`                                         |
| `DADA_ADMIN_EMAIL`                     | email of the first admin                                                                |
| `DADA_ADMIN_PASSWORD`                  | optional, see below                                                                     |

Then start the stack:

```bash
docker compose up -d
docker compose logs init
```

### Install and update

The `init` service runs before every other dada service and then exits:

1. `php artisan migrate --force` creates the schema on the first start and applies new migrations on upgrades.
2. `php artisan db:seed --force` creates the first admin from `DADA_ADMIN_EMAIL`, with `DADA_ADMIN_NAME` as an optional name.

The seeder only acts while no admin exists, so changing these variables later has no effect. If `DADA_ADMIN_PASSWORD` is empty, a random password is generated and printed once in the `init` log. Copy it from `docker compose logs init`.

To add or promote admins later, run:

```bash
docker compose exec app php artisan dada:admin someone@example.com
```

To upgrade, pull the new image and recreate the stack. `init` migrates first, then the other services start:

```bash
docker compose pull
docker compose up -d
```

### Container roles

Each container picks its processes with `DADA_ROLE`:

| Role        | Runs                                                                                                                         |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `init`      | migrations and the seeder, then exits                                                                                        |
| `web`       | nginx + php-fpm on port 8000. Websocket paths `/app` and `/apps` are proxied to `REVERB_UPSTREAM` (default `127.0.0.1:8080`) |
| `worker`    | `php artisan horizon` (queues `default` and `deploy`)                                                                        |
| `scheduler` | `php artisan schedule:work`                                                                                                  |
| `reverb`    | `php artisan reverb:start --host=0.0.0.0 --port=8080`                                                                        |
| `all`       | install step + web, worker, scheduler and reverb in one container (the default)                                              |

On start the entrypoint does the following:

1. Renders the nginx config for the `web` and `all` roles.
2. Runs the install step when `DADA_INSTALL=true`. That is the default for `init` and `all`; set it to `true` on another role only if you run without an `init` service.
3. Runs `php artisan optimize`.
4. Starts `supervisord` in the foreground.

The dada container must be on a network that can reach your hosts, or their jump hosts. A worker running a deploy is stopped gracefully: the example compose file gives it a one-hour `stop_grace_period`.

### Configuration

All variables are documented in [.env.example](.env.example). The dada-specific ones:

| Variable                         | Default             | Meaning                                                        |
| -------------------------------- | ------------------- | -------------------------------------------------------------- |
| `DADA_ROLE`                      | `all`               | container role, see above                                      |
| `DADA_INSTALL`                   | `true` for init/all | run migrations and seeder at start                             |
| `DADA_ADMIN_EMAIL`               |                     | first admin email                                              |
| `DADA_ADMIN_NAME`                | email local part    | first admin name                                               |
| `DADA_ADMIN_PASSWORD`            | generated           | first admin password                                           |
| `REVERB_UPSTREAM`                | `127.0.0.1:8080`    | where nginx proxies websocket traffic                          |
| `REVERB_CLIENT_HOST/PORT/SCHEME` | page origin         | Reverb endpoint the browser connects to                        |
| `DADA_DEPLOY_MIN_FREE_MB`        | `1024`              | minimum free disk space on a host before a deploy              |
| `DADA_DEPLOY_BACKUP_RETENTION`   | `10`                | pre-deploy backups kept per deployment host                    |
| `DADA_DEPLOY_LOCK_TTL`           | `3600`              | TTL in seconds of the deploy lock, also the deploy job timeout |
| `DADA_DEPLOY_RETRY_AFTER`        | lock TTL + 120      | `retry_after` of the `redis-deploy` queue connection           |
| `DADA_SSH_CONNECT_TIMEOUT`       | `10`                | SSH connect timeout in seconds                                 |
| `DADA_SSH_COMMAND_TIMEOUT`       | `1800`              | SSH command timeout in seconds                                 |

The Reverb client config is not baked into the frontend bundle. The browser receives it at runtime, and the secret is never sent. With the Docker image the defaults work as they are, because nginx forwards `/app` to Reverb on the same origin.

`.env.example` ships production defaults (`APP_ENV=production`, `APP_DEBUG=false`). With `APP_ENV=local` the Horizon dashboard is open to anyone, so use `local` only on a development machine.

## Development

Requirements: PHP 8.5, Composer, Node.js, PostgreSQL and Redis.

```bash
cp .env.example .env   # then set APP_ENV=local, APP_DEBUG=true and DADA_ADMIN_EMAIL
composer install
npm install
php artisan key:generate
php artisan migrate --seed
composer run dev
```

Run the tests:

```bash
php artisan test
```

The SSH integration tests are skipped unless a test SSH server is configured. CI runs them against throwaway containers, including a jump-host case; see [.github/workflows/ci.yml](.github/workflows/ci.yml).

### Building the image

```bash
docker build -t dada:dev .
```

For a local smoke test, add a `docker-compose.override.yml` (git-ignored) that sets `image: dada:dev` on the `init`, `app`, `worker`, `scheduler` and `reverb` services.

CI runs on every push and pull request. When every job passes on `main` or on a `v*` tag, the image is published to `ghcr.io`.

## Stack

Laravel 13, PHP 8.5, Inertia + Vue 3 + TypeScript, shadcn-vue, PostgreSQL, Redis, Horizon, Reverb and Pest.

## License

[MIT](LICENSE)
