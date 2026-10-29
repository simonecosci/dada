<?php

use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\HostController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except('show');
    Route::resource('credentials', CredentialController::class)->except('show');
    Route::resource('hosts', HostController::class);
});

require __DIR__.'/settings.php';
