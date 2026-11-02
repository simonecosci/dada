# Sudo with Password Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let dada deploy to a host where the login user is root, a passwordless sudoer, or a sudoer whose sudo asks for a password, with every operation on the deployment directory going through sudo.

**Architecture:** A host gets a `privilege_mode` (`none` / `sudo` / `sudo_password`) and an optional `sudo_credential_id` pointing to a `password` credential. A small value object, `App\Infrastructure\Privilege`, builds every escalated command and the stdin that goes with it (the sudo password and nothing else). Uploads and registry logins in sudo modes stage data as the login user in a private `mktemp -d` directory and then move or read it with one sudo call. A per-run `PrivilegeCheck` validates sudo once per `Host` per run and refuses to retry a failed password within the run.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 5, Inertia + Vue 3 + TypeScript, OpenSSH client via symfony/process.

**Spec:** `docs/superpowers/specs/2026-11-02-sudo-password-design.md`

## Global Constraints

- English for all code, comments, messages, UI copy, tests and commits.
- Regexes anchored with `\A…\z`, never `^…$`.
- Every value interpolated into a remote command is quoted with `App\Generation\ScriptRenderer::quote()` (POSIX quoting).
- The sudo password goes only on the stdin of a `sudo -S` call, followed by `"\n"` and nothing else. It never appears in a command line, run log, `deploy_runs.error`, `deploy_run_hosts.error`, host facts or HTTP response.
- Command forms, exactly:
  - `none`: `<cmd>`;
  - `sudo`: `sudo -n -H sh -c '<cmd>'`;
  - `sudo_password`: `sudo -S -p '' -H sh -c '<cmd>'`.
- Validation commands, exactly: `sudo -n -v` (`sudo`), `sudo -S -p '' -v` (`sudo_password`).
- Final file modes: `.env*` → `0600`, `*.sh` → `0755`, everything else → `0644`.
- Feature tests bind `App\Ssh\Fake\FakeRemoteShellFactory`. Real SSH only in `tests/Integration` (group `ssh-integration`).
- No `env()` outside `config/` (integration tests excepted, as today).
- Branch `feat/sudo-password`; Conventional Commits; every commit ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Gates: `vendor/bin/pint --test` and the filtered tests named in each task before every commit. Task 1 drops `hosts.sudo`, so tests that still use it fail until the task that owns them (Tasks 4–7) updates them. From Task 7 on, the full `php artisan test`, `npm run check`, `npx vue-tsc --noEmit` and `npm run build` must pass.

## Review Focus

