<?php

use App\Models\SecretReveal;
use App\Models\User;

it('masks preview for viewers and audits reveal for operators', function () {
    $dh = hostWithWordpress();
    $d = $dh->deployment;
    $vw = User::factory()->create();
    $op = User::factory()->create();
    $d->members()->attach($vw, ['role' => 'viewer']);
    $d->members()->attach($op, ['role' => 'operator']);

    $this->actingAs($vw)->getJson("/deployment-hosts/{$dh->id}/preview?reveal=1")->assertOk()->assertDontSee('pw-123');
    expect(SecretReveal::count())->toBe(0);

    $this->actingAs($op)->getJson("/deployment-hosts/{$dh->id}/preview")->assertOk()->assertDontSee('pw-123');
    expect(SecretReveal::count())->toBe(0);

    $this->actingAs($op)->getJson("/deployment-hosts/{$dh->id}/preview?reveal=1")->assertSee('pw-123');
    expect(SecretReveal::where('key', "preview:{$dh->id}")->count())->toBe(1)
        ->and(SecretReveal::first()->variable_id)->toBeNull();
});

it('returns files, errors, warnings and missing required', function () {
    $dh = hostWithWordpress();
    $vw = User::factory()->create();
    $dh->deployment->members()->attach($vw, ['role' => 'viewer']);

    $this->actingAs($vw)->getJson("/deployment-hosts/{$dh->id}/preview")
        ->assertOk()
        ->assertJsonStructure(['files' => ['docker-compose.yml', '.env', '.env.wp', 'wp.sh', 'restart.sh'], 'errors', 'warnings', 'missingRequired']);
});

it('forbids non members', function () {
    $dh = hostWithWordpress();
    $this->actingAs(User::factory()->create())->getJson("/deployment-hosts/{$dh->id}/preview")->assertForbidden();
});
