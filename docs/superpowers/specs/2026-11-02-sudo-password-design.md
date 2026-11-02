# Sudo with password on managed hosts — design

Status: draft for review. Extends `2026-10-29-dada-design.md` (Host, deploy pipeline, host check). Calls marked _[designer's call]_ were made while writing this document, not by the maintainer.

## Intent

A typical VPS has only Docker installed. The operator logs in with an SSH key, and `sudo` asks for a password. dada must deploy to such a host with no manual preparation on the server: no sudoers changes, no `docker` group membership, no directories created by hand.

Today this does not work. A host has only a free-text **sudo prefix** (`hosts.sudo`, e.g. `sudo -n`) that is prepended as is. dada runs commands over `ssh` without a terminal, so a sudo that needs a password fails (`sudo -n`: "a password is required"; plain `sudo`: "a terminal is required"). The deploy also uploads files as the login user after a `chown`, so the deployment directory must be writable by that user.

## Goals

- dada creates the deployment host directory (`deployment_hosts.path`, already configurable per deployment host) and everything inside it, on a server that has only Docker.
- Every remote operation is done by the host's configured user, which is one of:
  - a user that needs no privilege escalation (root, or a user in the `docker` group that owns the paths);
  - a sudoer with passwordless sudo;
  - a sudoer whose sudo asks for a password.
- When the host uses sudo, **every** operation on the deployment directory goes through sudo: creating the directory, the backup, writing the files, removals, `docker login`, and running the scripts (`sudo` + `./restart.sh …`, `./<slug>.sh …`, run from the deployment directory).
- The sudo password is stored and handled like every other credential secret: encrypted at rest, masked in UI, API and run logs, and write-only (credentials have no reveal endpoint today, and this feature does not add one) _[designer's call]_.
- A wrong or missing sudo password is reported clearly and early, never as a deploy that fails halfway.

## Non-goals

- Interactive sudo through a pseudo-terminal (waiting for the `[sudo] password for …:` prompt and typing the password). It is fragile: the prompt changes with locale and sudo configuration, stdout and stderr are merged, stdin can no longer carry data.
- Switching back to phpseclib. The phase 1 plan replaced it with the OpenSSH client because phpseclib 3 has no `direct-tcpip` channels and therefore no jump hosts. `sudo -S` works the same with either library.
- Hosts whose sudoers has `requiretty`. This needs a terminal (see non-goal 1). The host check must report it as a sudo failure; supporting it is out of scope.
- Privilege escalation on jump hosts. Sudo only applies to the final (target) host of a chain.
- Other escalation tools (`doas`, `su`, `pkexec`).
- A persistent breaker that stops using a sudo credential after it fails. The limit is one failed attempt per `Host` per run (see 7); each new run, restart or host check tries again. Decided by the maintainer: the operator fixes the credential, and repeated failures across runs are accepted.

## Anti-goals

These would make the feature a failure even if deploys work:

- The sudo password appearing in a command line (visible in the remote `ps` and in audit logs), in a run log, in `deploy_runs.error`, in host facts, or in an API response.
- The sudo password being sent anywhere other than the stdin of a `sudo -S` invocation on the target host.
- A wrong password causing sudo to read other data as further password attempts (for example the contents of an `.env` file following the password on stdin).
- A wrong password locking the account (`pam_faillock` and similar) because dada retried it across many commands or deployment hosts within the same run.

## Design

### 1. Host privilege mode

The free-text `hosts.sudo` prefix is replaced by a structured mode _[designer's call]_:

| Mode | Meaning | Command form |
|---|---|---|
| `none` | no escalation: root, or a user that already has the needed permissions | `<cmd>` |
| `sudo` | passwordless sudo | `sudo -n <…>` |
| `sudo_password` | sudo that asks for a password | `sudo -S -p '' <…>`, password on stdin |

- `sudo_password` requires a **sudo credential**: a reference (`hosts.sudo_credential_id`) to an existing credential of type `password`. Only its secret is used; its username is ignored. It may be the same credential the host uses to log in. Reusing `Credential` gets encryption, write-only handling and the admin UI for free _[designer's call]_.
- A credential used as a sudo credential cannot be deleted, like a credential used for login or as a registry (`CredentialController::destroy`).
- Data migration of existing hosts: an empty `sudo` becomes `none`; any non-empty value becomes `sudo`. A custom prefix other than `sudo -n` is not preserved _[designer's call: phase 1 only shipped `sudo -n` in tests and docs; the builder should list affected hosts in the migration output if any exist]_.
- The host form replaces the "Sudo prefix" text field with the mode choice and, for `sudo_password`, a select of `password` credentials. The host detail page shows the mode and the credential name, never the secret.

### 2. Passing the password

- In `sudo_password` mode every escalated command is `sudo -S -p '' sh -c '<cmd>'`, and the password followed by a newline is written to the stdin of the SSH process. `OpenSshShell::exec()` already accepts `$stdin` (used today by `docker login --password-stdin`).
- **Rule: the stdin of a sudo invocation carries only the sudo password.** Nothing else may follow it. This is what makes a wrong password harmless: sudo reads EOF on its retries and fails. As a consequence, two existing stdin uses must change (see 3 and 4).
- The password is added to the run's `DeployLogger` secrets (and so to `SecretMasker`) before the first remote command, like registry secrets today.
- Each `exec()` opens a new SSH session, so sudo's timestamp cache cannot be relied on: the password is sent with every escalated command.

### 3. Uploading files

Today `put()` runs `umask 077; cat > <path>.dada-tmp && chmod && mv` in the deployment directory as the login user, with the file contents on stdin. With sudo this becomes two steps:

1. As the login user, without sudo, the contents are written to a private temporary location outside the deployment directory (`umask 077`, e.g. a `mktemp -d` directory), with the contents on stdin as today.
2. One sudo invocation moves the files into the deployment directory: for each file, copy into `<path>/<name>.dada-tmp`, set the final mode (`0600` for `.env*`, `0755` for `*.sh`, `0644` otherwise, as today), then `mv` over the target so the replacement stays atomic within the directory. The temporary location is removed afterwards, also on failure.

- Batching all the files of a deploy into one temporary directory and one sudo invocation is preferred over one round trip per file _[designer's call; the builder may choose otherwise if it complicates error reporting]_.
- In `none` mode the current single-step `put()` is kept.
- Final files are owned by root in sudo modes _[designer's call: it follows from writing them through sudo, and the `chown` of phase 1 goes away]_.

### 4. Registry login

`docker login --password-stdin` also reads stdin, which conflicts with the rule in 2. In sudo modes the registry password is written as the login user to a private temporary file (`umask 077`), then `sudo … sh -c 'docker login … --password-stdin < <file>'` runs, and the file is removed in the same command and on failure. In `none` mode the current behaviour is kept.

### 5. Creating the directory

`prepareFolder()` today runs three separate `sudo` calls (`mkdir && chown && chown -R`). In sudo modes it becomes a single escalated command that creates `<path>` and `<path>/.dada/backups`. The `chown` to the login user is removed, because the login user no longer writes into the directory directly. In `none` mode it stays `mkdir -p`.

### 6. Where sudo applies

Every command that `RemoteSteps::wrap()` builds today goes through the host's mode: backup, removals, `docker compose pull`, `./restart.sh …` in the deploy and in `RestartJob`, `./<slug>.sh …`, and the Docker command of the host check. `checkDiskSpace()` stays unescalated (it only reads `df`). The `cd '<path>' && <cmd>` inside `sh -c` stays as today, so scripts always run from the deployment directory.

### 7. Failing early

- **Deploy and restart runs:** in sudo modes, right after connecting, the pipeline runs `sudo -S -p '' -v` (or `sudo -n -v`). If it fails the host step fails with "sudo authentication failed on <host>" and no further command runs. Validation happens once per `Host` per run, not once per deployment host: several deployment hosts of a deployment can sit on the same server, and after a failure the remaining deployment hosts on that `Host` fail immediately without sending the password again _[designer's call]_.
- **Host check:** `HostCheckJob` runs the same validation before the Docker command and records the result in the facts (`sudo: ok` or `sudo_error` with the masked, truncated output). A sudo failure leaves the host reachable, like a Docker failure today.
- The error text must say "sudo", not just "exit 1", so the operator knows to check the sudo credential or `requiretty`.

### 8. Tests

- Feature tests with `FakeRemoteShellFactory`:
  - the command form for each mode;
  - that the stdin of every sudo call is exactly the password plus a newline;
  - that upload and registry login never put other data on a sudo stdin;
  - that the password never appears in any recorded command or log line;
  - that a failed validation stops the run before any other command.
- Migration test for the `sudo` → mode mapping.
- `ssh-integration` group: the `linuxserver/openssh-server` image used in CI supports `SUDO_ACCESS=true` with `USER_PASSWORD`. Add a target configured that way. The container has no Docker, so the test covers the privileged path without Docker _[designer's call: a full deploy would need the runner's Docker socket and CLI inside the container, which adds CI work and flakiness]_:
  - directory creation;
  - privileged upload with the final modes and root ownership;
  - an escalated non-Docker command run from the deployment directory;
  - a wrong password stopping at validation.

## Left to the builder

- The exact commands for the two-step upload and their quoting (POSIX quoting via `ScriptRenderer::quote`, LF line endings).
- Whether the mode lives in a PHP enum next to `Orchestrator` and `CredentialType` (expected) and how `RemoteSteps` exposes it.
- How `RemoteShell::put()` changes: a new method for batched privileged uploads, or a mode-aware `put()`. `FakeRemoteShell` must follow.
- UI layout of the mode selector in `admin/hosts/Form.vue`.
- Whether the README "Hosts" bullet needs updating (it does; wording is free).
