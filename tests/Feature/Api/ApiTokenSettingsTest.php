<?php

use App\Models\User;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('asks to confirm the password before showing or creating tokens', function () {
    $this->flushSession();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('api-tokens.edit'))->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->post(route('api-tokens.store'), ['name' => 'ci', 'ability' => 'deploy'])
        ->assertRedirect(route('password.confirm'));

    expect($user->tokens()->count())->toBe(0);
});

it('lists the user\'s tokens without their values', function () {
    $user = User::factory()->create();
    $user->createToken('ci', ['read', 'deploy']);
    User::factory()->create()->createToken('someone else', ['read']);

    $this->actingAs($user)->get(route('api-tokens.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ApiTokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'ci')
            ->where('tokens.0.abilities', ['read', 'deploy'])
            ->where('tokens.0.last_used_at', null)
            ->missing('tokens.0.token'));
});

it('creates a token and shows the plaintext only once', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('api-tokens.store'), ['name' => 'GitLab CI', 'ability' => 'deploy'])
        ->assertRedirect(route('api-tokens.edit'));

    $token = $user->tokens()->sole();
    expect($token->name)->toBe('GitLab CI')->and($token->abilities)->toBe(['read', 'deploy']);

    $response->assertInertiaFlash('token.name', 'GitLab CI');
    $plain = session(SessionKey::FLASH_DATA)['token']['plain_text'];
    expect($plain)->toStartWith($token->id.'|')
        ->and(hash('sha256', explode('|', $plain, 2)[1]))->toBe($token->token);

    // Shown on the page that follows the creation...
    $this->get(route('api-tokens.edit'))->assertInertia(fn (Assert $page) => $page
        ->hasFlash('token.plain_text', $plain)
        ->where('tokens', fn ($tokens) => ! str_contains(json_encode($tokens), $plain)));

    // ...and never again.
    $this->get(route('api-tokens.edit'))->assertInertia(fn (Assert $page) => $page
        ->missingFlash('token'));
});

it('creates read-only tokens', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('api-tokens.store'), ['name' => 'monitor', 'ability' => 'read'])->assertRedirect();

    expect($user->tokens()->sole()->abilities)->toBe(['read']);
});

it('validates name and ability', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('api-tokens.store'), ['name' => '', 'ability' => 'admin'])
        ->assertSessionHasErrors(['name', 'ability']);
});

it('revokes a token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('ci', ['read'])->accessToken;

    $this->actingAs($user)->delete(route('api-tokens.destroy', $token->id))->assertRedirect(route('api-tokens.edit'));

    expect($user->tokens()->count())->toBe(0);
});

it('cannot revoke someone else\'s token', function () {
    $token = User::factory()->create()->createToken('ci', ['read'])->accessToken;

    $this->actingAs(User::factory()->create())->delete(route('api-tokens.destroy', $token->id))->assertNotFound();

    expect($token->fresh())->not->toBeNull();
});

it('requires authentication', function () {
    $this->get(route('api-tokens.edit'))->assertRedirect(route('login'));
    $this->post(route('api-tokens.store'), ['name' => 'x', 'ability' => 'read'])->assertRedirect(route('login'));
});
