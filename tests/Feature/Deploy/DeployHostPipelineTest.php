<?php

use App\Deploy\DeployHostPipeline;
use App\Deploy\DeployLogger;
use App\Deploy\PrivilegeCheck;
use App\Deploy\SecretMasker;
use App\Enums\PrivilegeMode;
use App\Models\Credential;
use App\Models\DeploymentHost;
use App\Models\DeploymentSnapshot;
use App\Models\DeployRun;
use App\Ssh\Fake\FakeRemoteShell;
use App\Ssh\Fake\FakeRemoteShellFactory;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShellFactory;
use App\Variables\VariableSync;

/** @param array<string, bool> $options */
function runPipeline(DeployRun $run, DeploymentHost $dh, array $options = []): void
{
    app(DeployHostPipeline::class)->run(
        $run,
        $dh,
        new DeployLogger($run, new SecretMasker([])),
        $options + ['pull' => true, 'restart' => true, 'confirm_removals' => false],
        new PrivilegeCheck,
    );
}

function fakeShells(): FakeRemoteShellFactory
{
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);

    return $fakes;
}

/**
 * Wordpress deployment host whose SSH host escalates with $mode; in SudoPassword mode
 * a password credential holds $pw.
 *
 * @return array{DeploymentHost, FakeRemoteShell, DeployRun}
 */
function privilegedHost(string $path, PrivilegeMode $mode, ?string $pw = null): array
{
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => $path]);
    $dh->host->update([
        'privilege_mode' => $mode,
        'sudo_credential_id' => $pw !== null ? Credential::factory()->password()->create(['secret' => $pw])->id : null,
    ]);
    $dh->refresh();
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n")->expect('/mktemp -d/', 0, "/tmp/tmp.Ab1\n");

    return [$dh, $shell, $run];
}

/** @return array{DeploymentHost, FakeRemoteShell, DeployRun} */
function sudoPasswordHost(string $path, string $pw): array
{
    return privilegedHost($path, PrivilegeMode::SudoPassword, $pw);
}

it('runs the full pipeline on a compose host', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n");
    app(DeployHostPipeline::class)->run($run, $dh, new DeployLogger($run, new SecretMasker([])), ['pull' => true, 'restart' => true, 'confirm_removals' => false], new PrivilegeCheck);
    expect($shell->files())->toHaveKeys(['/srv/x/docker-compose.yml', '/srv/x/.env', '/srv/x/.env.wp', '/srv/x/wp.sh', '/srv/x/restart.sh'])
        ->and($shell->files()['/srv/x/.env.wp']['mode'])->toBe(0600)
        ->and($shell->files()['/srv/x/restart.sh']['mode'])->toBe(0755)
        ->and(implode("\n", $shell->commands()))->toContain('.dada/backups')->toContain('docker compose pull')->toContain('./restart.sh restart');
    expect($dh->latestSnapshot)->not->toBeNull();
});

it('uploads files with the expected modes and closes the shell', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n");

    runPipeline($run, $dh, ['pull' => false, 'restart' => false]);

    expect($shell->files()['/srv/x/docker-compose.yml']['mode'])->toBe(0644)
        ->and($shell->files()['/srv/x/.env']['mode'])->toBe(0600)
        ->and($shell->files()['/srv/x/wp.sh']['mode'])->toBe(0755)
        ->and($shell->files()['/srv/x/.env.wp']['contents'])->toContain('pw-123')
        ->and($shell->closed())->toBeTrue()
        ->and(implode("\n", $shell->commands()))->not->toContain('docker compose pull')->not->toContain('./restart.sh');
});