1. **Password with shell metacharacters** (`p'a$s w"d\`): it must reach sudo byte-for-byte on stdin and never appear in any recorded command. Test in Task 4.
2. **One `Host` behind several deployment hosts, wrong password**: one validation exec per run. The other deployment hosts fail with the same message and send nothing. Test in Task 4.
3. **`sudo_password` host whose sudo credential is missing or has an empty secret**: a clear error before any remote command (deploy, restart, check). Tests in Tasks 2 and 6.
4. **A sudo credential changed to another type, or deleted**: both rejected. Test in Task 7.
5. **Deployment path with a single quote (`/srv/o'x`) in sudo modes**: `mkdir`, install and `cd` stay correctly quoted. Test in Task 3.

---

### Task 1: Privilege mode on hosts (enum, migration, model, factory)

**Files:**
- Create: `app/Enums/PrivilegeMode.php`
- Create: `database/migrations/2026_11_02_000000_replace_sudo_with_privilege_mode_on_hosts_table.php`
- Modify: `app/Models/Host.php`, `app/Models/Credential.php`, `database/factories/HostFactory.php`
- Test: `tests/Feature/Admin/HostPrivilegeMigrationTest.php`

**Interfaces:**
- Produces:
  - `enum PrivilegeMode: string { None = 'none'; Sudo = 'sudo'; SudoPassword = 'sudo_password'; public function label(): string }`. Labels: `None (root or a user with Docker access)`, `sudo without password`, `sudo with password`.
  - Columns `hosts.privilege_mode` (string, default `'none'`) and `hosts.sudo_credential_id` (nullable FK to `credentials`, same style as `credential_id`: `constrained('credentials')`). Column `hosts.sudo` is dropped.
  - `Host`: cast `privilege_mode` → `PrivilegeMode`; `sudoCredential(): BelongsTo` (to `Credential`, key `sudo_credential_id`).
  - `Credential::sudoHosts(): HasMany` (`Host`, `sudo_credential_id`).
  - `HostFactory`: default `privilege_mode => PrivilegeMode::None`, no `sudo` key; states `sudo(): static` and `sudoPassword(?Credential $credential = null): static`. The second sets the mode and `sudo_credential_id => $credential?->id ?? Credential::factory()->password()`.

- [ ] **Step 1: Write the failing migration test**

The test requires the migration file, calls `down()`, inserts raw rows, calls `up()` and asserts the mapping:

```php
it('maps the old sudo prefix to a privilege mode', function () {
    $migration = require database_path('migrations/2026_11_02_000000_replace_sudo_with_privilege_mode_on_hosts_table.php');
    $migration->down();
    $credential = Credential::factory()->create();
    foreach (['a' => '', 'b' => 'sudo -n', 'c' => '  ', 'd' => 'sudo -u ops'] as $name => $sudo) {
        DB::table('hosts')->insert(['name' => $name, 'hostname' => "{$name}.test", 'port' => 22, 'username' => 'deploy',
            'credential_id' => $credential->id, 'sudo' => $sudo, 'created_at' => now(), 'updated_at' => now()]);
    }
    Log::spy();

    $migration->up();

    expect(DB::table('hosts')->orderBy('name')->pluck('privilege_mode', 'name')->all())
        ->toBe(['a' => 'none', 'b' => 'sudo', 'c' => 'none', 'd' => 'sudo'])
        ->and(Schema::hasColumn('hosts', 'sudo'))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'd') && str_contains($m, 'sudo -u ops'));
});
```

- [ ] **Step 2: Run it and check it fails**

Run: `php artisan test --filter=HostPrivilegeMigrationTest`
Expected: FAIL (migration file not found).

- [ ] **Step 3: Implement the enum, migration, model relations and factory**

Migration `up()`:
1. Add the two columns.
2. Set `privilege_mode = 'sudo'` where `trim(sudo) <> ''`.
3. Call `Log::warning("Host {name}: sudo prefix '{sudo}' replaced by passwordless sudo (sudo -n -H).")` for each host whose trimmed prefix is neither empty nor `sudo -n`. A migration has no console output API, so the log is where the affected hosts get listed.
4. Drop `sudo`.

`down()`:
1. Re-add `sudo` (string, default `''`).
2. Set `'sudo -n'` where the mode is not `none`.
3. Drop the FK, `sudo_credential_id` and `privilege_mode`.

Remove `'sudo' => ''` from `HostFactory`.

- [ ] **Step 4: Run it and check it passes**

Run: `php artisan test --filter=HostPrivilegeMigrationTest`
Expected: PASS. The full suite still fails where tests use `sudo`; Tasks 3–7 fix those.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/PrivilegeMode.php database/migrations/2026_11_02_000000_replace_sudo_with_privilege_mode_on_hosts_table.php app/Models/Host.php app/Models/Credential.php database/factories/HostFactory.php tests/Feature/Admin/HostPrivilegeMigrationTest.php
git commit -m "feat(hosts): replace the sudo prefix with a privilege mode"
```

---

### Task 2: `Privilege` value object

**Files:**
- Create: `app/Infrastructure/Privilege.php`
- Test: `tests/Feature/Infrastructure/PrivilegeTest.php` (Feature, because two cases create models)

**Interfaces:**
- Consumes: `PrivilegeMode`, `Host::sudoCredential()` (Task 1).
- Produces `final readonly class Privilege` in `App\Infrastructure`:
  - `public function __construct(public PrivilegeMode $mode, private ?string $password = null)`
  - `public static function for(Host $host): self`. For `SudoPassword` it reads `$host->sudoCredential->secret`. It throws `RuntimeException("host {$host->name}: the sudo password credential is missing")` when the credential is null or the secret is not a non-empty string.
  - `public function escalates(): bool`: false only for `None`.
  - `public function wrap(string $cmd): string`: the command forms in Global Constraints.
  - `public function stdin(): ?string`: `"{$password}\n"` for `SudoPassword`, otherwise `null`.
  - `public function validateCommand(): ?string`: `null` for `None`, otherwise the validation command from Global Constraints.
  - `/** @return list<string> */ public function secrets(): array`: `[$password]` for `SudoPassword`, otherwise `[]`.

- [ ] **Step 1: Write the failing tests**

```php
it('builds the command for each mode', function (PrivilegeMode $mode, string $expected) {
    expect((new Privilege($mode, 'pw'))->wrap("echo 'a'"))->toBe($expected);
})->with([
    [PrivilegeMode::None, "echo 'a'"],
    [PrivilegeMode::Sudo, "sudo -n -H sh -c 'echo '\\''a'\\'''"],
    [PrivilegeMode::SudoPassword, "sudo -S -p '' -H sh -c 'echo '\\''a'\\'''"],
]);

it('sends only the password on stdin', function () {
    expect((new Privilege(PrivilegeMode::SudoPassword, "p'a\$s w\"d\\"))->stdin())->toBe("p'a\$s w\"d\\\n")
        ->and((new Privilege(PrivilegeMode::Sudo))->stdin())->toBeNull()
        ->and((new Privilege(PrivilegeMode::None))->stdin())->toBeNull();
});

it('exposes the validation command and the secrets', function () {
    expect((new Privilege(PrivilegeMode::SudoPassword, 'pw'))->validateCommand())->toBe("sudo -S -p '' -v")
        ->and((new Privilege(PrivilegeMode::Sudo))->validateCommand())->toBe('sudo -n -v')
        ->and((new Privilege(PrivilegeMode::None))->validateCommand())->toBeNull()
        ->and((new Privilege(PrivilegeMode::SudoPassword, 'pw'))->secrets())->toBe(['pw'])
        ->and((new Privilege(PrivilegeMode::Sudo))->secrets())->toBe([]);
});

it('reads the password from the sudo credential', function () {
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => 's3cret']))->create();
    expect(Privilege::for($host)->stdin())->toBe("s3cret\n");
});

