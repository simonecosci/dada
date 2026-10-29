<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('computes roles', function () {
    $maintainer = User::factory()->create(['is_maintainer' => true]);
    $admin = User::factory()->create(['is_admin' => true]);
    $plain = User::factory()->create();

    expect($maintainer->isMaintainer())->toBeTrue()->and($maintainer->isAdmin())->toBeFalse()
        ->and($admin->isMaintainer())->toBeTrue()
        ->and($plain->isMaintainer())->toBeFalse()->and($plain->isAdmin())->toBeFalse();
});

it('defines the admin and horizon gates', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $plain = User::factory()->create();

    expect(Gate::forUser($admin)->allows('admin'))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('admin'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('viewHorizon'))->toBeFalse();
});

it('lets an admin create a user with maintainer flag', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Mario', 'email' => 'mario@example.com', 'password' => 'secret-password-123',
        'is_admin' => false, 'is_maintainer' => true,
    ])->assertRedirect('/admin/users');
    expect(User::whereEmail('mario@example.com')->first()->isMaintainer())->toBeTrue();
});

it('forbids non admins from user management', function () {
    $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
    $this->actingAs(User::factory()->create(['is_maintainer' => true]))->get('/admin/users')->assertForbidden();
});

it('lists, shows create and edit pages to admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();

    $this->actingAs($admin)->get('/admin/users')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/users/Index')->has('users', 2));
    $this->actingAs($admin)->get('/admin/users/create')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/users/Form'));
    $this->actingAs($admin)->get("/admin/users/{$other->id}/edit")->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/users/Form')->where('user.id', $other->id));
});

it('updates a user and keeps the password when blank', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $u = User::factory()->create();
    $hash = $u->password;

    $this->actingAs($admin)->put("/admin/users/{$u->id}", [
        'name' => 'Nuovo', 'email' => $u->email, 'password' => '',
        'is_admin' => false, 'is_maintainer' => true,
    ])->assertRedirect('/admin/users');

    $u->refresh();
    expect($u->name)->toBe('Nuovo')->and($u->isMaintainer())->toBeTrue()->and($u->password)->toBe($hash);
});

it('does not let an admin remove own admin flag', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
        'name' => $admin->name, 'email' => $admin->email, 'is_admin' => false, 'is_maintainer' => false,
    ])->assertSessionHasErrors('is_admin');
    expect($admin->fresh()->isAdmin())->toBeTrue();
});

it('does not let an admin delete self but can delete others', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();

    $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHasErrors('user');
    expect(User::find($admin->id))->not->toBeNull();

    $this->actingAs($admin)->delete("/admin/users/{$other->id}")->assertRedirect('/admin/users');
    expect(User::find($other->id))->toBeNull();
});

it('validates unique email on create', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Dup', 'email' => $admin->email, 'password' => 'secret-password-123',
    ])->assertSessionHasErrors('email');
});

it('shares is_admin with the frontend', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('auth.user.is_admin', true));
});
