<?php

use App\Models\Credential;
use App\Models\Host;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function hostPayload(array $overrides = []): array
{
    return [
        'name' => 'web1', 'hostname' => 'web1.example.com', 'port' => 22, 'username' => 'deploy',
        'credential_id' => Credential::factory()->sshKey()->create()->id, 'jump_host_id' => null, 'sudo' => '',
        ...$overrides,
    ];
}

function hostAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('lists hosts without secrets', function () {
    Host::factory()->for(Credential::factory()->sshKey()->state(['secret' => 'PRIVATE-KEY-MATERIAL']))->create();

    $this->actingAs(hostAdmin())->get('/admin/hosts')->assertOk()->assertDontSee('PRIVATE-KEY-MATERIAL');
});

it('creates a host', function () {
    $this->actingAs(hostAdmin())->post('/admin/hosts', hostPayload())->assertRedirect('/admin/hosts');

    expect(Host::first())->hostname->toBe('web1.example.com')->port->toBe(22);
});

it('creates a host behind a jump host', function () {
    $jump = Host::factory()->create();

    $this->actingAs(hostAdmin())->post('/admin/hosts', hostPayload(['jump_host_id' => $jump->id]));

    expect(Host::where('name', 'web1')->first()->jump_host_id)->toBe($jump->id);
});

it('rejects a password host as jump host', function () {
    $pwd = Host::factory()->for(Credential::factory()->password())->create();

    $this->actingAs(hostAdmin())->post('/admin/hosts', hostPayload(['jump_host_id' => $pwd->id]))
        ->assertSessionHasErrors('jump_host_id');
});

it('rejects a jump cycle on update', function () {
    $a = Host::factory()->create();
    $b = Host::factory()->create(['jump_host_id' => $a->id]);

    $this->actingAs(hostAdmin())->put("/admin/hosts/{$a->id}", [
        'name' => $a->name, 'hostname' => $a->hostname, 'port' => 22, 'username' => 'deploy',
        'credential_id' => $a->credential_id, 'jump_host_id' => $b->id, 'sudo' => '',
    ])->assertSessionHasErrors('jump_host_id');
});

it('rejects a registry credential as ssh credential', function () {
    $registry = Credential::factory()->registry()->create();

    $this->actingAs(hostAdmin())->post('/admin/hosts', hostPayload(['credential_id' => $registry->id]))
        ->assertSessionHasErrors('credential_id');
});

it('updates a host', function () {
    $h = Host::factory()->create();

    $this->actingAs(hostAdmin())->put("/admin/hosts/{$h->id}", hostPayload(['name' => 'renamed', 'credential_id' => $h->credential_id]))
        ->assertRedirect('/admin/hosts');

    expect($h->fresh()->name)->toBe('renamed');
});

it('shows a host', function () {
    $h = Host::factory()->create();

    $this->actingAs(hostAdmin())->get("/admin/hosts/{$h->id}")->assertOk();
});

it('deletes a host', function () {
    $h = Host::factory()->create();

    $this->actingAs(hostAdmin())->delete("/admin/hosts/{$h->id}")->assertRedirect('/admin/hosts');

    expect(Host::count())->toBe(0);
});

it('blocks deleting a host used as jump host', function () {
    $a = Host::factory()->create();
    Host::factory()->create(['jump_host_id' => $a->id]);

    $this->actingAs(hostAdmin())->delete("/admin/hosts/{$a->id}")->assertSessionHasErrors('host');

    expect(Host::count())->toBe(2);
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create(['is_maintainer' => true]))->get('/admin/hosts')->assertForbidden();
});

it('applies the host policy', function () {
    $plain = User::factory()->create();
    $h = Host::factory()->create();

    expect(Gate::forUser($plain)->allows('viewAny', Host::class))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('view', $h))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('update', $h))->toBeFalse()
        ->and(Gate::forUser($plain)->allows('delete', $h))->toBeFalse()
        ->and(Gate::forUser($plain)->allows('create', Host::class))->toBeFalse();
});
