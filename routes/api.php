<?php

use App\Http\Controllers\Api\V1\DeployController;
use App\Http\Controllers\Api\V1\DeploymentController;
use App\Http\Controllers\Api\V1\RestartController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Middleware\HideInvisibleDeployments;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 (prefix api/v1, see bootstrap/app.php), authenticated with Sanctum personal access tokens.
| Abilities: `read` for the GET endpoints, `deploy` to start runs (a deploy token can also read).
*/

Route::middleware(['auth:sanctum', 'throttle:api', HideInvisibleDeployments::class])->name('api.')->group(function () {
    Route::middleware('ability:read,deploy')->group(function () {
        Route::get('deployments', [DeploymentController::class, 'index'])->name('deployments.index');
        Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
        Route::get('deployments/{deployment}/runs', [DeploymentController::class, 'runs'])->name('deployments.runs');
        Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
        Route::get('runs/{run}/logs', [RunController::class, 'logs'])->name('runs.logs');
    });

    Route::middleware('abilities:deploy')->group(function () {
        Route::post('deployments/{deployment}/deploy', DeployController::class)->name('deployments.deploy');
        Route::post('deployment-hosts/{deploymentHost}/restart', RestartController::class)->name('hosts.restart');
    });
});