it('fails clearly when the sudo credential is missing', function () {
    $host = Host::factory()->create(['privilege_mode' => PrivilegeMode::SudoPassword, 'sudo_credential_id' => null]);
    expect(fn () => Privilege::for($host))->toThrow(RuntimeException::class, 'the sudo password credential is missing');
});
```

- [ ] **Step 2: Run them and check they fail**

Run: `php artisan test --filter=PrivilegeTest`
Expected: FAIL (class `App\Infrastructure\Privilege` not found).

- [ ] **Step 3: Implement `Privilege`**

`wrap()` quotes `$cmd` with `ScriptRenderer::quote()`.

- [ ] **Step 4: Run them and check they pass**

Run: `php artisan test --filter=PrivilegeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/Privilege.php tests/
git commit -m "feat(hosts): build escalated commands and sudo stdin per privilege mode"
```

---

### Task 3: Remote building blocks: `RemoteSteps`, `Staging`, the fake shell's stdin

**Files:**
- Modify: `app/Deploy/RemoteSteps.php` (rewrite)
- Create: `app/Deploy/Staging.php`
- Modify: `app/Ssh/Fake/FakeRemoteShell.php`
- Test: `tests/Feature/Deploy/RemoteStepsTest.php` (new; it creates a `DeploymentHost`), `tests/Feature/Deploy/StagingTest.php` (new)

**Interfaces:**
- Consumes: `Privilege` (Task 2).
- Produces:
  - `RemoteSteps::wrap(DeploymentHost $dh, string $cmd): string` = `Privilege::for($dh->host)->wrap('cd '.quote($dh->path).' && '.$cmd)`.
  - `RemoteSteps::stdin(DeploymentHost $dh): ?string` = `Privilege::for($dh->host)->stdin()`.
  - `RemoteSteps::installCommand(string $stagingDir, string $targetDir, array $modes): string`, with `$modes` as `array<string, int>` (file name → mode). It returns `umask 077` followed, for each file in order, by `cp <staging/name> <target/name.dada-tmp> && chmod <octal> <target/name.dada-tmp> && mv -f <target/name.dada-tmp> <target/name>`, all joined with ` && `. Each path is quoted as a whole with `quote()`; `$targetDir` has its trailing `/` trimmed.
  - `RemoteSteps::withSudo()` and `RemoteSteps::sudo()` are removed.
  - `final class Staging` in `App\Deploy`:
    - `public readonly string $dir`;
    - `public static function open(RemoteShell $shell): self`, which execs `mktemp -d` without escalation. It requires the trimmed stdout to match `/\A\/[A-Za-z0-9._\/-]+\z/`, otherwise it throws `RuntimeException('unable to create a temporary directory on the host')`.
    - `public function put(string $name, string $contents, int $mode): string`. The name must match `/\A[A-Za-z0-9._-]+\z/`, otherwise it throws `InvalidArgumentException`. It calls `$shell->put("{$dir}/{$name}", …)` and returns that path.
    - `public function close(): void`, which execs `rm -rf <quoted dir>` and ignores the result.
  - `FakeRemoteShell` records the `$stdin` of each `exec()`, in the same order as `commands()`, readable through `stdins(): list<?string>`.

- [ ] **Step 1: Write the failing tests**

```php
// RemoteStepsTest
it('builds the install command with quoting and modes', function () {
    expect(RemoteSteps::installCommand('/tmp/tmp.Ab1', "/srv/o'x/", ['.env' => 0600, 'restart.sh' => 0755]))->toBe(
        "umask 077 && cp '/tmp/tmp.Ab1/.env' '/srv/o'\\''x/.env.dada-tmp' && chmod 600 '/srv/o'\\''x/.env.dada-tmp' && mv -f '/srv/o'\\''x/.env.dada-tmp' '/srv/o'\\''x/.env'"
        ." && cp '/tmp/tmp.Ab1/restart.sh' '/srv/o'\\''x/restart.sh.dada-tmp' && chmod 755 '/srv/o'\\''x/restart.sh.dada-tmp' && mv -f '/srv/o'\\''x/restart.sh.dada-tmp' '/srv/o'\\''x/restart.sh'"
    );
});

