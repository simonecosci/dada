<?php

use App\Models\User;

it('promotes an existing user with dada:admin', function () {
    $u = User::factory()->create(['email' => 'a@example.com']);
    $this->artisan('dada:admin', ['email' => 'a@example.com'])->assertSuccessful();
    expect($u->fresh()->isAdmin())->toBeTrue();
});

it('creates a missing user with a random 20 character password', function () {
    $this->artisan('dada:admin', ['email' => 'new@example.com', '--name' => 'New'])
        ->expectsOutputToContain('Password')
        ->assertSuccessful();

    $user = User::whereEmail('new@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('New')
        ->and($user->isAdmin())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('rejects an invalid email', function () {
    $this->artisan('dada:admin', ['email' => 'not-an-email'])->assertFailed();
});