it('stores the snapshot with installations, services and real files', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n");

    runPipeline($run, $dh);

    $snapshot = DeploymentSnapshot::sole();
    $installation = $snapshot->installations[0];
    $variables = collect($installation['variables'])->keyBy('key');

    expect($snapshot->deploy_run_id)->toBe($run->id)
        ->and($snapshot->deployment_host_id)->toBe($dh->id)
        ->and($installation['slug'])->toBe('wp')
        ->and($installation['services'])->toBe(['wp'])
        ->and($installation)->toHaveKeys(['project_version_id', 'order', 'compose', 'commands'])
        ->and($variables['DB_PASSWORD'])->toMatchArray(['default' => 'pw-123', 'secret' => true, 'scope' => 'container'])
        ->and($variables['DB_PASSWORD'])->toHaveKeys(['key', 'default', 'value', 'use_default', 'required', 'secret', 'scope', 'description'])
        ->and($snapshot->files['.env.wp'])->toContain('pw-123')
        ->and($snapshot->host_variables)->toBe([]);

    // Encrypted at rest.
    $raw = DB::table('deployment_snapshots')->first();
    expect($raw->files)->not->toContain('pw-123')
        ->and($raw->installations)->not->toContain('pw-123');
});

it('quotes the path and backs up generated and previous files with retention', function () {
    config(['dada.deploy.backup_retention' => 3]);
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    DeploymentSnapshot::create([
        'deployment_host_id' => $dh->id, 'deploy_run_id' => null,
        'installations' => [['slug' => 'wp', 'services' => ['wp']]],
        'host_variables' => [], 'files' => ['legacy.txt' => 'x'],
    ]);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n");

    runPipeline($run, $dh);

    $commands = $shell->commands();
    $backup = collect($commands)->first(fn ($c) => str_contains($c, 'cp -p'));

    expect($commands[0])->toBe("p='/srv/x'; while [ ! -d \"\$p\" ]; do p=\$(dirname \"\$p\"); done; df -Pm \"\$p\" | awk 'NR==2{print \$4}'")
        ->and($commands[1])->toBe("mkdir -p '/srv/x/.dada/backups'")
        ->and($backup)->toStartWith("cd '/srv/x' && ")
        ->toContain('ts=$(date +%Y%m%d_%H%M%S)')
        ->toContain("'docker-compose.yml'")->toContain("'.env.wp'")->toContain("'legacy.txt'")
        ->toContain('.dada/backups/$ts/')
        ->toContain('cp -p "$f" .dada/backups/$ts/ || exit 1')
        ->toContain('ls -1dt .dada/backups/*/ | tail -n +4 | xargs -r rm -rf');
});

it('fails when the backup fails and uploads nothing', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n")->expect('/cp -p/', 1, "cp: cannot stat\n");

    expect(fn () => runPipeline($run, $dh))->toThrow(RemoteCommandFailed::class, 'cannot stat');
    expect($shell->files())->toBe([])
        ->and(DeploymentSnapshot::count())->toBe(0);
});

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

it('passwordless sudo uses sudo -n -H and no stdin', function () {
    [$dh, $shell, $run] = privilegedHost('/srv/x', PrivilegeMode::Sudo);
    runPipeline($run, $dh);

    $commands = $shell->commands();
    $escalated = array_values(array_filter($commands, fn ($c) => str_starts_with($c, 'sudo ')));
    expect($commands[0])->toBe('sudo -n -v')
        ->and($commands)->toContain("sudo -n -H sh -c 'mkdir -p '\\''/srv/x/.dada/backups'\\'''")
        ->and(collect($commands)->filter(fn ($c) => str_contains($c, 'mv -f')))->toHaveCount(1)
        ->and(implode("\n", $commands))->not->toContain('chown')->toContain('./restart.sh restart')
        ->and($escalated)->not->toBeEmpty()
        ->and($shell->stdins())->each->toBeNull();
    foreach (array_slice($escalated, 1) as $command) {
        expect($command)->toStartWith('sudo -n -H sh -c ');
    }
});

it('fails before connecting when required variables are missing', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    VariableSync::sync($dh->installations->first(), [
        ['key' => 'DB_PASSWORD', 'scope' => 'container', 'secret' => true, 'default' => 'pw-123'],
        ['key' => 'WP_TAG', 'scope' => 'compose', 'default' => '6'],
        ['key' => 'DB_HOST', 'scope' => 'container', 'required' => true],
    ], allowHostGlobal: false);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host);

    expect(fn () => runPipeline($run, $dh->refresh()))->toThrow(RuntimeException::class, 'wp: DB_HOST');
    expect($shell->commands())->toBe([])
        ->and($shell->files())->toBe([])
        ->and(DeploymentSnapshot::count())->toBe(0);
});

