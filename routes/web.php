<?php

use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\HostController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Catalog\ProjectController;
use App\Http\Controllers\Catalog\ProjectVersionController;
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
});

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except('show');
    Route::resource('credentials', CredentialController::class)->except('show');
    Route::resource('hosts', HostController::class);
    Route::post('hosts/{host}/check', [HostController::class, 'check'])->name('hosts.check');
    Route::post('hosts/{host}/reset-key', [HostController::class, 'resetKey'])->name('hosts.reset-key');
});

require __DIR__.'/settings.php';
