<?php

use App\Deploy\RunTerminator;
use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Jobs\DeployJob;
use App\Models\Deployment;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use App\Models\User;
use App\Ssh\Fake\FakeRemoteShellFactory;
use App\Ssh\RemoteShellFactory;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function failingQueue(): void
{
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('redis down at 10.0.0.9'));
}

/*
| Dispatch fallito
*/

it('marks the deploy run failed and answers 503 JSON when the job cannot be queued', function () {
    $dh = hostWithWordpress();
    failingQueue();

    $this->actingAs(operatorOf($dh->deployment))->postJson("/deployments/{$dh->deployment_id}/deploy")
        ->assertStatus(503)
        ->assertJson(['message' => 'Impossibile accodare l\'esecuzione: riprova più tardi.']);

    $run = DeployRun::sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toBe('accodamento fallito (RuntimeException)')
        ->and($run->error)->not->toContain('10.0.0.9');
});

it('redirects back with an error toast when the deploy job cannot be queued from an Inertia request', function () {
    $dh = hostWithWordpress();
    failingQueue();

    $this->actingAs(operatorOf($dh->deployment))->from('/deployments/'.$dh->deployment_id)
        ->post("/deployments/{$dh->deployment_id}/deploy")
        ->assertRedirect('/deployments/'.$dh->deployment_id)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Impossibile accodare l\'esecuzione: riprova più tardi.']);

    expect(DeployRun::sole()->status)->toBe(RunStatus::Failed);
});

it('marks the restart run failed when the job cannot be queued', function () {
    $dh = hostWithWordpress();
    failingQueue();

    $this->actingAs(operatorOf($dh->deployment))->postJson("/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])
        ->assertStatus(503);

    expect(DeployRun::sole())->status->toBe(RunStatus::Failed)
        ->error->toBe('accodamento fallito (RuntimeException)');

    // Il run fallito non blocca il successivo.
    expect($dh->deployment->runs()->active()->exists())->toBeFalse();
});

/*
| Reaper
*/

it('reaps queued and running runs older than lock_ttl + retry_after and nothing else', function () {
    config(['dada.deploy.lock_ttl' => 600, 'queue.connections.redis-deploy.retry_after' => 720]);
    $limit = 600 + 720;
    $d = Deployment::factory()->create();

    $stuckRunning = DeployRun::factory()->for($d)->create(['status' => 'running', 'started_at' => now()->subSeconds($limit + 60)]);
    $stuckHost = DeployRunHost::factory()->create(['deploy_run_id' => $stuckRunning->id, 'status' => 'running']);
    $doneHost = DeployRunHost::factory()->create(['deploy_run_id' => $stuckRunning->id, 'status' => 'succeeded']);
    $stuckQueued = DeployRun::factory()->for($d)->create(['status' => 'queued', 'created_at' => now()->subSeconds($limit + 60)]);
    $freshRunning = DeployRun::factory()->for($d)->create(['status' => 'running', 'started_at' => now()->subSeconds($limit - 60), 'created_at' => now()->subDay()]);
    $freshQueued = DeployRun::factory()->for($d)->create(['status' => 'queued']);
    $oldDone = DeployRun::factory()->for($d)->create(['status' => 'succeeded', 'started_at' => now()->subDay(), 'created_at' => now()->subDay()]);

    $this->artisan('dada:runs:reap')->assertSuccessful();

    expect($stuckRunning->fresh())->status->toBe(RunStatus::Failed)->finished_at->not->toBeNull()
        ->error->toBe('esecuzione scaduta: nessun avanzamento oltre il limite')
        ->and($stuckHost->fresh())->status->toBe(RunStatus::Failed)
        ->and($doneHost->fresh())->status->toBe(RunStatus::Succeeded)
        ->and($stuckQueued->fresh()->status)->toBe(RunStatus::Failed)
        ->and($freshRunning->fresh()->status)->toBe(RunStatus::Running)
        ->and($freshQueued->fresh()->status)->toBe(RunStatus::Queued)
        ->and($oldDone->fresh())->status->toBe(RunStatus::Succeeded)->error->toBeNull();
});

it('releases only the lock owned by the reaped run', function () {
    $own = Deployment::factory()->create();
    $other = Deployment::factory()->create();
    $ownLock = Cache::lock(DeployJob::lockKey($own->id), 3600);
    $ownLock->get();
    $foreignLock = Cache::lock(DeployJob::lockKey($other->id), 3600);
    $foreignLock->get();

    $old = now()->subDays(2);
    DeployRun::factory()->for($own)->create(['status' => 'running', 'started_at' => $old, 'options' => ['lock_owner' => $ownLock->owner()]]);
    // Il run stantio dell'altro deployment ha un owner diverso da quello del lock attuale.
    DeployRun::factory()->for($other)->create(['status' => 'running', 'started_at' => $old, 'options' => ['lock_owner' => 'stale-owner']]);

    $this->artisan('dada:runs:reap')->assertSuccessful();

    expect(Cache::lock(DeployJob::lockKey($own->id), 10)->get())->toBeTrue()
        ->and(Cache::lock(DeployJob::lockKey($other->id), 10)->get())->toBeFalse();
});

it('schedules the reaper every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, 'dada:runs:reap'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
});

