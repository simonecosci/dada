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

it('keeps the log row and does not throw when the broadcaster fails', function () {
    $broadcaster = new class extends Illuminate\Broadcasting\Broadcasters\Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('reverb down');
        }
    };
    Illuminate\Support\Facades\Broadcast::extend('boom', fn () => $broadcaster);
    config(['broadcasting.default' => 'boom', 'broadcasting.connections.boom' => ['driver' => 'boom']]);

    $run = DeployRun::factory()->create();
    (new DeployLogger($run, new SecretMasker([])))->info(null, 'still saved');

    expect($run->logs()->pluck('message')->all())->toBe(['still saved']);
});

it('exposes mask() using the same secrets', function () {
    $log = new DeployLogger(DeployRun::factory()->create(), new SecretMasker(['hunter22']));

    expect($log->mask('error: hunter22 refused'))->toBe('error: ****** refused');
});

it('masks multi-line secrets echoed line by line', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    $log = new DeployLogger($run, new SecretMasker(["line-one\nline-two\nline-three"]));
    $log->output(null, "line-one\nline-two\nline-three\n");

    expect($run->logs()->pluck('message')->all())->toBe(['******', '******', '******']);
});

it('emits an oversized partial line without waiting for a newline', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    $log = new DeployLogger($run, new SecretMasker(['progresssecret']));
    $log->output(null, str_repeat("10%\r", 3000));
    $log->output(null, 'progresssecret');
    $log->flush();

    $messages = $run->logs()->pluck('message');
    expect($messages->count())->toBe(2)
        ->and(strlen($messages[0]))->toBeLessThanOrEqual(8192 + 3)
        ->and($messages[1])->toBe('******');
});

it('scrubs invalid utf-8 before persisting', function () {
    Event::fake([DeployRunLogged::class]);
    $run = DeployRun::factory()->create();
    (new DeployLogger($run, new SecretMasker([])))->output(null, "bad \xFF\xFE bytes\n");

    $message = $run->logs()->value('message');
    expect(mb_check_encoding($message, 'UTF-8'))->toBeTrue()
        ->and($message)->toStartWith('bad ')->toEndWith(' bytes');
});
