<?php

use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\HostController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Catalog\ProjectController;
use App\Http\Controllers\Catalog\ProjectVersionController;
use App\Http\Controllers\Deployments\DeploymentController;
use App\Http\Controllers\Deployments\DeploymentHostController;
use App\Http\Controllers\Deployments\InstallationController;
use App\Http\Controllers\Deployments\MemberController;
use App\Http\Controllers\Deployments\PreviewController;
use App\Http\Controllers\Deployments\RunController;
use App\Http\Controllers\VariableRevealController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::post('variables/{variable}/reveal', VariableRevealController::class)->name('variables.reveal');

    Route::resource('projects', ProjectController::class);
    Route::post('projects/{project}/versions', [ProjectVersionController::class, 'store'])->name('projects.versions.store');
    Route::get('versions/{version}/edit', [ProjectVersionController::class, 'edit'])->name('versions.edit');
    Route::put('versions/{version}', [ProjectVersionController::class, 'update'])->name('versions.update');
    Route::post('versions/{version}/publish', [ProjectVersionController::class, 'publish'])->name('versions.publish');
    Route::post('versions/{version}/clone', [ProjectVersionController::class, 'clone'])->name('versions.clone');
    Route::delete('versions/{version}', [ProjectVersionController::class, 'destroy'])->name('versions.destroy');

    Route::resource('deployments', DeploymentController::class);
    Route::post('deployments/{deployment}/members', [MemberController::class, 'store'])->name('deployments.members.store');
    Route::put('deployments/{deployment}/members/{user}', [MemberController::class, 'update'])->name('deployments.members.update');
    Route::delete('deployments/{deployment}/members/{user}', [MemberController::class, 'destroy'])->name('deployments.members.destroy');

    Route::get('deployments/{deployment}/hosts/create', [DeploymentHostController::class, 'create'])->name('deployments.hosts.create');
    Route::post('deployments/{deployment}/hosts', [DeploymentHostController::class, 'store'])->name('deployments.hosts.store');
    Route::get('deployments/{deployment}/hosts/{deploymentHost}', [DeploymentHostController::class, 'show'])->name('deployments.hosts.show');
    Route::get('deployments/{deployment}/hosts/{deploymentHost}/edit', [DeploymentHostController::class, 'edit'])->name('deployments.hosts.edit');
    Route::put('deployments/{deployment}/hosts/{deploymentHost}', [DeploymentHostController::class, 'update'])->name('deployments.hosts.update');
    Route::delete('deployments/{deployment}/hosts/{deploymentHost}', [DeploymentHostController::class, 'destroy'])->name('deployments.hosts.destroy');
    Route::put('deployments/{deployment}/hosts/{deploymentHost}/variables', [DeploymentHostController::class, 'variables'])->name('deployments.hosts.variables.update');
    Route::put('deployments/{deployment}/hosts/{deploymentHost}/registries', [DeploymentHostController::class, 'registries'])->name('deployments.hosts.registries.update');

    Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');

    Route::get('deployment-hosts/{deploymentHost}/preview', PreviewController::class)->name('hosts.preview');
    Route::get('deployment-hosts/{deploymentHost}/installations/create', [InstallationController::class, 'create'])->name('hosts.installations.create');
    Route::post('deployment-hosts/{deploymentHost}/installations', [InstallationController::class, 'store'])->name('hosts.installations.store');
    Route::put('deployment-hosts/{deploymentHost}/installations/order', [InstallationController::class, 'reorder'])->name('hosts.installations.reorder');
    Route::get('installations/{installation}/edit', [InstallationController::class, 'edit'])->name('installations.edit');
    Route::put('installations/{installation}', [InstallationController::class, 'update'])->name('installations.update');
    Route::delete('installations/{installation}', [InstallationController::class, 'destroy'])->name('installations.destroy');
});

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except('show');
    Route::resource('credentials', CredentialController::class)->except('show');
    Route::resource('hosts', HostController::class);
    Route::post('hosts/{host}/check', [HostController::class, 'check'])->name('hosts.check');
    Route::post('hosts/{host}/reset-key', [HostController::class, 'resetKey'])->name('hosts.reset-key');
});

require __DIR__.'/settings.php';
