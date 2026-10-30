<?php

use App\Models\Deployment;
use App\Models\DeployRun;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('deploy-runs.{runId}', function ($user, $runId) {
    $run = DeployRun::with('deployment')->find($runId);

    return $run !== null && $user->can('view', $run->deployment);
});

Broadcast::channel('deployments.{deploymentId}', function ($user, $deploymentId) {
    $deployment = Deployment::find($deploymentId);

    return $deployment !== null && $user->can('view', $deployment);
});
