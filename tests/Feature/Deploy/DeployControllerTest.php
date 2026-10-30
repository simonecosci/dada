<?php

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Jobs\DeployJob;
use App\Models\Deployment;
use App\Models\DeploymentSnapshot;
use App\Models\DeployRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function deployOperator(Deployment $d): User
{
    $op = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);

    return $op;
}

it('rejects a second deploy while one is active', function () {
    $d = Deployment::factory()->create();
    $op = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);
    DeployRun::factory()->for($d)->create(['status' => 'running']);
    $this->actingAs($op)->postJson("/deployments/{$d->id}/deploy")->assertStatus(409);
});

it('returns the 409 message', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    DeployRun::factory()->for($dh->deployment)->create(['status' => 'queued']);

    $this->actingAs(deployOperator($dh->deployment))->postJson("/deployments/{$dh->deployment_id}/deploy")
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Esecuzione già in corso']);
    Queue::assertNothingPushed();
});

it('forbids deploy to viewers', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    $viewer = User::factory()->create();
    $dh->deployment->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->postJson("/deployments/{$dh->deployment_id}/deploy")->assertForbidden();
    expect(DeployRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('creates a queued run with default options, dispatches the job and redirects', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    $op = deployOperator($dh->deployment);

    $response = $this->actingAs($op)->post("/deployments/{$dh->deployment_id}/deploy");

    $run = DeployRun::sole();
    $response->assertRedirect("/runs/{$run->id}");
    expect($run->status)->toBe(RunStatus::Queued)
        ->and($run->type)->toBe(RunType::Deploy)
        ->and($run->user_id)->toBe($op->id)
        ->and($run->options)->toBe(['deployment_host_id' => null, 'pull' => true, 'restart' => true, 'confirm_removals' => false]);
    Queue::assertPushed(DeployJob::class, fn (DeployJob $job) => $job->runId === $run->id);
});

it('passes the chosen options and host to the run', function () {
    Queue::fake();
    [$d, , $dhB] = deploymentWithTwoHosts();

    $this->actingAs(deployOperator($d))->postJson("/deployments/{$d->id}/deploy", [
        'deployment_host_id' => $dhB->id, 'pull' => false, 'restart' => false,
    ])->assertRedirect();

    expect(DeployRun::sole()->options)->toBe(['deployment_host_id' => $dhB->id, 'pull' => false, 'restart' => false, 'confirm_removals' => false]);
});

it('rejects a host of another deployment', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    $other = hostWithWordpress();

    $this->actingAs(deployOperator($dh->deployment))->postJson("/deployments/{$dh->deployment_id}/deploy", ['deployment_host_id' => $other->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('deployment_host_id');
    Queue::assertNothingPushed();
});

it('asks to confirm removals before deploying', function () {
    Queue::fake();
    [$d, $dhA, $dhB] = deploymentWithTwoHosts();
    DeploymentSnapshot::create([
        'deployment_host_id' => $dhB->id, 'deploy_run_id' => null,
        'installations' => [['slug' => 'wp', 'services' => ['wp']], ['slug' => 'old', 'services' => ['old-web']]],
        'host_variables' => [], 'files' => [],
    ]);
    $op = deployOperator($d);

    $this->actingAs($op)->postJson("/deployments/{$d->id}/deploy")
        ->assertStatus(422)
        ->assertExactJson(['removals' => [['deployment_host_id' => $dhB->id, 'slug' => 'old', 'services' => ['old-web']]]]);
    expect(DeployRun::count())->toBe(0);

    // Il solo host A non ha rimozioni.
    $this->actingAs($op)->postJson("/deployments/{$d->id}/deploy", ['deployment_host_id' => $dhA->id])->assertRedirect();
    DeployRun::query()->delete();

    $this->actingAs($op)->postJson("/deployments/{$d->id}/deploy", ['confirm_removals' => true])->assertRedirect();
    expect(DeployRun::sole()->options['confirm_removals'])->toBeTrue();
    Queue::assertPushed(DeployJob::class, 2);
});
