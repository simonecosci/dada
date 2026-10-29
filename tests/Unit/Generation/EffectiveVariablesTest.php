<?php

use App\Generation\EffectiveVariables;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Variables\VariableSync;

it('lists missing required variables', function () {
    $inst = Installation::factory()->create(['slug' => 'wp']);
    VariableSync::sync($inst, [['key' => 'DB_HOST', 'scope' => 'container', 'required' => true]], allowHostGlobal: false);

    expect(EffectiveVariables::for($inst->deploymentHost)->missingRequired())->toBe(['wp: DB_HOST']);
});

it('lists missing required host variables with the host prefix', function () {
    $dh = DeploymentHost::factory()->create();
    VariableSync::sync($dh, [['key' => 'TOKEN', 'scope' => 'host-global', 'required' => true, 'use_default' => false, 'value' => '']]);

    expect(EffectiveVariables::for($dh)->missingRequired())->toBe(['host: TOKEN']);
});

it('uses default or value according to use_default and exposes container env', function () {
    $dh = DeploymentHost::factory()->create();
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp']);
    VariableSync::sync($inst, [
        ['key' => 'A', 'scope' => 'container', 'default' => 'd', 'value' => 'v', 'use_default' => true],
        ['key' => 'B', 'scope' => 'container', 'default' => 'd', 'value' => 'v', 'use_default' => false],
        ['key' => 'C', 'scope' => 'container'],
    ], allowHostGlobal: false);

    $vars = EffectiveVariables::for($dh);

    expect($vars->containerEnv($inst))->toBe(['A' => 'd', 'B' => 'v', 'C' => null])
        ->and($vars->dotEnv())->toBe([]);
});

it('collects host-global then compose variables into dotEnv', function () {
    $dh = DeploymentHost::factory()->create();
    VariableSync::sync($dh, [['key' => 'DOMAIN', 'scope' => 'host-global', 'default' => 'a.test']]);
    $one = Installation::factory()->for($dh)->create(['slug' => 'one', 'order' => 1]);
    $two = Installation::factory()->for($dh)->create(['slug' => 'two', 'order' => 2]);
    VariableSync::sync($one, [['key' => 'APP_PORT', 'scope' => 'compose', 'default' => '8080']], allowHostGlobal: false);
    VariableSync::sync($two, [
        ['key' => 'APP_PORT', 'scope' => 'compose', 'default' => '8080'],
        ['key' => 'OTHER', 'scope' => 'compose', 'default' => 'x'],
    ], allowHostGlobal: false);

    $vars = EffectiveVariables::for($dh);

    expect($vars->dotEnv())->toBe(['DOMAIN' => 'a.test', 'APP_PORT' => '8080', 'OTHER' => 'x'])
        ->and($vars->errors())->toBe([]);
});

it('errors on conflicting compose vars across installations', function () {
    $dh = DeploymentHost::factory()->create();
    $one = Installation::factory()->for($dh)->create(['slug' => 'one', 'order' => 1]);
    $two = Installation::factory()->for($dh)->create(['slug' => 'two', 'order' => 2]);
    VariableSync::sync($one, [['key' => 'APP_PORT', 'scope' => 'compose', 'default' => '8080']], allowHostGlobal: false);
    VariableSync::sync($two, [['key' => 'APP_PORT', 'scope' => 'compose', 'default' => '9090']], allowHostGlobal: false);

    $errors = EffectiveVariables::for($dh)->errors();

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('APP_PORT')->toContain('one')->toContain('two');
});

it('lets host-global win over a compose variable with a warning', function () {
    $dh = DeploymentHost::factory()->create();
    VariableSync::sync($dh, [['key' => 'APP_PORT', 'scope' => 'host-global', 'default' => '1']]);
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp']);
    VariableSync::sync($inst, [['key' => 'APP_PORT', 'scope' => 'compose', 'default' => '2']], allowHostGlobal: false);

    $vars = EffectiveVariables::for($dh);

    expect($vars->dotEnv())->toBe(['APP_PORT' => '1'])
        ->and($vars->errors())->toBe([])
        ->and($vars->warnings())->toHaveCount(1)
        ->and($vars->warnings()[0])->toContain('APP_PORT');
});

it('exposes only non-empty secret values', function () {
    $dh = DeploymentHost::factory()->create();
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp']);
    VariableSync::sync($inst, [
        ['key' => 'DB_PASSWORD', 'scope' => 'container', 'secret' => true, 'default' => 'pw-123'],
        ['key' => 'EMPTY_SECRET', 'scope' => 'container', 'secret' => true],
        ['key' => 'PLAIN', 'scope' => 'container', 'default' => 'visible'],
    ], allowHostGlobal: false);

    expect(EffectiveVariables::for($dh)->secretValues())->toBe(['pw-123']);
});