// StagingTest
it('opens, fills and removes a staging directory as the login user', function () {
    $shell = (new FakeRemoteShell)->expect('/\Amktemp -d\z/', 0, "/tmp/tmp.Ab1\n");
    $staging = Staging::open($shell);
    $path = $staging->put('.env.wp', 'A=1', 0600);
    $staging->close();
    expect($path)->toBe('/tmp/tmp.Ab1/.env.wp')
        ->and($shell->files())->toBe(['/tmp/tmp.Ab1/.env.wp' => ['contents' => 'A=1', 'mode' => 0600]])
        ->and($shell->commands())->toBe(['mktemp -d', "rm -rf '/tmp/tmp.Ab1'"])
        ->and($shell->stdins())->toBe([null, null]);
});

it('refuses an unexpected mktemp output and unsafe names', function () {
    expect(fn () => Staging::open((new FakeRemoteShell)->expect('/mktemp/', 0, "/tmp/x; rm -rf /\n")))
        ->toThrow(RuntimeException::class, 'unable to create a temporary directory');
    $staging = Staging::open((new FakeRemoteShell)->expect('/mktemp/', 0, "/tmp/t\n"));
    expect(fn () => $staging->put('../x', '', 0600))->toThrow(InvalidArgumentException::class);
});
```

Also move the existing `wraps without sudo as a plain cd` assertion from `DeployHostPipelineTest` into `RemoteStepsTest`. Add the quoting case from Review Focus 5 for `sudo` mode: `RemoteSteps::wrap($dh, 'ls')` with path `/srv/o'x` equals `"sudo -n -H sh -c 'cd '\\''/srv/o'\\''\\'\\'''\\''x'\\'' && ls'"`.

- [ ] **Step 2: Run them and check they fail**

Run: `php artisan test --filter="RemoteStepsTest|StagingTest"`
Expected: FAIL (`installCommand`, `Staging`, `stdins` undefined).

- [ ] **Step 3: Implement `RemoteSteps`, `Staging` and `FakeRemoteShell::stdins()`**

Also update the `HostCheckJob` call to the removed `RemoteSteps::withSudo()`: replace it with `Privilege::for($host)->wrap(self::DOCKER_COMMAND)` for now. Task 6 completes the check.

- [ ] **Step 4: Run them and check they pass**

Run: `php artisan test --filter="RemoteStepsTest|StagingTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Deploy/RemoteSteps.php app/Deploy/Staging.php app/Ssh/Fake/FakeRemoteShell.php app/Jobs/HostCheckJob.php tests/
git commit -m "feat(deploy): add staging directory and privileged install command"
```

---

### Task 4: Deploy pipeline in sudo modes, with per-run validation

**Files:**
- Create: `app/Deploy/PrivilegeCheck.php`
- Modify: `app/Deploy/DeployHostPipeline.php` (`run`, `prepareFolder`, `backup`, `upload`, `remove`, `login`, `exec` callers)
- Modify: `app/Jobs/Concerns/RunsWithDeploymentLock.php` (`runLocked`, `runHost`, `masker`)
- Modify: `app/Jobs/DeployJob.php`
- Test: `tests/Feature/Deploy/DeployHostPipelineTest.php`, `tests/Feature/Deploy/DeployJobTest.php`

**Interfaces:**
- Consumes: `Privilege` (Task 2); `RemoteSteps::wrap/stdin/installCommand`, `Staging`, `FakeRemoteShell::stdins()` (Task 3).
- Produces:
  - `final class PrivilegeCheck` in `App\Deploy`, one instance per run: `public function ensure(RemoteShell $shell, Host $host): void`.
    - No-op for `None`.
    - Otherwise, the first call for a given `$host->id` execs `validateCommand()` with `stdin()`. On success the host is remembered as valid. On failure it remembers and throws `RuntimeException("sudo authentication failed on {$host->name}: ".Str::limit(trim($result->output), 200))`.
    - Later calls for a host that failed throw the same message without exec. Later calls for a valid host do nothing.
  - `DeployHostPipeline::run(DeployRun $run, DeploymentHost $dh, DeployLogger $log, array $options, PrivilegeCheck $privileges): void`.
  - `runLocked()` creates one `PrivilegeCheck` per run and calls `$perHost($dh, $log, $privileges)`. The closure type becomes `Closure(DeploymentHost, DeployLogger, PrivilegeCheck): void`.
  - `masker()` adds `Privilege::for($dh->host)->secrets()` for each host, inside the existing `try`.

**Pipeline behaviour in sudo modes** (`none` mode is byte-identical to today):

1. **Order after connecting:**
   1. `$log->step($dh, 'Checking sudo')`, then `$privileges->ensure($shell, $dh->host)`;
   2. `checkDiskSpace` (unescalated);
   3. `prepareFolder`;
   4. `backup`;
   5. `upload`;
   6. `remove`;
   7. `login`;
   8. pull;
   9. restart.
