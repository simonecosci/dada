<?php

use App\Enums\DeploymentRole;
use App\Models\Deployment;
use App\Models\User;

it('shows only deployments the user belongs to', function () {
    $u = User::factory()->create();
    $mine = Deployment::factory()->create();
    Deployment::factory()->create(['name' => 'Altrui']);
    $mine->members()->attach($u, ['role' => 'viewer']);
    $this->actingAs($u)->get('/deployments')->assertInertia(fn ($p) => $p->has('deployments', 1));
});

it('shows every deployment to admins', function () {
    Deployment::factory()->count(2)->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get('/deployments')->assertInertia(fn ($p) => $p->has('deployments', 2));
});

it('lets an operator add a viewer but not a viewer add anyone', function () {
    $d = Deployment::factory()->create();
    $op = User::factory()->create();
    $vw = User::factory()->create();
    $new = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);
    $d->members()->attach($vw, ['role' => 'viewer']);
    $this->actingAs($vw)->post(route('deployments.members.store', $d), ['user_id' => $new->id, 'role' => 'viewer'])->assertForbidden();
    $this->actingAs($op)->post(route('deployments.members.store', $d), ['user_id' => $new->id, 'role' => 'viewer'])->assertRedirect();
    expect($new->roleIn($d))->toBe(DeploymentRole::Viewer);
});

it('rejects adding an existing member and unknown roles', function () {
    $d = Deployment::factory()->create();
    $op = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);
    $other = User::factory()->create();

    $this->actingAs($op)->post(route('deployments.members.store', $d), ['user_id' => $op->id, 'role' => 'viewer'])
        ->assertSessionHasErrors('user_id');
    $this->actingAs($op)->post(route('deployments.members.store', $d), ['user_id' => $other->id, 'role' => 'admin'])
        ->assertSessionHasErrors('role');
});

it('hides deployments from non members', function () {
    $d = Deployment::factory()->create();
    $this->actingAs(User::factory()->create())->get(route('deployments.show', $d))->assertForbidden();
});

it('only admins create deployments and the creator becomes operator', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs(User::factory()->create())->post('/deployments', ['name' => 'X', 'slug' => 'x'])->assertForbidden();
    $this->actingAs($admin)->post('/deployments', ['name' => 'Cliente X', 'slug' => 'cliente-x'])->assertRedirect();
    expect($admin->roleIn(Deployment::whereSlug('cliente-x')->first()))->toBe(DeploymentRole::Operator);
});

it('validates the deployment slug', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->post('/deployments', ['name' => 'X', 'slug' => "bad\nslug"])->assertSessionHasErrors('slug');
    $this->actingAs($admin)->post('/deployments', ['name' => 'X', 'slug' => 'ok'])->assertRedirect();
    $this->actingAs($admin)->post('/deployments', ['name' => 'Y', 'slug' => 'ok'])->assertSessionHasErrors('slug');
});

it('lets operators update but not delete, and admins delete', function () {
    $d = Deployment::factory()->create();
    $op = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);

    $this->actingAs($op)->put(route('deployments.update', $d), ['name' => 'Nuovo', 'slug' => $d->slug])->assertRedirect();
    expect($d->fresh()->name)->toBe('Nuovo');
    $this->actingAs($op)->delete(route('deployments.destroy', $d))->assertForbidden();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->delete(route('deployments.destroy', $d))->assertRedirect();
    expect(Deployment::count())->toBe(0);
});

it('exposes the user picker only to those who manage members', function () {
    $d = Deployment::factory()->create();
    $op = User::factory()->create();
    $vw = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);
    $d->members()->attach($vw, ['role' => 'viewer']);
    User::factory()->create();

    $this->actingAs($op)->get(route('deployments.show', $d))
        ->assertInertia(fn ($p) => $p->where('canManageMembers', true)->has('candidates', 1)->has('members', 2));
    $this->actingAs($vw)->get(route('deployments.show', $d))
        ->assertInertia(fn ($p) => $p->where('canManageMembers', false)->where('candidates', []));
});

it('refuses to remove the last operator', function () {
    $d = Deployment::factory()->create();
    $op = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);
    $this->actingAs($op)->delete(route('deployments.members.destroy', [$d, $op]))->assertSessionHasErrors();
    expect($op->roleIn($d))->toBe(DeploymentRole::Operator);
});

it('refuses to demote the last operator but allows it with another operator', function () {
    $d = Deployment::factory()->create();
    $op = User::factory()->create();
    $op2 = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);

    $this->actingAs($op)->put(route('deployments.members.update', [$d, $op]), ['role' => 'viewer'])->assertSessionHasErrors();
    expect($op->roleIn($d))->toBe(DeploymentRole::Operator);

    $d->members()->attach($op2, ['role' => 'operator']);
    $this->actingAs($op)->put(route('deployments.members.update', [$d, $op]), ['role' => 'viewer'])->assertSessionHasNoErrors();
    expect($op->roleIn($d))->toBe(DeploymentRole::Viewer);
    $this->actingAs($op2)->delete(route('deployments.members.destroy', [$d, $op]))->assertRedirect();
    expect($op->roleIn($d))->toBeNull();
});

it('lets admins manage members without being members', function () {
    $d = Deployment::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $new = User::factory()->create();
    $this->actingAs($admin)->post(route('deployments.members.store', $d), ['user_id' => $new->id, 'role' => 'operator'])->assertRedirect();
    expect($new->roleIn($d))->toBe(DeploymentRole::Operator);
});

it('exposes view through the policy', function () {
    $d = Deployment::factory()->create();
    $vw = User::factory()->create();
    $d->members()->attach($vw, ['role' => 'viewer']);
    expect($vw->can('view', $d))->toBeTrue()
        ->and($vw->can('operate', $d))->toBeFalse()
        ->and(User::factory()->create()->can('view', $d))->toBeFalse();
});
