# dada — Design

Date: 2026-10-29
Status: draft pending approval

## 1. What it is and why

**dada** is an open source web application for the Continuous Delivery of Docker stacks on your own servers. You define **projects** (e.g. "wordpress") with **versions**. A version is installed on a host of a **deployment**, and from there the app generates the deploy files (`docker-compose.yml`, `.env`, `.env.<project>`, restart scripts). The app uploads them via SSH/SFTP and runs the remote commands. Everything happens through asynchronous jobs with real-time logs.

The concept comes from a proprietary internal tool (admink-deploy) that the user knows well. dada is an **independent rewrite from scratch**:

- no ties to admink-deploy or admink-release;
- no synchronization with external systems;
- no reused code.

Projects are born inside dada.

The good concepts of the old tool are kept:

- deployment → host → installations hierarchy;
- editable per-host copy;
- required variables;
- per-service restart scripts;
- compose/swarm.

Its flaws are avoided:

- yaml built from string fragments with placeholders and regexes;
- silent fallbacks on parsing errors;
- hard-wired names (`kplatform`, special `queue` service, `VERSION_*` keys);
- webssh password passed in the query string;
- monolithic 1000-line services.

### Success

The first milestone is the **complete cycle**:

1. I create a project and a version;
2. I register a credential and a host (even behind a bastion);
3. I create a deployment and install the version on a host;
4. I edit variables and yaml;
5. I generate the files;
6. I deploy with a live log;
7. I restart the services.

The rest (webssh, monitoring, maintenance, MCP) follows in later phases.

### Non-goals

- **VPN management.** The dada container must already sit in a network that reaches the hosts or their bastions.
- **Multi-tenancy (several isolated organizations).** It is one installation per team.
- **Building the images of managed projects.** dada deploys existing images and does not do CI for customers' sites.
- **Kubernetes.** Only Docker Compose and Docker Swarm.
- **Alert channels other than email**, in this version.

### Anti-goals (failure even if it "works")