2. **`prepareFolder`:** one exec of `Privilege::for($dh->host)->wrap('mkdir -p '.quote("{$base}/.dada/backups"))` with the privilege stdin. No `chown`.
3. **Commands built with `RemoteSteps::wrap()`:** backup, removals, pull, restart. All are executed with `RemoteSteps::stdin($dh)`.
4. **`upload`:**
   1. `Staging::open($shell)`;
   2. `put` each generated file under its own name, with its final mode;
   3. one exec of `Privilege::wrap(RemoteSteps::installCommand($staging->dir, $dh->path, $modes))` with the privilege stdin;
   4. `$staging->close()` in `finally`.
   
   The log lines `name (mode)` are unchanged.
5. **`login`:**
   1. if there are registries, `Staging::open($shell)`;
   2. for each registry, `$staging->put('registry-'.$registry->id, $secret, 0600)`;
   3. exec `RemoteSteps::wrap($dh, $command.' < '.quote($file))`, where `$command` is the existing `docker login … --password-stdin`, with the privilege stdin;
   4. `$staging->close()` in `finally`.
   
   In `none` mode the secret stays on stdin as today.

- [ ] **Step 1: Write the failing tests**

Update `runPipeline()` to pass `new PrivilegeCheck`. Replace `wraps commands with sudo and chowns the folder` with the tests below. `sudoPasswordHost()` is a local helper: `hostWithWordpress(['path' => $path])` plus a host update to `SudoPassword` with a password credential whose secret is `$pw`; the fake shell expects `/df -Pm/` → `"50000\n"` and `/mktemp -d/` → `"/tmp/tmp.Ab1\n"`.

```php
it('runs every step through sudo with the password only on stdin', function () {
    $pw = "p'a\$s w\"d\\";
    [$dh, $shell, $run] = sudoPasswordHost('/srv/x', $pw);
    runPipeline($run, $dh);

    $commands = $shell->commands();
    $stdins = $shell->stdins();
    expect($commands[0])->toBe("sudo -S -p '' -v")
        ->and($commands)->toContain("sudo -S -p '' -H sh -c 'mkdir -p '\\''/srv/x/.dada/backups'\\'''")
        ->and(implode("\n", $commands))->not->toContain($pw)->not->toContain('chown');
    foreach ($commands as $i => $command) {
        expect($stdins[$i])->toBe(str_starts_with($command, 'sudo ') ? $pw."\n" : null, $command);
    }
});

it('stages files as the login user and installs them with one sudo call', function () {
    [$dh, $shell, $run] = sudoPasswordHost('/srv/x', 'pw');
    runPipeline($run, $dh, ['pull' => false, 'restart' => false]);

    $install = collect($shell->commands())->filter(fn ($c) => str_contains($c, 'mv -f'))->values();
    expect(array_keys($shell->files()))->each->toStartWith('/tmp/tmp.Ab1/')
        ->and($shell->files()['/tmp/tmp.Ab1/.env.wp']['mode'])->toBe(0600)
        ->and($install)->toHaveCount(1)
        ->and($install[0])->toStartWith("sudo -S -p '' -H sh -c ")
        ->and($shell->commands())->toContain("rm -rf '/tmp/tmp.Ab1'");
});

it('logs in to registries from a staged file in sudo mode', function () {
    [$dh, $shell, $run] = sudoPasswordHost('/srv/x', 'pw');
    $registry = Credential::factory()->registry()->create(['secret' => 'reg-secret']);
    $dh->registries()->attach($registry);
    runPipeline($run, $dh->refresh());

    $login = collect($shell->commands())->first(fn ($c) => str_contains($c, 'docker login'));
    expect($login)->toContain('--password-stdin < ')->toContain("registry-{$registry->id}")
        ->and($shell->files()["/tmp/tmp.Ab1/registry-{$registry->id}"]['contents'])->toBe('reg-secret')
        ->and($shell->stdins())->not->toContain('reg-secret');
});

it('stops before any other command when sudo validation fails', function () {
    [$dh, $shell, $run] = sudoPasswordHost('/srv/x', 'wrong');
    $shell->expect('/\Asudo -S -p \'\' -v\z/', 1, '', "Sorry, try again.\nsudo: 3 incorrect password attempts\n");
    expect(fn () => runPipeline($run, $dh))->toThrow(RuntimeException::class, 'sudo authentication failed on');
    expect($shell->commands())->toBe(["sudo -S -p '' -v"])->and($shell->files())->toBe([]);
});
```

In `DeployJobTest`, add the Review Focus 2 test:
- two deployment hosts of one deployment on the **same** `Host` in `SudoPassword` mode, with validation failing;
- dispatch `DeployJob`;
- expect the shell to have recorded exactly one `sudo -S -p '' -v`;
- expect both `deploy_run_hosts` rows to be `failed` with an error containing `sudo authentication failed`;
- expect no `deploy_run_logs` message to contain the password.

