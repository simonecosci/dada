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
        ->and($shell->closed())->toBeTrue();
});

it('marks host unreachable on connection failure', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $fakes->shellFor($host)->failConnect(new HostKeyChanged('Chiave host cambiata'));
    HostCheckJob::dispatchSync($host->id);
    expect($host->fresh())->reachable->toBeFalse()->and($host->fresh()->facts['error'])->toContain('Chiave host');
});

it('marks host unreachable when a command fails', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $host = Host::factory()->create();
    $fakes->shellFor($host)->expect('/docker version/', 127, "docker: command not found\n");

    HostCheckJob::dispatchSync($host->id);

    expect($host->fresh())->reachable->toBeFalse()->checked_at->not->toBeNull()
        ->and($host->fresh()->facts['error'])->toContain('docker: command not found');
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
