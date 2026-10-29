<?php

use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Jobs\DeployJob;
use App\Models\Credential;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use App\Ssh\Fake\FakeRemoteShellFactory;
use App\Ssh\HostKeyChanged;
use App\Ssh\RemoteShellFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

it('continues other hosts when one fails and marks run failed', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    [$d, $dhA, $dhB] = deploymentWithTwoHosts();
    $fakes->shellFor($dhA->host)->failConnect(new HostKeyChanged('Chiave host cambiata per A'));
    $fakes->shellFor($dhB->host)->expect('/df -Pm/', 0, "50000\n");
    $run = DeployRun::factory()->for($d)->create(['type' => 'deploy', 'options' => ['pull' => false, 'restart' => true, 'confirm_removals' => false]]);
    DeployJob::dispatchSync($run->id);
    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->hosts()->where('deployment_host_id', $dhA->id)->first()->error)->toContain('Chiave host')
        ->and($run->hosts()->where('deployment_host_id', $dhB->id)->first()->status)->toBe(RunStatus::Succeeded);
});

it('succeeds when all hosts succeed, broadcasts status changes and releases the lock', function () {
    Event::fake([DeployRunUpdated::class]);
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    [$d, $dhA, $dhB] = deploymentWithTwoHosts();
    $fakes->shellFor($dhA->host)->expect('/df -Pm/', 0, "50000\n");
    $fakes->shellFor($dhB->host)->expect('/df -Pm/', 0, "50000\n");
    $run = DeployRun::factory()->for($d)->create(['options' => ['pull' => true, 'restart' => true, 'confirm_removals' => false]]);

    DeployJob::dispatchSync($run->id);

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->hosts()->pluck('status')->all())->toBe([RunStatus::Succeeded, RunStatus::Succeeded])
        ->and(Cache::lock("deployment-run:{$d->id}", 10)->get())->toBeTrue();
    Event::assertDispatched(DeployRunUpdated::class, fn ($e) => $e->run->id === $run->id);
    expect(Event::dispatched(DeployRunUpdated::class))->toHaveCount(6); // running, 2x(host running, host done), final
});

it('deploys only the selected host', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    [$d, $dhA, $dhB] = deploymentWithTwoHosts();
    $fakes->shellFor($dhB->host)->expect('/df -Pm/', 0, "50000\n");
    $run = DeployRun::factory()->for($d)->create(['options' => ['deployment_host_id' => $dhB->id, 'pull' => false, 'restart' => false, 'confirm_removals' => false]]);

    DeployJob::dispatchSync($run->id);

    expect($run->fresh()->status)->toBe(RunStatus::Succeeded)
        ->and($run->hosts()->pluck('deployment_host_id')->all())->toBe([$dhB->id])
        ->and($fakes->shellFor($dhA->host)->commands())->toBe([]);
});

it('fails the run when the deployment is locked', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    [$d, $dhA] = deploymentWithTwoHosts();
    $run = DeployRun::factory()->for($d)->create(['options' => []]);
    Cache::lock("deployment-run:{$d->id}", 3600)->get();

    DeployJob::dispatchSync($run->id);

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe('deployment occupato')
        ->and($run->hosts()->count())->toBe(0)
        ->and($fakes->shellFor($dhA->host)->commands())->toBe([]);
});

it('masks secrets in host errors', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    [$d, $dhA, $dhB] = deploymentWithTwoHosts();
    $dhA->registries()->attach(Credential::factory()->registry()->create(['secret' => 'reg-token-9']));
    $fakes->shellFor($dhA->host)->expect('/df -Pm/', 0, "50000\n")->expect('/restart\.sh/', 2, "leak pw-123 reg-token-9\n");
    $fakes->shellFor($dhB->host)->failConnect(new RuntimeException('connessione fallita pw-123'));
    $run = DeployRun::factory()->for($d)->create(['options' => ['pull' => false, 'restart' => true, 'confirm_removals' => false]]);

    DeployJob::dispatchSync($run->id);

    $errors = $run->hosts()->pluck('error')->implode("\n");
    $logs = $run->logs()->pluck('message')->implode("\n");
    expect($errors)->toContain('exit 2')->toContain('connessione fallita')
        ->not->toContain('pw-123')->not->toContain('reg-token-9')
        ->and($logs)->not->toContain('pw-123')->not->toContain('reg-token-9')
        ->and($run->logs()->where('level', 'error')->count())->toBe(2);
});

it('failed() marks run failed and releases lock', function () {
    $run = DeployRun::factory()->create(['status' => 'running']);
    Cache::lock("deployment-run:{$run->deployment_id}", 3600)->get();
    (new DeployJob($run->id))->failed(new RuntimeException('worker timeout'));
    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and(Cache::lock("deployment-run:{$run->deployment_id}", 10)->get())->toBeTrue();
});

it('failed() also fails running hosts', function () {
    $run = DeployRun::factory()->create(['status' => 'running']);
    $dh = hostWithWordpress(['deployment_id' => $run->deployment_id]);
    $host = DeployRunHost::create(['deploy_run_id' => $run->id, 'deployment_host_id' => $dh->id, 'status' => 'running']);

    (new DeployJob($run->id))->failed(new RuntimeException('worker timeout'));

    expect($host->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->finished_at)->not->toBeNull()
        ->and($run->fresh()->error)->not->toBeNull();
});

it('configures tries and timeout from the lock ttl', function () {
    config(['dada.deploy.lock_ttl' => 1000]);
    $job = new DeployJob(1);

    expect($job->tries)->toBe(1)->and($job->timeout)->toBe(940);
});