Also add `passwordless sudo uses sudo -n -H and no stdin`: same flow as the first test in `Sudo` mode; every escalated command starts with `sudo -n -H sh -c ` and every stdin is `null`.

- [ ] **Step 2: Run them and check they fail**

Run: `php artisan test --filter="DeployHostPipelineTest|DeployJobTest"`
Expected: FAIL (`PrivilegeCheck` not found; old command forms).

- [ ] **Step 3: Implement `PrivilegeCheck` and the pipeline, trait and job changes described above**

- [ ] **Step 4: Run them and check they pass**

Run: `php artisan test --filter="DeployHostPipelineTest|DeployJobTest|DeployLoggerTest|StuckRunsTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Deploy app/Jobs tests/Feature/Deploy
git commit -m "feat(deploy): run the pipeline through sudo with the password on stdin"
```

---

### Task 5: Restart runs in sudo modes

**Files:**
- Modify: `app/Jobs/RestartJob.php`
- Test: `tests/Feature/Deploy/RestartTest.php`

**Interfaces:**
- Consumes: `PrivilegeCheck` and the closure signature from Task 4; `RemoteSteps::stdin()` (Task 3).
- Produces: `RestartJob::handle()` calls `$privileges->ensure($shell, $dh->host)` right after connecting. It then execs the command from `RestartJob::command()` with `stdin: RemoteSteps::stdin($dh)`. `command()` is unchanged.

- [ ] **Step 1: Write the failing tests**

Replace `$dh->host->update(['sudo' => ''])` with `['privilege_mode' => PrivilegeMode::None]`. Replace `wraps with sudo when configured` with:

```php
it('restarts through sudo with the password on stdin', function () {
    $fakes = restartShells();
    $dh = hostWithWordpress();
    $dh->host->update(['privilege_mode' => PrivilegeMode::SudoPassword,
        'sudo_credential_id' => Credential::factory()->password()->create(['secret' => 'pw'])->id]);

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])->assertRedirect();
    runQueuedRestart();

    $shell = $fakes->shellFor($dh->host);
    expect($shell->commands())->toHaveCount(2)
        ->and($shell->commands()[0])->toBe("sudo -S -p '' -v")
        ->and($shell->commands()[1])->toStartWith("sudo -S -p '' -H sh -c ")->toContain('./restart.sh restart')
        ->and($shell->stdins())->toBe(["pw\n", "pw\n"]);
});
```

- [ ] **Step 2: Run them and check they fail**

Run: `php artisan test --filter=RestartTest`
Expected: FAIL.

- [ ] **Step 3: Implement it in `RestartJob::handle()`**

- [ ] **Step 4: Run them and check they pass**

Run: `php artisan test --filter=RestartTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/RestartJob.php tests/Feature/Deploy/RestartTest.php
git commit -m "feat(deploy): run restarts through sudo with the password on stdin"
```

---

### Task 6: Host check validates sudo

**Files:**
- Modify: `app/Jobs/HostCheckJob.php`
- Test: `tests/Feature/HostCheckJobTest.php`

**Interfaces:**
- Consumes: `Privilege` (Task 2), `SecretMasker` (existing: `new SecretMasker(array $secrets)`, `mask(string): string`).
- Produces: `docker()` becomes privilege-aware. In `none` mode the facts are exactly as today (no `sudo` key). In sudo modes:
  1. It execs `validateCommand()` with `stdin()` and the existing `COMMAND_TIMEOUT`.
  2. **On failure** it returns `['sudo_error' => mask(Str::limit(trim(output) or "exit N", 500)), 'docker' => null, 'docker_error' => 'skipped: sudo failed']`. The Docker command is not run.
  3. **On success** it returns `['sudo' => 'ok', …]` followed by the Docker result. The Docker command runs through `Privilege::wrap()` with `stdin()`.
  
  A `Privilege::for()` exception (missing credential) propagates to the existing catch, so the host is recorded as unreachable with that message.

- [ ] **Step 1: Write the failing tests**

Replace `runs docker through the host sudo prefix` with three tests:

```php
it('validates sudo before docker and sends the password on stdin', function () {
    // host SudoPassword, secret 'pw'; expectations for uname, sudo -v (0), docker version (0, "27.3.1\n"), nproc, MemTotal, df
    HostCheckJob::dispatchSync($host->id);
    expect($host->fresh()->facts)->toMatchArray(['sudo' => 'ok', 'docker' => '27.3.1'])
        ->and($shell->commands())->toContain("sudo -S -p '' -v")
        ->toContain("sudo -S -p '' -H sh -c 'docker version --format '\\''{{.Server.Version}}'\\'''")
        ->and(array_values(array_filter($shell->stdins())))->toBe(["pw\n", "pw\n"]);
});

it('records a sudo failure, skips docker and keeps the host reachable', function () {
    // sudo -v → exit 1, stderr "sudo: a password is required\n"
    HostCheckJob::dispatchSync($host->id);
    expect($host->fresh())->reachable->toBeTrue()
        ->and($host->fresh()->facts)->toMatchArray(['sudo_error' => 'sudo: a password is required', 'docker' => null, 'docker_error' => 'skipped: sudo failed'])
        ->and(implode("\n", $shell->commands()))->not->toContain('docker version');
});

it('marks the host unreachable when the sudo credential is missing', function () {
    $host = Host::factory()->create(['privilege_mode' => PrivilegeMode::SudoPassword, 'sudo_credential_id' => null]);
    // default fake expectations for uname
    HostCheckJob::dispatchSync($host->id);
    expect($host->fresh())->reachable->toBeFalse()
        ->and($host->fresh()->facts['error'])->toContain('the sudo password credential is missing');
});
```

