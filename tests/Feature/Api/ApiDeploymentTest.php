<?php

use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Models\Host;
use Laravel\Sanctum\Sanctum;

it('shows a deployment with hosts, installations and the active run', function () {
    $dh = hostWithWordpress();
    $inst = $dh->installations()->sole();
    $run = DeployRun::factory()->for($dh->deployment)->create(['status' => 'running', 'options' => ['pull' => true, 'lock_owner' => 'secret-owner']]);
    Sanctum::actingAs(operatorOf($dh->deployment), ['read']);

    $response = $this->getJson("/api/v1/deployments/{$dh->deployment_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $dh->deployment_id)
        ->assertJsonPath('data.hosts.0.id', $dh->id)
        ->assertJsonPath('data.hosts.0.name', $dh->host->name)
        ->assertJsonPath('data.hosts.0.hostname', $dh->host->hostname)
        ->assertJsonPath('data.hosts.0.path', $dh->path)
        ->assertJsonPath('data.hosts.0.orchestrator', 'compose')
        ->assertJsonPath('data.hosts.0.stack_name', 'client-x')
        ->assertJsonPath('data.hosts.0.installations.0.slug', 'wp')
        ->assertJsonPath('data.hosts.0.installations.0.project.slug', $inst->version->project->slug)
        ->assertJsonPath('data.hosts.0.installations.0.version', $inst->version->version)
        ->assertJsonPath('data.active_run.id', $run->id)
        ->assertJsonPath('data.active_run.status', 'running');

    // Neither secrets nor internal run options leak.
    expect($response->getContent())->not->toContain('pw-123')
        ->not->toContain('secret-owner')
        ->not->toContain('compose_template');
});

it('shows a null active run when the deployment is idle', function () {
    $dh = hostWithWordpress();
    DeployRun::factory()->for($dh->deployment)->create(['status' => 'succeeded']);
    Sanctum::actingAs(operatorOf($dh->deployment), ['read']);

    $this->getJson("/api/v1/deployments/{$dh->deployment_id}")
        ->assertOk()
        ->assertJsonPath('data.active_run', null);
});

it('lists the runs of a deployment newest first, paginated', function () {
    $dh = hostWithWordpress();
    $runs = DeployRun::factory()->for($dh->deployment)->count(3)->create(['status' => 'succeeded']);
    DeployRun::factory()->create(['status' => 'succeeded']); // another deployment
    Sanctum::actingAs(operatorOf($dh->deployment), ['read']);

    $this->getJson("/api/v1/deployments/{$dh->deployment_id}/runs?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $runs[2]->id)
        ->assertJsonPath('data.1.id', $runs[1]->id)
        ->assertJsonPath('meta.total', 3);
});

it('orders the hosts of a deployment by id', function () {
    [$d, $a, $b] = deploymentWithTwoHosts();
    DeploymentHost::factory()->for($d)->for(Host::factory())->create();
    Sanctum::actingAs(operatorOf($d), ['read']);

    $this->getJson("/api/v1/deployments/{$d->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data.hosts')
        ->assertJsonPath('data.hosts.0.id', $a->id)
        ->assertJsonPath('data.hosts.1.id', $b->id);
});
