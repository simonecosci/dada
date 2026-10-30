<?php

use App\Jobs\HostCheckJob;
use App\Models\Host;
use App\Models\User;
use App\Ssh\HostKeyFingerprint;
use Illuminate\Support\Facades\Queue;

it('queues a host check', function () {
    Queue::fake();
    $host = Host::factory()->create();

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->from("/admin/hosts/{$host->id}")
        ->post("/admin/hosts/{$host->id}/check")
        ->assertRedirect("/admin/hosts/{$host->id}");

    Queue::assertPushed(HostCheckJob::class, fn (HostCheckJob $job) => $job->hostId === $host->id);
});

it('resets the host key', function () {
    $host = Host::factory()->create(['host_key' => 'dada-h1 ssh-ed25519 AAAA']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->from("/admin/hosts/{$host->id}")
        ->post("/admin/hosts/{$host->id}/reset-key")
        ->assertRedirect("/admin/hosts/{$host->id}");

    expect($host->fresh()->host_key)->toBeNull();
});

it('shows the host key fingerprint but not the key', function () {
    $line = 'dada-h1 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
    $host = Host::factory()->create(['host_key' => $line]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get("/admin/hosts/{$host->id}")
        ->assertInertia(fn ($p) => $p->component('admin/hosts/Show')
            ->where('host.has_host_key', true)
            ->where('host.host_key_fingerprints', [HostKeyFingerprint::sha256($line)])
            ->missing('host.host_key'));
});

it('forbids check and reset-key to non admins', function () {
    Queue::fake();
    $host = Host::factory()->create(['host_key' => 'k']);
    $user = User::factory()->create();

    $this->actingAs($user)->post("/admin/hosts/{$host->id}/check")->assertForbidden();
    $this->actingAs($user)->post("/admin/hosts/{$host->id}/reset-key")->assertForbidden();

    Queue::assertNothingPushed();
    expect($host->fresh()->host_key)->toBe('k');
});
