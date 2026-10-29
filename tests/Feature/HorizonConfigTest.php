<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('runs both supervisors in production', function () {
    expect(config('horizon.environments.production'))->toHaveKeys(['supervisor-1', 'supervisor-deploy']);
});

it('ships production defaults in the example env files', function () {
    $env = file_get_contents(base_path('.env.example'));
    $compose = file_get_contents(base_path('docker-compose.example.yml'));

    expect($env)->toContain("\nAPP_ENV=production\n")->toContain("\nAPP_DEBUG=false\n")
        ->and($compose)->toContain('APP_ENV: production')->toContain("APP_DEBUG: 'false'");
});

it('lets only admins view Horizon', function () {
    expect(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create(['is_admin' => true]))->allows('viewHorizon'))->toBeTrue();
});