- [ ] **Step 2: Run them and check they fail**

Run: `php artisan test --filter=HostCheckJobTest`
Expected: FAIL.

- [ ] **Step 3: Implement it in `HostCheckJob::docker()`**

- [ ] **Step 4: Run them and check they pass**

Run: `php artisan test --filter=HostCheckJobTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/HostCheckJob.php tests/Feature/HostCheckJobTest.php
git commit -m "feat(hosts): validate sudo in the host check"
```

---

### Task 7: Admin UI and validation for the privilege mode

**Files:**
- Modify: `app/Http/Controllers/Admin/HostController.php` (`show`, `edit`, `validated`, `options`)
- Modify: `app/Http/Controllers/Admin/CredentialController.php` (`destroy`, `validated` or `update`)
- Modify: `resources/js/pages/admin/hosts/Form.vue`, `resources/js/pages/admin/hosts/Show.vue`
- Modify: `README.md` (the "Hosts" bullet: hosts can use passwordless sudo or sudo with a password)
- Test: `tests/Feature/Admin/HostTest.php`, `tests/Feature/Admin/CredentialTest.php`

**Interfaces:**
- Consumes: `PrivilegeMode` (Task 1).
- Produces:
  - **Host request fields:**
    - `privilege_mode`: required, `Rule::enum(PrivilegeMode::class)`.
    - `sudo_credential_id`: nullable, `required_if:privilege_mode,sudo_password`, integer, exists, and of type `password`. Otherwise the error is `The sudo credential must be a password credential.`
    - It is stored as `null` unless the mode is `sudo_password`.
  - **Inertia props:**
    - `edit`: `host.privilege_mode` and `host.sudo_credential_id`, replacing `host.sudo`.
    - `show`: `host.privilege_mode_label` and `host.sudo_credential` (`{id, name}` or `null`), replacing `host.sudo`.
    - `options()`: adds `privilegeModes: list<{value: string, label: string}>` (from `PrivilegeMode::cases()` and `label()`) and `sudoCredentials: list<{id, name}>` (password credentials, ordered by name).
  - **Credential errors:**
    - deleting a credential used as a sudo credential fails on key `credential` with `The credential is used as a sudo password by one or more hosts.`;
    - changing the type of such a credential away from `password` fails on key `type` with `The credential is used as a sudo password and must stay a password credential.`
  - **`Form.vue`:**
    - a `privilege_mode` select fed by `privilegeModes`, replacing the "Sudo prefix" input;
    - a `sudo_credential_id` select fed by `sudoCredentials`, shown only while the selected mode is `sudo_password` (use a `ref` initialised from `host?.privilege_mode ?? 'none'`);
    - field label `Privileges`, credential label `Sudo password credential`.
  - **`Show.vue`:**
    - a `Privileges` row showing `privilege_mode_label`;
    - a `Sudo password` row showing the credential name, only for `sudo_password`.

- [ ] **Step 1: Write the failing tests**

In `HostTest`: change `hostPayload()` and the jump-cycle payload from `'sudo' => ''` to `'privilege_mode' => 'none'`. Add:

```php
it('stores sudo with a password credential', function () {
    $pw = Credential::factory()->password()->create();
    $this->actingAs(hostAdmin())->post('/admin/hosts', hostPayload(['privilege_mode' => 'sudo_password', 'sudo_credential_id' => $pw->id]))->assertRedirect('/admin/hosts');
    expect(Host::first())->privilege_mode->toBe(PrivilegeMode::SudoPassword)->sudo_credential_id->toBe($pw->id);
});

it('requires a password credential for sudo with password', function (array $overrides) {
    $this->actingAs(hostAdmin())->post('/admin/hosts', hostPayload(['privilege_mode' => 'sudo_password', ...$overrides]))
        ->assertSessionHasErrors('sudo_credential_id');
})->with([
    'missing' => [[]],
    'ssh key' => [fn () => ['sudo_credential_id' => Credential::factory()->sshKey()->create()->id]],
]);

it('drops the sudo credential when the mode does not need it', function () {
    $pw = Credential::factory()->password()->create();
    $this->actingAs(hostAdmin())->post('/admin/hosts', hostPayload(['privilege_mode' => 'sudo', 'sudo_credential_id' => $pw->id]));
    expect(Host::first()->sudo_credential_id)->toBeNull();
});

it('never sends the sudo password to the host pages', function () {
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => 'SUDO-SECRET']))->create();
    $this->actingAs(hostAdmin())->get("/admin/hosts/{$host->id}")->assertOk()->assertDontSee('SUDO-SECRET');
    $this->actingAs(hostAdmin())->get("/admin/hosts/{$host->id}/edit")->assertOk()->assertDontSee('SUDO-SECRET');
});
```

