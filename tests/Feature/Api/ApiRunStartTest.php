<?php

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Jobs\DeployJob;
use App\Jobs\RestartJob;
use App\Models\DeploymentSnapshot;
use App\Models\DeployRun;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('starts a deploy: 202, run created and job dispatched', function () {
    Bus::fake();
    $dh = hostWithWordpress();
    $op = operatorOf($dh->deployment);
    Sanctum::actingAs($op, ['read', 'deploy']);

    $response = $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy", ['pull' => false]);

    $run = DeployRun::sole();
    $response->assertStatus(202)
        ->assertJsonPath('data.id', $run->id)
        ->assertJsonPath('data.type', 'deploy')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.user', $op->name)
        ->assertJsonPath('data.options', ['deployment_host_id' => null, 'pull' => false, 'restart' => true, 'confirm_removals' => false]);
    expect($run->type)->toBe(RunType::Deploy)->and($run->user_id)->toBe($op->id);
    Bus::assertDispatched(DeployJob::class, fn (DeployJob $job) => $job->runId === $run->id);
});

it('lets an admin deploy any deployment', function () {
    Bus::fake();
    $dh = hostWithWordpress();
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['read', 'deploy']);

    $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy")->assertStatus(202);
    Bus::assertDispatched(DeployJob::class);
});

it('answers 409 when a run is already active', function () {
    Bus::fake();
    $dh = hostWithWordpress();
    DeployRun::factory()->for($dh->deployment)->create(['status' => 'running']);
    Sanctum::actingAs(operatorOf($dh->deployment), ['deploy']);

    $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy")
        ->assertStatus(409)
        ->assertExactJson(['message' => 'A run is already in progress']);
    $this->postJson("/api/v1/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])
        ->assertStatus(409);

    expect(DeployRun::count())->toBe(1);
    Bus::assertNothingDispatched();
});

it('answers 422 with the removals until they are confirmed', function () {
    Bus::fake();
    [$d, , $dhB] = deploymentWithTwoHosts();
    DeploymentSnapshot::create([
        'deployment_host_id' => $dhB->id, 'deploy_run_id' => null,
        'installations' => [['slug' => 'wp', 'services' => ['wp']], ['slug' => 'old', 'services' => ['old-web']]],
        'host_variables' => [], 'files' => [],
    ]);
    Sanctum::actingAs(operatorOf($d), ['deploy']);

    $this->postJson("/api/v1/deployments/{$d->id}/deploy")
        ->assertStatus(422)
        ->assertJsonPath('removals', [['deployment_host_id' => $dhB->id, 'slug' => 'old', 'services' => ['old-web']]]);
    expect(DeployRun::count())->toBe(0);

    $this->postJson("/api/v1/deployments/{$d->id}/deploy", ['confirm_removals' => true])->assertStatus(202);
    expect(DeployRun::sole()->options['confirm_removals'])->toBeTrue();
});

it('answers 422 on invalid input', function () {
    Bus::fake();
    $dh = hostWithWordpress();
    $other = hostWithWordpress();
    Sanctum::actingAs(operatorOf($dh->deployment), ['deploy']);

    $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy", ['deployment_host_id' => $other->id, 'pull' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['deployment_host_id', 'pull']);
    expect(DeployRun::count())->toBe(0);
});

it('answers 503 and fails the run when the job cannot be queued', function () {
    $dh = hostWithWordpress();
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('redis down at 10.0.0.9'));
    Sanctum::actingAs(operatorOf($dh->deployment), ['deploy']);

    $response = $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy")
        ->assertStatus(503)
        ->assertJsonPath('message', 'Unable to queue the run: try again later.');

    $run = DeployRun::sole();
    $response->assertJsonPath('run_id', $run->id);
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe('queueing failed (RuntimeException)')
        ->and($response->getContent())->not->toContain('10.0.0.9');
});

it('starts a restart: 202, run created and job dispatched', function () {
    Bus::fake();
    $dh = hostWithWordpress();
    Sanctum::actingAs(operatorOf($dh->deployment), ['read', 'deploy']);

    $this->postJson("/api/v1/deployment-hosts/{$dh->id}/restart", ['action' => 'restart', 'installation_slug' => 'wp', 'service' => 'wp'])
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'restart')
        ->assertJsonPath('data.options', ['deployment_host_id' => $dh->id, 'action' => 'restart', 'installation_slug' => 'wp', 'service' => 'wp']);

    $run = DeployRun::sole();
    expect($run->type)->toBe(RunType::Restart);
    Bus::assertDispatched(RestartJob::class, fn (RestartJob $job) => $job->runId === $run->id);
});

it('answers 422 to a restart of an unknown or invalid service', function (array $payload, string $field) {
    Bus::fake();
    $dh = hostWithWordpress();
    Sanctum::actingAs(operatorOf($dh->deployment), ['deploy']);

    $this->postJson("/api/v1/deployment-hosts/{$dh->id}/restart", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
    expect(DeployRun::count())->toBe(0);
    Bus::assertNothingDispatched();
})->with([
    'unknown service' => [['action' => 'restart', 'installation_slug' => 'wp', 'service' => 'db'], 'service'],
    'service injection' => [['action' => 'restart', 'installation_slug' => 'wp', 'service' => 'wp; id'], 'service'],
    'missing action' => [[], 'action'],
]);