it('fails on low disk space', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "100\n");

    expect(fn () => runPipeline($run, $dh))->toThrow(RuntimeException::class, 'insufficient space: 100 MB free, minimum 1024');
    expect($shell->files())->toBe([])
        ->and($shell->commands())->toHaveCount(1)
        ->and($shell->closed())->toBeTrue();
});

it('turns a failing remote command into RemoteCommandFailed', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n")->expect('/restart\.sh/', 1, "boom\n");

    expect(fn () => runPipeline($run, $dh))->toThrow(RemoteCommandFailed::class, 'boom');
    expect(DeploymentSnapshot::count())->toBe(0);
});

it('logs in to registries via stdin and masks secrets in remote output', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $registry = Credential::factory()->registry()->create(['secret' => 'reg-token-9', 'username' => 'bot', 'registry_url' => 'ghcr.io']);
    $dh->registries()->attach($registry);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)
        ->expect('/df -Pm/', 0, "50000\n")
        ->expect('/docker login/', 0, "error using reg-token-9\n")
        ->expect('/restart\.sh restart/', 0, "DB_PASSWORD=pw-123\n");

    runPipeline($run, $dh->refresh());

    $commands = implode("\n", $shell->commands());
    $logs = $run->logs()->pluck('message')->implode("\n");

    expect($commands)->not->toContain('reg-token-9')
        ->toContain("docker login 'ghcr.io' -u 'bot' --password-stdin")
        ->and($logs)->toContain('******')
        ->not->toContain('reg-token-9')
        ->not->toContain('pw-123');
});

it('requires confirmation for removals and removes compose containers by label', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    DeploymentSnapshot::create([
        'deployment_host_id' => $dh->id, 'deploy_run_id' => null,
        'installations' => [['slug' => 'wp', 'services' => ['wp']], ['slug' => 'old', 'services' => ['old-web']]],
        'host_variables' => [], 'files' => ['.env.old' => 'A=1', 'old.sh' => '#!/bin/sh'],
    ]);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n");

    expect(fn () => runPipeline($run, $dh))->toThrow(RuntimeException::class, 'old');
    expect($shell->commands())->toBe([]);

    runPipeline($run, $dh, ['confirm_removals' => true]);

    $commands = implode("\n", $shell->commands());
    expect($commands)->toContain("rm -f '.env.old' 'old.sh'")
        ->toContain('label=com.docker.compose.service=old-web')
        ->toContain('label=com.docker.compose.project=client-x')
        ->and(collect($dh->latestSnapshot()->first()->installations)->pluck('slug')->all())->toBe(['wp']);
});

it('does not remove containers by label in swarm', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x', 'orchestrator' => 'swarm']);
    DeploymentSnapshot::create([
        'deployment_host_id' => $dh->id, 'deploy_run_id' => null,
        'installations' => [['slug' => 'old', 'services' => ['old-web']]],
        'host_variables' => [], 'files' => [],
    ]);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $shell = $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n");

    runPipeline($run, $dh, ['confirm_removals' => true]);

    $commands = implode("\n", $shell->commands());
    expect($commands)->toContain("rm -f '.env.old' 'old.sh'")
        ->not->toContain('docker ps -aq')
        ->not->toContain('docker compose pull');
});

it('parses only stdout of the disk check, ignoring ssh warnings and banners on stderr', function () {
    $fakes = fakeShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $run = DeployRun::factory()->for($dh->deployment)->create();
    $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n", "Warning: Permanently added 'dada-h1' (ED25519) to the list of known hosts.\nWelcome to the bastion\n");

    runPipeline($run, $dh, ['pull' => false, 'restart' => false]);

    expect(DeploymentSnapshot::count())->toBe(1);
});

it('measures free space on the nearest existing ancestor of a missing nested path', function () {
    $script = "mkdir -p \"srv/o'x\"\n"
        ."df() { printf 'Filesystem\nfs 1 2 %s\n' \"\$2\"; }\n"
        .DeployHostPipeline::diskFreeCommand("srv/o'x/missing/deeper")."\n";

    $r = runBashScript(['check.sh' => $script], 'check.sh');

    expect($r['exit'])->toBe(0, $r['output'])->and(trim($r['output']))->toBe("srv/o'x");
});
