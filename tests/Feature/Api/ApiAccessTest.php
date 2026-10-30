<?php

use App\Models\Deployment;
use App\Models\DeployRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function viewerOf(Deployment $deployment): User
{
    $user = User::factory()->create();
    $deployment->members()->attach($user, ['role' => 'viewer']);

    return $user;
}

it('answers 401 without a token', function () {
    $d = Deployment::factory()->create();

    $this->getJson('/api/v1/deployments')->assertUnauthorized();
    $this->getJson("/api/v1/deployments/{$d->id}")->assertUnauthorized();
    $this->postJson("/api/v1/deployments/{$d->id}/deploy")->assertUnauthorized();
});

it('answers 401 with an invalid bearer token and accepts a real one', function () {
    $user = User::factory()->create();
    $token = $user->createToken('ci', ['read'])->plainTextToken;

    $this->withToken('nope')->getJson('/api/v1/deployments')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/deployments')->assertOk();
    expect($user->tokens()->sole()->last_used_at)->not->toBeNull();
});

it('lets a read token list and show but forbids deploy and restart', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    Sanctum::actingAs(operatorOf($dh->deployment), ['read']);

    $this->getJson('/api/v1/deployments')->assertOk()->assertJsonPath('data.0.id', $dh->deployment_id);
    $this->getJson("/api/v1/deployments/{$dh->deployment_id}")->assertOk();
    $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy")->assertForbidden();
    $this->postJson("/api/v1/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])->assertForbidden();

    expect(DeployRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lets a deploy token read too', function () {
    $dh = hostWithWordpress();
    Sanctum::actingAs(operatorOf($dh->deployment), ['read', 'deploy']);

    $this->getJson("/api/v1/deployments/{$dh->deployment_id}/runs")->assertOk();
});

it('forbids deploy and restart to a viewer even with a deploy token', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    Sanctum::actingAs(viewerOf($dh->deployment), ['read', 'deploy']);

    $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy")->assertForbidden();
    $this->postJson("/api/v1/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])->assertForbidden();

    expect(DeployRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('answers 404 to non-members on every deployment endpoint', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    $run = DeployRun::factory()->for($dh->deployment)->create(['status' => 'succeeded']);
    Sanctum::actingAs(User::factory()->create(), ['read', 'deploy']);

    $this->getJson("/api/v1/deployments/{$dh->deployment_id}")->assertNotFound();
    $this->getJson("/api/v1/deployments/{$dh->deployment_id}/runs")->assertNotFound();
    $this->getJson("/api/v1/runs/{$run->id}")->assertNotFound();
    $this->getJson("/api/v1/runs/{$run->id}/logs")->assertNotFound();
    $this->postJson("/api/v1/deployments/{$dh->deployment_id}/deploy")->assertNotFound();
    $this->postJson("/api/v1/deployment-hosts/{$dh->id}/restart", ['action' => 'restart'])->assertNotFound();

    expect(DeployRun::count())->toBe(1);
});

it('lists only the deployments visible to the user', function () {
    $mine = Deployment::factory()->create(['name' => 'B mine']);
    $viewed = Deployment::factory()->create(['name' => 'A viewed']);
    Deployment::factory()->create(['name' => 'C other']);
    $user = User::factory()->create();
    $mine->members()->attach($user, ['role' => 'operator']);
    $viewed->members()->attach($user, ['role' => 'viewer']);
    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/deployments')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'A viewed')
        ->assertJsonPath('data.1.name', 'B mine')
        ->assertJsonPath('meta.total', 2);
});

it('shows every deployment to an admin', function () {
    $deployments = Deployment::factory()->count(3)->create();
    $admin = User::factory()->create(['is_admin' => true]);
    Sanctum::actingAs($admin, ['read']);

    $this->getJson('/api/v1/deployments')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson("/api/v1/deployments/{$deployments[0]->id}")->assertOk();
});

it('paginates the deployment list', function () {
    Deployment::factory()->count(3)->create();
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['read']);

    $this->getJson('/api/v1/deployments?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/deployments?per_page=500')->assertUnprocessable();
});

it('authenticates first-party session requests without a token', function () {
    $dh = hostWithWordpress();

    $this->actingAs(operatorOf($dh->deployment))
        ->getJson("/api/v1/deployments/{$dh->deployment_id}")
        ->assertOk();
});