/*
| "Segna come fallita"
*/

it('lets an admin mark an active run as failed', function () {
    $run = DeployRun::factory()->create(['status' => 'running', 'started_at' => now()]);
    $host = DeployRunHost::factory()->create(['deploy_run_id' => $run->id, 'status' => 'running']);
    $admin = User::factory()->create(['is_admin' => true, 'name' => 'Ada']);

    $this->actingAs($admin)->from("/runs/{$run->id}")->post("/runs/{$run->id}/fail")
        ->assertRedirect("/runs/{$run->id}");

    expect($run->fresh())->status->toBe(RunStatus::Failed)->error->toBe('segnata come fallita da Ada')
        ->and($host->fresh()->status)->toBe(RunStatus::Failed);
});

it('forbids operators from marking a run as failed', function () {
    $run = DeployRun::factory()->create(['status' => 'running']);

    $this->actingAs(operatorOf($run->deployment))->post("/runs/{$run->id}/fail")->assertForbidden();
    expect($run->fresh()->status)->toBe(RunStatus::Running);
});

it('refuses to mark a finished run as failed', function () {
    $run = DeployRun::factory()->create(['status' => 'succeeded']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->post("/runs/{$run->id}/fail")
        ->assertSessionHasErrors('run');
    expect($run->fresh()->status)->toBe(RunStatus::Succeeded);
});

it('exposes the fail permission only to admins on active runs', function () {
    $run = DeployRun::factory()->create(['status' => 'running']);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get("/runs/{$run->id}")
        ->assertInertia(fn ($page) => $page->where('can.fail', true));
    $this->actingAs(operatorOf($run->deployment))->get("/runs/{$run->id}")
        ->assertInertia(fn ($page) => $page->where('can.fail', false));
});

it('does not overwrite a run marked failed while the job was still working', function () {
    $fakes = new FakeRemoteShellFactory;
    app()->instance(RemoteShellFactory::class, $fakes);
    $dh = hostWithWordpress();
    $run = DeployRun::factory()->for($dh->deployment)->create(['options' => ['pull' => false, 'restart' => false, 'confirm_removals' => false]]);
    // Durante il controllo del disco un admin segna il run come fallito.
    $fakes->shellFor($dh->host)->expect('/df -Pm/', 0, "50000\n");
    Illuminate\Support\Facades\Event::listen(DeployRunUpdated::class, function ($event) use ($run) {
        if ($event->run->status === RunStatus::Running && $event->run->hosts()->exists()) {
            RunTerminator::fail(DeployRun::find($run->id), 'segnata come fallita da Ada');
        }
    });

    DeployJob::dispatchSync($run->id);

    expect($run->fresh())->status->toBe(RunStatus::Failed)->error->toBe('segnata come fallita da Ada');
});
