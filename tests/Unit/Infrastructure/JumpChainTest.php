<?php

use App\Infrastructure\JumpChain;
use App\Models\Credential;
use App\Models\Host;

it('orders the jump chain from first hop to target', function () {
    $a = Host::factory()->create();
    $b = Host::factory()->create(['jump_host_id' => $a->id]);
    $c = Host::factory()->create(['jump_host_id' => $b->id]);

    expect(collect(JumpChain::for($c))->pluck('id')->all())->toBe([$a->id, $b->id, $c->id]);
});

it('throws on a cycle when building the chain', function () {
    $a = Host::factory()->create();
    $b = Host::factory()->create(['jump_host_id' => $a->id]);
    $a->update(['jump_host_id' => $b->id]);

    JumpChain::for($a);
})->throws(InvalidArgumentException::class);

it('rejects a cycle', function () {
    $a = Host::factory()->create();
    $b = Host::factory()->create(['jump_host_id' => $a->id]);

    expect(JumpChain::validate($a, $b->id))->toContain('cycle');
});

it('rejects a host as its own jump host', function () {
    $a = Host::factory()->create();

    expect(JumpChain::validate($a, $a->id))->toContain('cycle');
});

it('accepts no jump host and a valid ssh-key jump host', function () {
    $a = Host::factory()->create();

    expect(JumpChain::validate(Host::factory()->make(), null))->toBeNull()
        ->and(JumpChain::validate(Host::factory()->make(), $a->id))->toBeNull();
});

it('rejects a password host as jump host', function () {
    $pwd = Host::factory()->for(Credential::factory()->password())->create();

    expect(JumpChain::validate(Host::factory()->make(), $pwd->id))->toContain('ssh-key');
});

it('rejects a password host further up the chain', function () {
    $pwd = Host::factory()->for(Credential::factory()->password())->create();
    $mid = Host::factory()->create(['jump_host_id' => $pwd->id]);

    expect(JumpChain::validate(Host::factory()->make(), $mid->id))->toContain('ssh-key');
});

it('rejects a registry credential for ssh', function () {
    $registry = Credential::factory()->registry()->create();

    expect(JumpChain::validate(Host::factory()->make(['credential_id' => $registry->id]), null))->toContain('registry');
});
