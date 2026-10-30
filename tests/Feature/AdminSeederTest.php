<?php

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

it('creates the first admin from the configured email and password', function () {
    config(['dada.admin' => ['email' => 'Admin@Example.com', 'name' => 'Root', 'password' => 'correct-horse-battery']]);

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true])->assertSuccessful();

    $user = User::whereEmail('admin@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Root')
        ->and($user->isAdmin())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('generates and prints a password when none is configured', function () {
    config(['dada.admin' => ['email' => 'admin@example.com', 'name' => null, 'password' => null]]);

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('Password:')
        ->assertSuccessful();

    $user = User::whereEmail('admin@example.com')->first();
    expect($user->name)->toBe('admin')
        ->and($user->isAdmin())->toBeTrue();
});

it('promotes an existing user with the configured email', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'old-password-123']);
    config(['dada.admin' => ['email' => 'admin@example.com', 'name' => null, 'password' => 'ignored-password']]);

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true])->assertSuccessful();

    $user->refresh();
    expect($user->isAdmin())->toBeTrue()
        ->and(Hash::check('old-password-123', $user->password))->toBeTrue();
});

it('does nothing when an admin already exists', function () {
    User::factory()->create(['email' => 'first@example.com', 'is_admin' => true]);
    config(['dada.admin' => ['email' => 'admin@example.com', 'name' => null, 'password' => 'secret-password']]);

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true])->assertSuccessful();

    expect(User::whereEmail('admin@example.com')->exists())->toBeFalse();
});

it('skips with a warning when no email is configured', function () {
    config(['dada.admin' => ['email' => null, 'name' => null, 'password' => null]]);

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('DADA_ADMIN_EMAIL')
        ->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('fails on an invalid email so the init container stops', function () {
    config(['dada.admin' => ['email' => 'not-an-email', 'name' => null, 'password' => null]]);

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true]);
})->throws(InvalidArgumentException::class);

it('seeds the admin through the default database seeder', function () {
    config(['dada.admin' => ['email' => 'admin@example.com', 'name' => null, 'password' => 'secret-password']]);

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(User::first()->isAdmin())->toBeTrue();
});
