<?php

use App\Deployments\InstallVersion;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Models\ProjectVersion;
use App\Models\User;
use App\Models\Variable;
use App\Variables\VariableSync;
use Illuminate\Validation\ValidationException;

it('installs a published version copying compose and variables', function () {
    $v = ProjectVersion::factory()->published()->create();
    VariableSync::sync($v, [['key' => 'WP_DEBUG', 'scope' => 'container', 'default' => 'false']]);
    $r = app(InstallVersion::class)->handle(DeploymentHost::factory()->create(), $v);
    expect($r->installation->slug)->toBe($v->project->slug)
        ->and($r->installation->compose)->toBe($v->compose)
        ->and($r->installation->variables()->first())->use_default->toBeTrue()->effectiveValue()->toBe('false');
});

it('copies secret defaults and leaves the value empty', function () {
    $v = ProjectVersion::factory()->published()->create();
    VariableSync::sync($v, [['key' => 'DB_PASSWORD', 'scope' => 'container', 'secret' => true, 'default' => 'pw']]);
    $r = app(InstallVersion::class)->handle(DeploymentHost::factory()->create(), $v);
    $var = $r->installation->variables()->first();
    expect($var->secret)->toBeTrue()->and($var->default)->toBe('pw')->and($var->value)->toBeNull();
});

it('allows the same project twice with distinct slugs', function () {
    $v = ProjectVersion::factory()->published()->create();
    $dh = DeploymentHost::factory()->create();
    app(InstallVersion::class)->handle($dh, $v, 'wp-a');
    $b = app(InstallVersion::class)->handle($dh, $v, 'wp-b');
    expect($b->installation->order)->toBe(2)
        ->and(fn () => app(InstallVersion::class)->handle($dh, $v, 'wp-a'))->toThrow(ValidationException::class);
});

it('refuses drafts', function () {
    expect(fn () => app(InstallVersion::class)->handle(DeploymentHost::factory()->create(), ProjectVersion::factory()->create()))
        ->toThrow(DomainException::class);
});

it('creates missing host-global vars and keeps existing ones with OR-ed flags', function () {
    $dh = DeploymentHost::factory()->create();
    VariableSync::sync($dh, [['key' => 'DOMAIN', 'scope' => 'host-global', 'default' => 'a.it']]);
    $v = ProjectVersion::factory()->published()->create();
    VariableSync::sync($v, [['key' => 'DOMAIN', 'scope' => 'host-global', 'default' => 'b.it', 'required' => true],
        ['key' => 'TZ', 'scope' => 'host-global', 'default' => 'Europe/Rome']]);
    $r = app(InstallVersion::class)->handle($dh, $v);
    expect($dh->variables()->where('key', 'DOMAIN')->first())->default->toBe('a.it')->required->toBeTrue()
        ->and($dh->variables()->where('key', 'TZ')->exists())->toBeTrue()
        ->and($r->installation->variables()->where('scope', 'host-global')->exists())->toBeFalse()
        ->and($r->warnings[0])->toContain('DOMAIN')->toContain('b.it');
});

it('lets an operator reveal an installation secret but not a viewer', function () {
    $inst = Installation::factory()->create();
    $d = $inst->deploymentHost->deployment;
    $var = Variable::factory()->secret()->for($inst, 'owner')->create(['default' => 'x']);
    $op = User::factory()->create();
    $vw = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);
    $d->members()->attach($vw, ['role' => 'viewer']);
    $this->actingAs($vw)->postJson("/variables/{$var->id}/reveal")->assertForbidden();
    $this->actingAs($op)->postJson("/variables/{$var->id}/reveal")->assertOk();
});

it('reveals the raw field requested', function () {
    $inst = Installation::factory()->create();
    $d = $inst->deploymentHost->deployment;
    $var = Variable::factory()->secret()->for($inst, 'owner')->create(['default' => 'dd', 'value' => 'vv', 'use_default' => true]);
    $op = User::factory()->create();
    $d->members()->attach($op, ['role' => 'operator']);
    $this->actingAs($op)->postJson("/variables/{$var->id}/reveal", ['field' => 'value'])->assertJson(['value' => 'vv']);
    $this->actingAs($op)->postJson("/variables/{$var->id}/reveal", ['field' => 'default'])->assertJson(['value' => 'dd']);
    $this->actingAs($op)->postJson("/variables/{$var->id}/reveal")->assertJson(['value' => 'dd']);
    $this->actingAs($op)->postJson("/variables/{$var->id}/reveal", ['field' => 'bogus'])->assertStatus(422);
});

it('lets an operator reveal a host-global secret', function () {
    $dh = DeploymentHost::factory()->create();
    $var = Variable::factory()->secret()->for($dh, 'owner')->create(['default' => 'x', 'scope' => 'host-global']);
    $op = User::factory()->create();
    $vw = User::factory()->create();
    $dh->deployment->members()->attach($op, ['role' => 'operator']);
    $dh->deployment->members()->attach($vw, ['role' => 'viewer']);
    $this->actingAs($vw)->postJson("/variables/{$var->id}/reveal")->assertForbidden();
    $this->actingAs($op)->postJson("/variables/{$var->id}/reveal")->assertOk();
});
