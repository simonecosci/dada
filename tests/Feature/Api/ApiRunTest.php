<?php

use App\Deploy\DeployLogger;
use App\Deploy\SecretMasker;
use App\Events\DeployRunLogged;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use App\Models\DeployRunLog;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

it('shows a run with its hosts', function () {
    $dh = hostWithWordpress();
    $op = operatorOf($dh->deployment);
    $run = DeployRun::factory()->for($dh->deployment)->create([
        'user_id' => $op->id,
        'status' => 'failed',
        'error' => 'host unreachable',
        'options' => ['deployment_host_id' => null, 'pull' => true, 'restart' => true, 'confirm_removals' => false, 'lock_owner' => 'abc'],
        'started_at' => now(), 'finished_at' => now(),
    ]);
    DeployRunHost::factory()->create(['deploy_run_id' => $run->id, 'deployment_host_id' => $dh->id, 'status' => 'failed', 'error' => 'boom']);
    Sanctum::actingAs($op, ['read']);

    $this->getJson("/api/v1/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $run->id)
        ->assertJsonPath('data.deployment_id', $dh->deployment_id)
        ->assertJsonPath('data.type', 'deploy')
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', 'host unreachable')
        ->assertJsonPath('data.user', $op->name)
        ->assertJsonPath('data.options', ['deployment_host_id' => null, 'pull' => true, 'restart' => true, 'confirm_removals' => false])
        ->assertJsonPath('data.hosts.0.deployment_host_id', $dh->id)
        ->assertJsonPath('data.hosts.0.name', $dh->host->name)
        ->assertJsonPath('data.hosts.0.status', 'failed')
        ->assertJsonPath('data.hosts.0.error', 'boom');
});

it('pages the log with the after cursor', function () {
    $dh = hostWithWordpress();
    $run = DeployRun::factory()->for($dh->deployment)->create(['status' => 'running']);
    $logs = collect(range(1, 5))->map(fn (int $i) => DeployRunLog::factory()->create([
        'deploy_run_id' => $run->id, 'level' => 'info', 'message' => "line {$i}",
    ]));
    DeployRunLog::factory()->create(['message' => 'another run']);
    Sanctum::actingAs(viewerOfRun($run), ['read']);

    $this->getJson("/api/v1/runs/{$run->id}/logs?limit=3")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.message', 'line 1')
        ->assertJsonPath('meta.last_id', $logs[2]->id)
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('meta.run_status', 'running');

    $this->getJson("/api/v1/runs/{$run->id}/logs?after={$logs[2]->id}&limit=3")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.message', 'line 4')
        ->assertJsonPath('meta.last_id', $logs[4]->id)
        ->assertJsonPath('meta.has_more', false);

    // Nothing new: the cursor stays where it was.
    $this->getJson("/api/v1/runs/{$run->id}/logs?after={$logs[4]->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.last_id', $logs[4]->id);

    $this->getJson("/api/v1/runs/{$run->id}/logs?limit=5000")->assertUnprocessable();
});

it('never returns secret values in the log or the run error', function () {
    Event::fake([DeployRunLogged::class]);
    $dh = hostWithWordpress();
    $run = DeployRun::factory()->for($dh->deployment)->create(['status' => 'running']);
    $logger = new DeployLogger($run, new SecretMasker(['pw-123']));
    $logger->info($dh, 'connecting with DB_PASSWORD=pw-123');
    $logger->output($dh, "echo pw-123\n");
    $run->update(['status' => 'failed', 'error' => $logger->mask('failed: pw-123 rejected')]);
    Sanctum::actingAs(viewerOfRun($run), ['read']);

    $logs = $this->getJson("/api/v1/runs/{$run->id}/logs")->assertOk()->assertJsonCount(2, 'data');
    $show = $this->getJson("/api/v1/runs/{$run->id}")->assertOk();

    expect($logs->getContent())->not->toContain('pw-123')->toContain(SecretMasker::MASK)
        ->and($show->getContent())->not->toContain('pw-123')
        ->and($show->json('data.error'))->toBe('failed: '.SecretMasker::MASK.' rejected');
});

function viewerOfRun(DeployRun $run): User
{
    $user = User::factory()->create();
    $run->deployment->members()->attach($user, ['role' => 'viewer']);

    return $user;
}