In `CredentialTest`, add the two rejection tests (delete; change type to `ssh-key`) for a credential referenced by `hosts.sudo_credential_id`, asserting the messages above.

- [ ] **Step 2: Run them and check they fail**

Run: `php artisan test --filter="HostTest|CredentialTest"`
Expected: FAIL.

- [ ] **Step 3: Implement the controllers, the Vue pages and the README bullet**

- [ ] **Step 4: Run every gate**

Run: `php artisan test && vendor/bin/pint --test && npm run check && npx vue-tsc --noEmit && npm run build`
Expected: all pass, with no reference to `host.sudo` left (`grep -rn "\.sudo\b\|'sudo' =>" app resources tests` finds nothing outside the migration).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin resources/js/pages/admin/hosts README.md tests/Feature/Admin
git commit -m "feat(hosts): choose the privilege mode and sudo credential in the admin UI"
```

---

### Task 8: SSH integration test against a real sudo-with-password target

**Files:**
- Create: `tests/Integration/PrivilegedDeployTest.php`
- Modify: `.github/workflows/ci.yml` (job `ssh-integration`)

**Interfaces:**
- Consumes:
  - `DeployHostPipeline::run(..., PrivilegeCheck)`, `PrivilegeCheck::ensure()` (Task 4);
  - `RemoteSteps::wrap/stdin` (Task 3);
  - `hostWithWordpress()` (`tests/Pest.php`).
- Produces: nothing used by other tasks.

- [ ] **Step 1: Configure CI**

- In the `sshd` service env, add `SUDO_ACCESS: 'true'`. With `USER_PASSWORD` already set, the linuxserver image then gives the user sudo that asks for that password.
- In the `SSH integration tests` step env, add `DADA_SSH_TEST_SUDO: 'true'`.

- [ ] **Step 2: Write the integration test**

The test is skipped unless `env('DADA_SSH_TEST_SUDO')` is set. It builds `hostWithWordpress(['path' => '/srv/dada-it-'.Str::lower(Str::random(6))])` and points `$dh->host` at the CI target:
- `hostname`, `port`, `username` from `DADA_SSH_TEST_*`;
- login credential: a password credential with `DADA_SSH_TEST_PASSWORD`;
- `privilege_mode => SudoPassword`;
- `sudo_credential_id`: a password credential with the same password;
- `host_key => null`.

Then:

```php
app(DeployHostPipeline::class)->run($run, $dh, new DeployLogger($run, new SecretMasker([])), ['pull' => false, 'restart' => false, 'confirm_removals' => false], new PrivilegeCheck);

$shell = app(RemoteShellFactory::class)->for($dh->host->fresh());
try {
    $stat = fn (string $f) => trim($shell->exec("stat -c '%U %a' ".ScriptRenderer::quote("{$dh->path}/{$f}"))->stdout);
    expect($stat('.env.wp'))->toBe('root 600')
        ->and($stat('restart.sh'))->toBe('root 755')
        ->and($stat('docker-compose.yml'))->toBe('root 644')
        ->and(trim($shell->exec(RemoteSteps::wrap($dh, 'pwd'), stdin: RemoteSteps::stdin($dh))->stdout))->toBe($dh->path);
} finally {
    $shell->exec(Privilege::for($dh->host)->wrap('rm -rf '.ScriptRenderer::quote($dh->path)), stdin: RemoteSteps::stdin($dh));
    $shell->close();
}
```

A second test points the sudo credential at a credential with secret `wrong`. It expects `(new PrivilegeCheck)->ensure($shell, $host)` to throw `RuntimeException` containing `sudo authentication failed`.

Both tests use `->group('ssh-integration')`.

- [ ] **Step 3: Run it locally and check it is skipped**

Run: `php artisan test --group=ssh-integration`
Expected: the new tests are SKIPPED without `DADA_SSH_TEST_SUDO`; the existing ones behave as before.

- [ ] **Step 4: Commit and verify in CI**

```bash
git add tests/Integration/PrivilegedDeployTest.php .github/workflows/ci.yml
git commit -m "test(ssh): deploy through sudo with a password against the CI target"
```

Push the branch and open the PR against `develop` only when the maintainer asks. The `ssh-integration` job must pass with the two new tests executed, not skipped.
