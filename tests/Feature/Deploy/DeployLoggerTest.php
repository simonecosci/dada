<?php

use App\Deploy\DeployLogger;
use App\Deploy\SecretMasker;
use App\Events\DeployRunLogged;
use App\Models\DeployRun;
use App\Models\DeploymentHost;
use Illuminate\Support\Facades\Event;

it('persists masked lines and broadcasts them', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    $log = new DeployLogger($run, new SecretMasker(['s3cr3t']));
    $log->output(null, "login with s3cr3t\npartial");
    $log->flush();

    expect($run->logs()->pluck('message')->all())->toBe(['login with ******', 'partial']);
    Event::assertDispatchedTimes(DeployRunLogged::class, 2);
});

it('masks a secret split across two chunks', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    $log = new DeployLogger($run, new SecretMasker(['s3cr3t-value']));
    $log->output(null, 'password s3cr');
    $log->output(null, "3t-value ok\r\nnext");
    $log->flush();

    expect($run->logs()->pluck('message')->all())->toBe(['password ****** ok', 'next']);
});

it('keeps separate buffers per host and stores level and host', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    $a = DeploymentHost::factory()->create(['deployment_id' => $run->deployment_id]);
    $b = DeploymentHost::factory()->create(['deployment_id' => $run->deployment_id]);
    $log = new DeployLogger($run, new SecretMasker([]));
    $log->step($a, 'Inizio');
    $log->output($a, 'a-1 ');
    $log->output($b, "b-1\n");
    $log->output($a, "a-2\n");
    $log->error(null, 'boom');

    $rows = $run->logs()->orderBy('id')->get();
    expect($rows->pluck('message')->all())->toBe(['Inizio', 'b-1', 'a-1 a-2', 'boom'])
        ->and($rows->pluck('level')->all())->toBe(['step', 'output', 'output', 'error'])
        ->and($rows->pluck('deployment_host_id')->all())->toBe([$a->id, $b->id, $a->id, null]);
});

it('truncates very long lines', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    $log = new DeployLogger($run, new SecretMasker([]));
    $log->info(null, str_repeat('a', 20000));

    $message = $run->logs()->value('message');
    expect(strlen($message))->toBeLessThanOrEqual(8192 + 3)
        ->and($message)->toEndWith('…');
});

it('broadcasts the masked payload on the run channel', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    (new DeployLogger($run, new SecretMasker(['topsecret'])))->info(null, 'x topsecret');

    Event::assertDispatched(DeployRunLogged::class, function (DeployRunLogged $e) use ($run) {
        return $e->broadcastAs() === 'log'
            && $e->broadcastOn()[0]->name === "private-deploy-runs.{$run->id}"
            && $e->broadcastWith()['message'] === 'x ******'
            && array_keys($e->broadcastWith()) === ['id', 'deployment_host_id', 'level', 'message', 'created_at'];
    });
});
