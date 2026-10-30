<?php

use App\Models\Credential;
use App\Models\Host;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function adminUser(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('never exposes credential secrets in responses', function () {
    Credential::factory()->sshKey()->create(['secret' => 'PRIVATE-KEY-MATERIAL']);
    $c = Credential::factory()->password()->create(['secret' => 'PASSWORD-MATERIAL']);
    $admin = adminUser();

    $this->actingAs($admin)->get('/admin/credentials')->assertOk()->assertDontSee('PRIVATE-KEY-MATERIAL');
    $this->actingAs($admin)->get("/admin/credentials/{$c->id}/edit")->assertOk()->assertDontSee('PASSWORD-MATERIAL');
});

it('stores credential secret encrypted', function () {
    Credential::factory()->password()->create(['secret' => 'p4ss']);

    expect(DB::table('credentials')->value('secret'))->not->toBe('p4ss')
        ->and(Credential::first()->secret)->toBe('p4ss');
});

it('hides secret and passphrase on serialization', function () {
    $c = Credential::factory()->password()->create(['secret' => 'p4ss']);

    expect($c->toArray())->not->toHaveKeys(['secret', 'passphrase']);
});

it('lets an admin create a credential', function () {
    $this->actingAs(adminUser())->post('/admin/credentials', [
        'name' => 'prod', 'type' => 'password', 'username' => 'deploy', 'secret' => 'hunter2',
    ])->assertRedirect('/admin/credentials');

    expect(Credential::first())->name->toBe('prod')->secret->toBe('hunter2');
});

it('requires a registry url for registry credentials', function () {
    $this->actingAs(adminUser())->post('/admin/credentials', [
        'name' => 'ghcr', 'type' => 'registry', 'username' => 'me', 'secret' => 'tok',
    ])->assertSessionHasErrors('registry_url');
});

it('rejects a passphrase', function () {
    $this->actingAs(adminUser())->post('/admin/credentials', [
        'name' => 'k', 'type' => 'ssh-key', 'secret' => 'KEY', 'passphrase' => 'abc',
    ])->assertSessionHasErrors(['passphrase' => 'keys with a passphrase are not supported in this version']);

    expect(Credential::count())->toBe(0);
});

it('keeps the secret when updating with an empty one', function () {
    $c = Credential::factory()->password()->create(['secret' => 'keep-me', 'name' => 'old']);

    $this->actingAs(adminUser())->put("/admin/credentials/{$c->id}", [
        'name' => 'new', 'type' => 'password', 'secret' => '',
    ])->assertRedirect('/admin/credentials');

    expect($c->fresh())->name->toBe('new')->secret->toBe('keep-me');
});

it('replaces the secret when updating with a new one', function () {
    $c = Credential::factory()->password()->create(['secret' => 'old']);

    $this->actingAs(adminUser())->put("/admin/credentials/{$c->id}", [
        'name' => $c->name, 'type' => 'password', 'secret' => 'new',
    ]);

    expect($c->fresh()->secret)->toBe('new');
});

it('deletes a credential', function () {
    $c = Credential::factory()->create();

    $this->actingAs(adminUser())->delete("/admin/credentials/{$c->id}")->assertRedirect('/admin/credentials');

    expect(Credential::count())->toBe(0);
});

it('blocks deleting a credential in use by a host', function () {
    $host = Host::factory()->create();

    $this->actingAs(adminUser())->delete("/admin/credentials/{$host->credential_id}")
        ->assertSessionHasErrors('credential');

    expect(Credential::count())->toBe(1);
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create(['is_maintainer' => true]))->get('/admin/credentials')->assertForbidden();
});

it('lets any authenticated user view but only admins manage (policy)', function () {
    $plain = User::factory()->create();
    $c = Credential::factory()->create();

    expect(Gate::forUser($plain)->allows('viewAny', Credential::class))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('view', $c))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('create', Credential::class))->toBeFalse()
        ->and(Gate::forUser($plain)->allows('update', $c))->toBeFalse()
        ->and(Gate::forUser($plain)->allows('delete', $c))->toBeFalse()
        ->and(Gate::forUser(adminUser())->allows('update', $c))->toBeTrue();
});
