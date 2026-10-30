<?php

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Jobs\RestartJob;
use App\Models\DeployRun;
use App\Models\Installation;
use App\Models\User;
use App\Ssh\Fake\FakeRemoteShellFactory;
use App\Ssh\RemoteShellFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/** Esegue subito l'ultimo run in coda (la coda è finta nei test). */
function runQueuedRestart(): void
{
    app()->call([new RestartJob(DeployRun::query()->latest('id')->firstOrFail()->id), 'handle']);
}

function restartShells(): FakeRemoteShellFactory
{
    Queue::fake();
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);

    return $fakes;
}

it('restarts a single service of an installation', function () {
    $fakes = restartShells();
    $dh = hostWithWordpress(['path' => '/srv/x'], []);
    $dh->host->update(['sudo' => '']);
    $op = operatorOf($dh->deployment);

    $this->actingAs($op)->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'restart', 'installation_slug' => 'wp', 'service' => 'wp'])->assertRedirect();
    runQueuedRestart();

    expect($fakes->shellFor($dh->host)->commands())->toContain("cd '/srv/x' && ./wp.sh restart wp");
    $run = DeployRun::sole();
    expect($run->type)->toBe(RunType::Restart)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->options)->toMatchArray(['deployment_host_id' => $dh->id, 'action' => 'restart', 'installation_slug' => 'wp', 'service' => 'wp']);
});

it('restarts a whole installation and a whole host', function () {
    $fakes = restartShells();
    $dh = hostWithWordpress(['path' => '/srv/x']);
    $dh->host->update(['sudo' => '']);
    $op = operatorOf($dh->deployment);

    $this->actingAs($op)->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'stop', 'installation_slug' => 'wp'])->assertRedirect();
    runQueuedRestart();
    $this->actingAs($op)->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'start'])->assertRedirect();
    runQueuedRestart();

    expect($fakes->shellFor($dh->host)->commands())
        ->toContain("cd '/srv/x' && ./wp.sh stop all")
        ->toContain("cd '/srv/x' && ./restart.sh start");
});

it('wraps with sudo when configured', function () {
    $fakes = restartShells();
    $dh = hostWithWordpress();
    $dh->host->update(['sudo' => 'sudo -n']);

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])->assertRedirect();
    runQueuedRestart();

    $commands = $fakes->shellFor($dh->host)->commands();
    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toStartWith('sudo -n sh -c ')
        ->and($commands[0])->toContain('./restart.sh restart');
});

it('rejects service without installation', function () {
    Queue::fake();
    $dh = hostWithWordpress();

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'restart', 'service' => 'wp'])
        ->assertSessionHasErrors('installation_slug');
    expect(DeployRun::count())->toBe(0);
});

it('rejects invalid action, unknown slug, unknown service and shell metacharacters', function (array $payload, string $field) {
    Queue::fake();
    $dh = hostWithWordpress();

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", $payload)->assertSessionHasErrors($field);
    expect(DeployRun::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'action' => [['action' => 'rm'], 'action'],
    'unknown slug' => [['action' => 'start', 'installation_slug' => 'nope'], 'installation_slug'],
    'slug injection' => [['action' => 'start', 'installation_slug' => "wp\n; id"], 'installation_slug'],
    'unknown service' => [['action' => 'start', 'installation_slug' => 'wp', 'service' => 'db'], 'service'],
    'service injection' => [['action' => 'start', 'installation_slug' => 'wp', 'service' => 'wp; id'], 'service'],
]);

it('does not allow init services', function () {
    Queue::fake();
    $dh = hostWithWordpress([], ['compose' => ['services' => [
        'wp' => ['image' => 'wordpress:6'],
        'migrate' => ['image' => 'wordpress:6', 'x-dada' => ['init' => true]],
    ]]]);

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'start', 'installation_slug' => 'wp', 'service' => 'migrate'])
        ->assertSessionHasErrors('service');
});

