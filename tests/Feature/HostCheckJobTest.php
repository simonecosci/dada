<?php

use App\Jobs\HostCheckJob;
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

it('runs docker through the host sudo prefix', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create(['sudo' => 'sudo -n']);
    $shell = $fakes->shellFor($host)->expect('/docker version/', 0, "27.3.1\n");

    HostCheckJob::dispatchSync($host->id);

    expect($shell->commands())->toContain("sudo -n sh -c 'docker version --format '\''{{.Server.Version}}'\'''")
        ->toContain('uname -sr')
        ->and($host->fresh()->facts['docker'])->toBe('27.3.1');
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
