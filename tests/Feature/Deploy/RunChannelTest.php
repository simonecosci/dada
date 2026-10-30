<?php

use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use App\Models\DeployRunLog;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    // I canali si registrano sul driver attivo al boot (null nei test): ri-registrali su quello reverb.
    Broadcast::purge();
    require base_path('routes/channels.php');
});

function authChannel(User $user, string $channel)
{
    return test()->actingAs($user)->post('/broadcasting/auth', ['channel_name' => "private-{$channel}", 'socket_id' => '1.1']);
}

it('authorizes run channel only for deployment members', function () {
    $run = DeployRun::factory()->create();
    $member = User::factory()->create();
    $run->deployment->members()->attach($member, ['role' => 'viewer']);

    authChannel($member, "deploy-runs.{$run->id}")->assertOk();
    authChannel(User::factory()->create(), "deploy-runs.{$run->id}")->assertForbidden();
});

it('authorizes the deployment channel only for members and admins', function () {
    $run = DeployRun::factory()->create();
    $member = User::factory()->create();
    $run->deployment->members()->attach($member, ['role' => 'viewer']);
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    authChannel($member, "deployments.{$run->deployment_id}")->assertOk();
    authChannel($admin, "deployments.{$run->deployment_id}")->assertOk();
    authChannel(User::factory()->create(), "deployments.{$run->deployment_id}")->assertForbidden();
});

it('rejects the channel of a missing run', function () {
    authChannel(User::factory()->create(), 'deploy-runs.999999')->assertForbidden();
});

it('broadcasts run updates on both channels', function () {
    $run = DeployRun::factory()->create(['status' => RunStatus::Running]);
    $dh = DeployRunHost::factory()->create(['deploy_run_id' => $run->id, 'status' => RunStatus::Failed, 'error' => 'ko']);
    $event = new DeployRunUpdated($run);

    expect(array_map(fn ($c) => $c->name, $event->broadcastOn()))
        ->toBe(["private-deploy-runs.{$run->id}", "private-deployments.{$run->deployment_id}"])
        ->and($event->broadcastAs())->toBe('run.updated')
        ->and($event->broadcastWith())->toBe([
            'id' => $run->id,
            'status' => 'running',
            'hosts' => [['deployment_host_id' => $dh->deployment_host_id, 'status' => 'failed', 'error' => 'ko']],
        ]);
});

it('shows a run with its logs to members only', function () {
    $run = DeployRun::factory()->create();
    DeployRunLog::factory()->count(3)->create(['deploy_run_id' => $run->id]);
    $member = User::factory()->create();
    $run->deployment->members()->attach($member, ['role' => 'viewer']);

    $this->actingAs($member)->get("/runs/{$run->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('runs/Show')
            ->where('run.id', $run->id)
            ->has('logs', 3));

    $this->actingAs(User::factory()->create())->get("/runs/{$run->id}")->assertForbidden();
});

it('caps the initial logs to the last 2000 lines in order', function () {
    $run = DeployRun::factory()->create();
    foreach (array_chunk(range(1, 2005), 500) as $chunk) {
        DeployRunLog::insert(array_map(fn ($i) => [
            'deploy_run_id' => $run->id, 'level' => 'output', 'message' => "line {$i}", 'created_at' => now(),
        ], $chunk));
    }
    $member = User::factory()->create();
    $run->deployment->members()->attach($member, ['role' => 'viewer']);

    $this->actingAs($member)->get("/runs/{$run->id}")
        ->assertInertia(fn ($page) => $page
            ->has('logs', 2000)
            ->where('logs.0.message', 'line 6')
            ->where('logs.1999.message', 'line 2005'));
});

it('lists the latest runs on the deployment and host pages', function () {
    $run = DeployRun::factory()->create();
    $member = User::factory()->create();
    $run->deployment->members()->attach($member, ['role' => 'viewer']);

    $this->actingAs($member)->get("/deployments/{$run->deployment_id}")
        ->assertInertia(fn ($page) => $page->has('runs', 1)->where('runs.0.id', $run->id)->where('runs.0.type', 'deploy'));
});