it('forbids viewers and rejects while a run is active', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    $viewer = User::factory()->create();
    $dh->deployment->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'start'])->assertForbidden();

    DeployRun::factory()->for($dh->deployment)->create(['status' => 'running']);
    $this->actingAs(operatorOf($dh->deployment))->postJson("/deployment-hosts/{$dh->id}/restart", ['action' => 'start'])
        ->assertStatus(409)->assertExactJson(['message' => 'Esecuzione già in corso']);
    Queue::assertNothingPushed();
});

it('queues the job on the deploy queue', function () {
    Queue::fake();
    $dh = hostWithWordpress();

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'start'])->assertRedirect();

    Queue::assertPushed(RestartJob::class, fn (RestartJob $j) => $j->connection === 'redis-deploy' && $j->queue === 'deploy');
});

it('fails the run with a masked error and releases the lock when the command fails', function () {
    $fakes = restartShells();
    $dh = hostWithWordpress();
    $fakes->shellFor($dh->host)->expect('/restart\.sh/', 1, 'boom pw-123');

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])->assertRedirect();
    runQueuedRestart();

    $run = DeployRun::sole();
    $host = $run->hosts()->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($host->status)->toBe(RunStatus::Failed)
        ->and($host->error)->toContain('boom')->not->toContain('pw-123')
        ->and(Cache::lock("deployment-run:{$dh->deployment_id}", 10)->get())->toBeTrue();
});

it('restarts a host whose generation is broken and still masks secrets', function () {
    $fakes = restartShells();
    $dh = hostWithWordpress();
    // Servizio "wp" definito da due installazioni: la generazione fallisce.
    Installation::factory()->for($dh)->create(['slug' => 'dup', 'compose' => ['services' => ['wp' => ['image' => 'wordpress:6']]]]);
    $fakes->shellFor($dh->host)->expect('/restart\.sh/', 0, 'ok pw-123 done');

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'stop'])->assertRedirect();
    runQueuedRestart();

    $run = DeployRun::sole();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->logs()->where('level', 'output')->pluck('message')->implode("\n"))->toContain('ok ****** done')->not->toContain('pw-123');
});

it('stores a friendly error when the script is missing on the host', function () {
    $fakes = restartShells();
    $dh = hostWithWordpress();
    $fakes->shellFor($dh->host)->expect('/wp\.sh/', 127, 'sh: 1: ./wp.sh: not found pw-123');

    $this->actingAs(operatorOf($dh->deployment))->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'restart', 'installation_slug' => 'wp'])->assertRedirect();
    runQueuedRestart();

    $host = DeployRun::sole()->hosts()->sole();
    expect($host->status)->toBe(RunStatus::Failed)
        ->and($host->error)->toBe("script non presente sull'host: esegui prima un deploy")
        ->and(DeployRun::sole()->logs()->where('level', 'output')->pluck('message')->implode("\n"))->toContain('not found')->not->toContain('pw-123');
});

it('redirects back with an error toast on 409 for Inertia requests', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    DeployRun::factory()->for($dh->deployment)->create(['status' => 'running']);
    $back = "/deployments/{$dh->deployment_id}/hosts/{$dh->id}";

    $this->actingAs(operatorOf($dh->deployment))->from($back)
        ->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'start'], ['X-Inertia' => 'true'])
        ->assertRedirect($back)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Esecuzione già in corso']);
    Queue::assertNothingPushed();
});

it('returns validation errors to the page for Inertia requests', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    $back = "/deployments/{$dh->deployment_id}/hosts/{$dh->id}";

    $this->actingAs(operatorOf($dh->deployment))->from($back)
        ->post("/deployment-hosts/{$dh->id}/restart", ['action' => 'start', 'installation_slug' => 'wp', 'service' => 'nope'], ['X-Inertia' => 'true'])
        ->assertRedirect($back)
        ->assertSessionHasErrors(['service' => 'Servizio non presente in questa installazione.']);
});
