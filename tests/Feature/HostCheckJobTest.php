<?php

use App\Enums\PrivilegeMode;
use App\Jobs\HostCheckJob;
use App\Models\Credential;
use App\Models\Host;
use App\Ssh\Fake\FakeRemoteShellFactory;
use App\Ssh\HostKeyChanged;
use App\Ssh\RemoteShellFactory;

it('records facts and reachability', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n")->expect('/docker version/', 0, "27.3.1\n")
        ->expect('/nproc/', 0, "4\n")->expect('/MemTotal/', 0, "7936\n")->expect('/df -Pm/', 0, "51200\n");
    HostCheckJob::dispatchSync($host->id);
    expect($host->fresh())->reachable->toBeTrue()->facts->toMatchArray(['docker' => '27.3.1', 'cpus' => 4]);
});

it('stores every fact and the check time', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $shell = $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n")->expect('/docker version/', 0, "27.3.1\n")
        ->expect('/nproc/', 0, "4\n")->expect('/MemTotal/', 0, "7936\n")->expect('/df -Pm/', 0, "51200\n");

    HostCheckJob::dispatchSync($host->id);

    expect($host->fresh())->checked_at->not->toBeNull()
        ->facts->toBe(['os' => 'Linux 6.8', 'docker' => '27.3.1', 'cpus' => 4, 'memory_mb' => 7936, 'disk_free_mb' => 51200])
        ->and($shell->commands())->toContain("docker version --format '{{.Server.Version}}'")
        ->and($shell->closed())->toBeTrue()
        ->and($shell->timeouts())->toBe(array_fill(0, 5, HostCheckJob::COMMAND_TIMEOUT))
        ->and((new HostCheckJob($host->id))->timeout)->toBe(120);
});

it('marks host unreachable on connection failure', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $fakes->shellFor($host)->failConnect(new HostKeyChanged('Host key changed'));
    HostCheckJob::dispatchSync($host->id);
    expect($host->fresh())->reachable->toBeFalse()->and($host->fresh()->facts['error'])->toContain('Host key');
});

it('marks host unreachable when a basic command fails', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $fakes->shellFor($host)->expect('/uname/', 126, "uname: permission denied\n");

    HostCheckJob::dispatchSync($host->id);

    expect($host->fresh())->reachable->toBeFalse()->checked_at->not->toBeNull()
        ->and($host->fresh()->facts['error'])->toContain('uname: permission denied');
});

it('keeps the host reachable and records the docker error when docker fails', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n")
        ->expect('/docker version/', 1, '', "permission denied while trying to connect to the Docker daemon socket\n")
        ->expect('/nproc/', 0, "4\n")->expect('/MemTotal/', 0, "7936\n")->expect('/df -Pm/', 0, "51200\n");

    HostCheckJob::dispatchSync($host->id);

    $facts = $host->fresh()->facts;
    expect($host->fresh()->reachable)->toBeTrue()
        ->and($facts)->toHaveKey('docker')
        ->and($facts['docker'])->toBeNull()
        ->and($facts['docker_error'])->toContain('permission denied while trying to connect')
        ->and($facts['cpus'])->toBe(4);
});

it('validates sudo before docker and sends the password on stdin', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => 'pw']))->create();
    $shell = $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n")->expect('/ -v\z/', 0)
        ->expect('/docker version/', 0, "27.3.1\n")->expect('/nproc/', 0, "4\n")
        ->expect('/MemTotal/', 0, "7936\n")->expect('/df -Pm/', 0, "51200\n");

    HostCheckJob::dispatchSync($host->id);

    expect($host->fresh()->facts)->toMatchArray(['sudo' => 'ok', 'docker' => '27.3.1'])
        ->and($shell->commands())->toContain("sudo -S -p '' -v")
        ->toContain("sudo -S -p '' -H sh -c 'exec </dev/null; docker version --format '\''{{.Server.Version}}'\'''")
        ->and(array_values(array_filter($shell->stdins())))->toBe(["pw\n", "pw\n"]);
});

it('records a sudo failure, skips docker and keeps the host reachable', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => 'pw']))->create();
    $shell = $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n")
        ->expect('/ -v\z/', 1, '', "sudo: a password is required\n")
        ->expect('/nproc/', 0, "4\n")->expect('/MemTotal/', 0, "7936\n")->expect('/df -Pm/', 0, "51200\n");

    HostCheckJob::dispatchSync($host->id);

    expect($host->fresh())->reachable->toBeTrue()
        ->and($host->fresh()->facts)->toMatchArray(['sudo_error' => 'sudo: a password is required', 'docker' => null, 'docker_error' => 'skipped: sudo failed'])
        ->and(implode("\n", $shell->commands()))->not->toContain('docker version');
});

it('masks the sudo password in a recorded sudo error', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => 'hunter22']))->create();
    $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n")->expect('/ -v\z/', 1, "bad hunter22 here\n")
        ->expect('/nproc/', 0, "4\n")->expect('/MemTotal/', 0, "7936\n")->expect('/df -Pm/', 0, "51200\n");

    HostCheckJob::dispatchSync($host->id);

    expect(json_encode($host->fresh()->facts))->not->toContain('hunter22');
});

it('masks the sudo password in a recorded docker error', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => 'hunter22']))->create();
    $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n")->expect('/ -v\z/', 0)
        ->expect('/docker version/', 1, '', "docker: hunter22 rejected\n")
        ->expect('/nproc/', 0, "4\n")->expect('/MemTotal/', 0, "7936\n")->expect('/df -Pm/', 0, "51200\n");

    HostCheckJob::dispatchSync($host->id);

    $facts = $host->fresh()->facts;
    expect($facts['docker'])->toBeNull()
        ->and($facts['docker_error'])->toBe('docker: ****** rejected')
        ->and(json_encode($facts))->not->toContain('hunter22');
});

it('marks the host unreachable when the sudo credential is missing', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create(['privilege_mode' => PrivilegeMode::SudoPassword, 'sudo_credential_id' => null]);
    $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n");

    HostCheckJob::dispatchSync($host->id);

    expect($host->fresh())->reachable->toBeFalse()
        ->and($host->fresh()->facts['error'])->toContain('the sudo password credential is missing');
});

it('records uploaded files on the fake shell without recording a command', function () {
    $fakes = new FakeRemoteShellFactory;
    $host = Host::factory()->create();

    $shell = $fakes->for($host);
    $shell->put('/srv/app/.env', 'A=1', 0600);

    expect($shell)->toBe($fakes->shellFor($host))
        ->and($shell->files())->toBe(['/srv/app/.env' => ['contents' => 'A=1', 'mode' => 0600]])
        ->and($shell->commands())->toBe([]);
});

it('parses only stdout of each fact command', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $noise = "Warning: Permanently added 'dada-h1' (ED25519) to the list of known hosts.\n";
    $fakes->shellFor($host)->expect('/uname/', 0, "Linux 6.8\n", $noise)->expect('/docker version/', 0, "27.3.1\n", $noise)
        ->expect('/nproc/', 0, "4\n", $noise)->expect('/MemTotal/', 0, "7936\n", $noise)->expect('/df -Pm/', 0, "51200\n", $noise);

    HostCheckJob::dispatchSync($host->id);

    expect($host->fresh()->facts)->toBe(['os' => 'Linux 6.8', 'docker' => '27.3.1', 'cpus' => 4, 'memory_mb' => 7936, 'disk_free_mb' => 51200]);
});