- Generating invalid yaml without saying so. Every parsing or validation error blocks generation with a clear message.
- Showing secrets to people without permission, or writing them to job logs.
- Overwriting changes made on an installation in dada during a version update without the user having seen and accepted them. Detecting manual changes made to files on the server, outside dada, is outside the first milestone: dada is the source of truth and the pre-deploy backup preserves them. _[designer's call]_
- Two concurrent deploys on the same deployment.

## 2. Users and permissions

It is a team with different roles. Permissions are **per deployment**.

- **Admin** (global): manages users, credentials and hosts, creates deployments and performs every action on every deployment.
- **Per-deployment roles**, assigned user × deployment. Each role includes those below it.
    - **Operator**:
        - installs, updates versions, edits installations;
        - generates and deploys, restarts, runs project commands;
        - uses webssh on the deployment's hosts;
        - **adds and removes hosts** of the deployment, choosing them among the registered hosts;
        - **manages the members** of the deployment, assigning at most the Operator role.
    - **Viewer**: sees everything (status, generated files, logs, recordings) except secret values, which they see masked.
- **Maintainer** (global, additional permission): creates and edits catalog projects and versions, including secret defaults.

Creating a new deployment is an Admin action. _[designer's call: whoever creates it becomes Operator; opening it to everyone is a configuration flag]_

**Plaintext secrets**: Operator, Maintainer (on the catalog) and Admin can reveal them with a "show" button. Every reveal is recorded with user, secret and time. The APIs return secrets masked; revealing is a dedicated endpoint. The MCP never returns them.

A user without a role on a deployment does not see it.

API/MCP tokens (Sanctum personal access tokens) inherit the permissions of their user. The token's _abilities_ can be restricted, for example to read-only.

## 3. Domain model

### Catalog

**Project**

- name, slug (used in file names: `.env.<slug>`, `<slug>.sh`), description, optional icon.

**ProjectVersion**

- belongs to a Project;
- `version` (free string, e.g. `1.2.0`), release notes;
- **services**: the compose definition of the project's services, stored structured (JSON/array, edited as YAML in the editor but validated and parsed on save);
- optional additional top-levels: volumes, networks, configs;
- **variables**: list of variables;
- **commands**: list of predefined commands;
- state: `draft` / `published`. Only published versions can be installed. _[designer's call]_

A published version is immutable; to change it you clone it. _[designer's call, needed to make the update diff reliable]_

**Clone version** creates a new `draft` version that copies the source's services, variables and commands.

**Variable** (inside a version or an installation)

- `key`, `default`, `description`;
- `required` (bool): the deploy is blocked if the effective value is empty;
- `secret` (bool): encrypted at rest, masked in UI/API/logs;
- `scope`:
    - `container`: goes in `.env.<slug>`, i.e. inside the container via `env_file`;
    - `compose`: goes in `.env`, for `${VAR}` interpolation in the yaml;
    - `host-global`: goes in `.env` and is shared among the host's installations.

**Command** (inside a version or an installation)

- name, description;
- `target`: `service:<name>`, run with `docker compose exec` / `docker exec` on the task, or `host`, run in the installation folder;
- `command` (string);
- `dangerous` (bool): requires explicit confirmation in the UI and in the MCP.

### Infrastructure

**Credential**

- name, type `password` | `ssh-key` (private key + optional passphrase) | `registry` (registry URL, username, password/token), optional username;
- data encrypted with Laravel's `encrypted` cast (APP_KEY);
- never returned in plaintext by the APIs.

**Host**

- name, hostname/IP, port, username, credential;
- optional `jump_host_id` (FK on Host). Jumps can be chained; cycles are forbidden;
- `sudo` (prefix command, e.g. `sudo -n`, empty = none);
- host key fingerprint: stored on first connection (TOFU), then verified; if it changes, the connection fails with an explicit error;
- status: reachable/unreachable, last check, collected info (OS, docker version, CPU, RAM, disk).

### Installations

**Deployment**

- name, description, environment (free label, e.g. production/staging);
- members with role.

**DeploymentHost**

- a Host inside a Deployment;
- `path` (installation folder), `orchestrator`: `compose` | `swarm`;
- `stack_name` (for swarm, default = deployment slug);
- associated **registry credentials** (zero or more `registry`-type Credentials);
- the host's `host-global` variables (with the same Variable schema);
- optional **compose template**: top-level networks/volumes shared by all the host's installations;
- the same physical Host can appear in several deployments with different paths.

**Installation**

- a ProjectVersion installed on a DeploymentHost: it is a **complete, editable copy** of services, variables and commands;
- reference to the originating ProjectVersion (for the update diff);
- the installation's own `slug`, defaulting to the project slug and unique on the DeploymentHost. The `.env.<slug>` and `<slug>.sh` file names use this slug, so the same project can be installed twice on the same host (e.g. two wordpress), as long as service names do not collide. _[designer's call]_
- `order` (order in the restart);
- for each variable, the value set on the installation or "use the default". This distinguishes "left at default" from "modified".

## 4. File generation

For each DeploymentHost, dada generates a set of files in memory. It shows them in a preview (with secrets masked for those who cannot see them) and uploads them at deploy time.

### docker-compose.yml

It is built **as a data structure** and serialized once with `symfony/yaml`:

1. start from the host template (shared networks/volumes);
2. merge the services of each installation in `order`. A service name collision between installations is a blocking error;
3. add `env_file: .env.<slug>` to every service with `container` variables;
4. merge the installations' top-level volumes/networks;
5. validate the result: minimal compose structure and references to declared networks/volumes. Optional: `docker compose config -q` run on the host before the up.

There are no textual placeholders: variables stay `${VAR}` in the yaml and are resolved by Docker reading `.env`.

### .env

It contains:

- all the `compose` variables of all the host's installations;
- the `host-global` ones.

A key conflict between installations with different values is a blocking error, which the user resolves by moving the variable to `host-global`.

**`host-global` variables declared by a version** _[designer's call]_:

- **At installation**: if the key does not yet exist on the DeploymentHost, it is created there with the version's default and flags. If it already exists, the host's one is kept and the difference in default or flags is reported. `required` and `secret` are combined with OR.
- **Afterwards**: the variable lives on the host and not on the installation.
- **Removal or update of the installation**: the variable is not deleted, but flagged as "no longer used" if no installation declares it.

### .env.<slug>

One per installation, with the `container` variables.

### Secrets

In the first version secrets end up in the `.env*` files on the host, with `600` permissions, owned by the deploy user. _[designer's call: it is simple and compatible with any image]_

Docker secrets (`/run/secrets`, `*_FILE`) are a future evolution, outside the first milestone.

### Scripts

They are generated from Blade templates.

- **`<slug>.sh`**: one per installation. Usage: `./<slug>.sh start|stop|restart|status [service|all]`.
    - **compose**: `docker compose up -d <services>` / `stop` + `rm -f` / `ps`.
    - **swarm**:
        - `start` / `restart`: `docker service update --force <stack>_<service>`, or scale to N replicas if the service had been stopped;
        - `stop`: `docker service scale <stack>_<service>=0`;
        - `status`: `docker service ps`.
        - The number of replicas to restore comes from the service definition.
    - Services marked as _init_ (a service property in the version, e.g. one-shot migrations) are run and awaited before the others in `start`/`restart`. A non-zero exit code aborts the script.
        - In compose: `docker compose run --rm <init>`.
        - In swarm: `docker run --rm` on the manager, with the same image, the same env files and the service's network (`attachable` overlay network).
        - Inits are not part of the stack.
- **`restart.sh`**: single per host. Usage: `./restart.sh start|stop|restart|status`.
    - In **compose** it calls the installations' scripts in `order`.
    - In **swarm** it loads `.env` (because `stack deploy` does not read it by itself), runs the inits, then `docker stack deploy -c docker-compose.yml --with-registry-auth --prune <stack>`.
    - It does not destroy and recreate the swarm. Swarm initialization (`docker swarm init`) is a separate, explicit action in the UI.

### Swarm: topology

Every DeploymentHost in swarm mode is a **manager**. dada sends it the files and launches the stack there. Any workers of the cluster are not managed by dada and do not receive files. There is no "cluster" object: a swarm deployment with several hosts means several managers, each with its own stack. _[interpretation of the answer "every host is a manager"]_

### Removed installation

When an installation is removed from a DeploymentHost, the next deploy's screen shows an explicit warning: "services X, Y will be stopped and removed". On the confirmed deploy this happens:

- **compose**: `up --remove-orphans`;
- **swarm**: `stack deploy --prune`;
- `.env.<slug>` and `<slug>.sh` are deleted.

_[designer's call]_

## 5. Version update

An installation on `wordpress 1.0` is brought to `1.1`. dada shows:

- **Variables**:
    - **added**: they will be added with their default;
    - **removed**: they will be removed;
    - **default changed**: if on the installation the variable was "use the default" it takes the new default; if it had been set, the set value stays. In both cases the difference is shown.
    - The set value (override) is never touched.
- **Services/yaml and commands**: side-by-side view (Monaco diff editor). On the left is the current installation, on the right the new version. The user chooses block by block and can hand-tweak the result.
- **The diff engine is three-way** _[designer's call]_: base = originating version, local = installation, remote = new version. This tells which differences are local changes and which are news from the version. In the UI it is used to highlight blocks; the choice stays with the user. The MCP uses it to decide whether the update is "conflict-free": no block modified both locally and in the new version.
- Confirm or cancel. Nothing is applied automatically.

The update changes only the installation in the DB. The deploy remains a separate action.

## 6. Deploy

It is an asynchronous job (`DeployJob`) per deployment, runnable on all hosts or on just one.

- **Lock**: an atomic lock (Redis) per deployment prevents two concurrent jobs. The lock has a TTL, so a dead worker does not leave it hanging forever.

For each host, in sequence:

1. SSH connection (through the jump hosts);
2. preconditions check: required variables set, generation without errors, enough disk space (configurable threshold);
3. creation of the `path` folder;
4. backup of the current files to `path/.dada/backups/<timestamp>/`, with configurable retention;
5. SFTP upload of all generated files, then permissions (`600` for `.env*`, `+x` for scripts);
6. `docker login` on every registry associated with the DeploymentHost, with the password passed via stdin and never on the command line; then `docker compose pull` / image pull (optional, on by default);
7. `./restart.sh restart` (optional, on by default).

These points apply to the whole job:

- **Log**: every step and every line of remote output is streamed live via Reverb on the job's private channel and saved to the history. Secrets are masked in the output.
- **Outcome**: success or failure per host and overall, with a browsable history.
- **Snapshot**: every successful deploy saves to the DB a snapshot of the state of the host's installations (originating version, services, variables, commands) and of the generated files.
- **Rollback** to a previous deploy: it is an explicit action, not automatic. It restores both the snapshot in the DB and the files on the host (upload + restart), so the next deploy does not reapply what was undone. _[designer's call; the snapshot is needed from phase 1, the UI rollback arrives in phase 2]_

Other jobs: Restart (host or single service), RunCommand (project command), HostCheck (info and reachability collection), Prune.

The deploy can be scheduled at a date and time (scheduler).

## 7. WebSSH (later phase)

- It is a terminal in the browser (xterm.js) to a host, through the jump hosts.
- The SSH session is opened server-side with phpseclib; the bytes pass over a websocket channel. _[designer's call: the exact way (dedicated process per session, custom ws server next to Reverb, or sidecar) is left to the builder, as long as credentials never leave the server]_
- Access: Operator of the deployment containing the host, or Admin.
- **Full recording** of every session: output with timing (asciicast v2 format) plus input, saved and replayable from the UI with a player. User, host, start and end are also recorded.
- Configurable retention.

## 8. Scheduler (later phase)

- **Cleanup**:
    - prune of unused docker images on the hosts;
    - deploy backups older than the retention;
    - job logs and ssh recordings older than N days.
- **Monitoring**: periodic check of:
    - host reachable;
    - stopped containers/services or ones in a restart loop;
    - disk above threshold;
    - configurable HTTP checks per installation (URL + expected status);
    - expiring TLS certificates (from the HTTP check URLs).
- **Email alerting** to the deployment's members, with a configurable role. One notice is sent on opening and one on resolution, with no repeats at every check. Open alerts are visible in the UI.
- **Maintenance**: assigned to a user, on a deployment or a host, with date and description. 3 days before, the assignee receives a reminder email (the number of days is configurable). States: planned / done / cancelled.

## 9. MCP server (later phase)

It uses `laravel/mcp` and is exposed over HTTP by the app. It authenticates with a Sanctum personal token and applies the same per-deployment permissions.

- **Reading and diagnosis**:
    - lists deployments, hosts, installations, projects and versions;
    - status of containers and services;
    - log of a service (tail);
    - outcome of the latest jobs;
    - open alerts;
    - generated files, with secrets always masked.
- **Actions**:
    - deploy;
    - version update: only if without yaml conflicts, otherwise it returns the diff and refers to the UI;
    - restart;
    - running project commands.
- Destructive actions (stop, `dangerous` commands) require an explicit confirmation parameter.

Actions go through the same services/policies as the UI, with no duplicated logic.

## 10. Stack and packaging

The stack is decided as follows.

- **Backend**:
    - Laravel 13, PHP 8.5;
    - PostgreSQL by default (MySQL/MariaDB must work: no specific SQL);
    - Redis for queues, cache, locks;
    - Horizon for workers;
    - Reverb for websockets;
    - phpseclib 3 for SSH/SFTP;
    - symfony/yaml;
    - laravel/mcp;
    - Sanctum.
- **Frontend**:
    - Vue 3 + TypeScript + Inertia + Vite;
    - Tailwind + PrimeVue _[designer's call]_;
    - xterm.js;
    - Monaco editor (yaml and diff).
- **Authentication**: session login, optional TOTP 2FA _[designer's call]_.
- **Tests**: Pest. Unit tests on generation (input → expected files, snapshot), on the update diff, on policies. Feature tests on the APIs. SSH operations sit behind an interface with a fake for tests.
- **App Docker image**:
    - single nginx + PHP 8.5-fpm image with supervisord;
    - an environment variable chooses the container's role: `web` | `worker` | `scheduler` | `reverb` (or `all` for small installations);
    - includes a sample `docker-compose.yml` with app, postgres and redis.
- **CI (GitHub Actions)**:
    - lint (Pint, ESLint) and Pest tests on push and PR;
    - multi-arch build (amd64/arm64) and push to GitHub Container Registry `ghcr.io/<owner>/dada` with tags `latest` (main), `sha-<short>`, and semver on git tags.
- **License**: the one already in the repo.

## 11. Phases

1. **Foundations and complete cycle** (first milestone):
    - auth and users, per-deployment roles;
    - credentials, hosts with jump host and TOFU;
    - projects, versions (clone, publish), variables, commands;
    - deployments, deployment hosts, installations;
    - file generation with preview;
    - DeployJob with lock, backup, live log via Reverb;
    - restart;
    - docker image + CI;
    - Pest tests.
2. **Version update** with variable diff and side-by-side yaml merge; rollback from backup; project commands from the UI; scheduled deploys.
3. **WebSSH** with recording and player.
4. **Scheduler**: cleanup, monitoring, email alerting, maintenance with reminders.
5. **MCP server**.

## 12. Left to the builder

- Detailed DB schema, table names, code folder structure.
- The technical mechanism of webssh (see §7), with the stated constraints.
- The UI layout and navigation, as long as the described functions are there.
- The exact format of the bash scripts, as long as it respects the `start|stop|restart|status [service|all]` interface.
- The scheduler's default intervals and thresholds.
