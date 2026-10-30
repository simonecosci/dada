<?php

use App\Generation\BuiltCompose;
use App\Generation\ComposeBuilder;
use App\Generation\EffectiveVariables;
use App\Generation\GenerationFailed;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Variables\VariableSync;

function buildFor(DeploymentHost $dh): BuiltCompose
{
    return ComposeBuilder::build($dh, $dh->installations, EffectiveVariables::for($dh));
}

it('builds compose with env_file, init profile and stack name', function () {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'compose', 'stack_name' => 'client-x']);
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => [
        'wp' => ['image' => 'wordpress:6'],
        'migrate' => ['image' => 'wordpress:6', 'x-dada' => ['init' => true]],
    ]]]);
    VariableSync::sync($inst, [['key' => 'WP_DEBUG', 'scope' => 'container']], allowHostGlobal: false);

    $b = ComposeBuilder::build($dh, $dh->installations, EffectiveVariables::for($dh));

    expect($b->compose['name'])->toBe('client-x')
        ->and($b->compose['services']['wp']['env_file'])->toBe(['.env.wp'])
        ->and($b->compose['services']['migrate']['profiles'])->toBe(['init'])
        ->and($b->compose['services']['migrate'])->not->toHaveKey('x-dada')
        ->and($b->initServices)->toBe([])
        ->and($b->servicesBySlug)->toBe(['wp' => ['wp', 'migrate']]);
});

it('does not add env_file without container variables and normalizes an existing one', function () {
    $dh = DeploymentHost::factory()->create();
    $a = Installation::factory()->for($dh)->create(['slug' => 'a', 'order' => 1, 'compose' => ['services' => [
        'svc-a' => ['image' => 'x', 'env_file' => 'common.env'],
    ]]]);
    Installation::factory()->for($dh)->create(['slug' => 'b', 'order' => 2, 'compose' => ['services' => [
        'svc-b' => ['image' => 'x'],
    ]]]);
    VariableSync::sync($a, [['key' => 'K', 'scope' => 'container']], allowHostGlobal: false);

    $b = buildFor($dh);

    expect($b->compose['services']['svc-a']['env_file'])->toBe(['common.env', '.env.a'])
        ->and($b->compose['services']['svc-b'])->not->toHaveKey('env_file');
});

it('moves init services out of the stack in swarm and makes default network attachable', function () {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm', 'stack_name' => 'client-x']);
    Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => [
        'wp' => ['image' => 'wordpress:6'],
        'migrate' => ['image' => 'wordpress:6', 'x-dada' => ['init' => true]],
    ]]]);

    $b = buildFor($dh);

    expect($b->compose)->not->toHaveKey('name')
        ->and($b->compose['services'])->not->toHaveKey('migrate')
        ->and($b->compose['services'])->toHaveKey('wp')
        ->and($b->initServices['wp'])->toHaveKey('migrate')
        ->and($b->initServices['wp']['migrate'])->not->toHaveKey('x-dada')
        ->and($b->compose['networks']['default']['attachable'])->toBeTrue()
        ->and($b->compose['networks']['default']['driver'])->toBe('overlay');
});

it('leaves networks alone in swarm without init services', function () {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm']);
    Installation::factory()->for($dh)->create(['slug' => 'wp']);

    expect(buildFor($dh)->compose)->not->toHaveKey('networks');
});

it('collects all structural errors', function () {
    $dh = DeploymentHost::factory()->create();
    Installation::factory()->for($dh)->create(['slug' => 'one', 'order' => 1, 'compose' => ['services' => [
        'db' => ['image' => 'mysql:8'],
    ]]]);
    Installation::factory()->for($dh)->create(['slug' => 'two', 'order' => 2, 'compose' => ['services' => [
        'db' => ['image' => 'mariadb'],
        'app' => ['image' => 'x', 'networks' => ['back'], 'volumes' => ['data:/var/lib/app']],
    ]]]);

    try {
        buildFor($dh);
        $this->fail('GenerationFailed not thrown');
    } catch (GenerationFailed $e) {
        expect($e->errors)->toHaveCount(3)
            ->and($e->errors[0])->toContain('"db"')->toContain('one')->toContain('two')
            ->and($e->errors[1])->toContain('back')
            ->and($e->errors[2])->toContain('data');
    }
});

it('detects used networks and named volumes in all syntaxes', function () {
    $dh = DeploymentHost::factory()->create();
    Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => [
        'ok' => ['image' => 'x', 'networks' => ['default'], 'volumes' => ['/data', './rel:/a', '/abs:/b', '~/h:/c', 'named-ro:/d:ro']],
        'long' => ['image' => 'x', 'networks' => ['n1' => ['aliases' => ['a']]], 'volumes' => [
            ['type' => 'bind', 'source' => 'not-a-volume', 'target' => '/x'],
            ['type' => 'volume', 'source' => 'long-vol', 'target' => '/y'],
        ]],
    ]]]);

    try {
        buildFor($dh);
        $this->fail('GenerationFailed not thrown');
    } catch (GenerationFailed $e) {
        $all = implode("\n", $e->errors);
        expect($e->errors)->toHaveCount(3)
            ->and($all)->toContain('named-ro')->toContain('n1')->toContain('long-vol')
            ->and($all)->not->toContain('not-a-volume')
            ->and($all)->not->toContain('rel');
    }
});

it('merges declarations, normalizes empty ones to null and flags conflicting definitions', function () {
    $dh = DeploymentHost::factory()->create(['compose_template' => [
        'volumes' => ['shared' => []],
        'networks' => ['back' => null],
    ]]);
    Installation::factory()->for($dh)->create(['slug' => 'one', 'order' => 1, 'compose' => [
        'services' => ['a' => ['image' => 'x', 'networks' => ['back'], 'volumes' => ['shared:/s', 'va:/v']]],
        'volumes' => ['va' => [], 'shared' => null],
    ]]);
    $two = Installation::factory()->for($dh)->create(['slug' => 'two', 'order' => 2, 'compose' => [
        'services' => ['b' => ['image' => 'x']],
        'volumes' => ['va' => []],
    ]]);

    $b = buildFor($dh);

    expect($b->compose['volumes'])->toBe(['shared' => null, 'va' => null])
        ->and($b->compose['networks'])->toBe(['back' => null]);

    $two->update(['compose' => [
        'services' => ['b' => ['image' => 'x']],
        'volumes' => ['va' => ['driver' => 'local']],
    ]]);
    $dh->unsetRelation('installations');

    expect(fn () => buildFor($dh))->toThrow(GenerationFailed::class, 'va');
});

it('does not validate volume sources that contain variable interpolation', function () {
    $dh = DeploymentHost::factory()->create();
    Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => [
        'a' => ['image' => 'x', 'volumes' => [
            '${DATA_DIR:-./data}:/data',
            '${WP_DATA}:/var/www',
            ['type' => 'volume', 'source' => '${LONG_VOL}', 'target' => '/z'],
        ]],
    ]]]);

    expect(buildFor($dh)->compose['services'])->toHaveKey('a');
});

it('forces attachable on networks used by swarm inits', function () {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm']);
    Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => [
        'services' => [
            'wp' => ['image' => 'x', 'networks' => ['front', 'back']],
            'migrate' => ['image' => 'x', 'networks' => ['back'], 'x-dada' => ['init' => true]],
        ],
        'networks' => ['back' => null, 'front' => ['driver' => 'overlay']],
    ]]);

    $b = buildFor($dh);

    expect($b->compose['networks']['back'])->toBe(['attachable' => true])
        ->and($b->compose['networks']['front'])->toBe(['driver' => 'overlay'])
        ->and($b->compose['networks']['default'])->toBe(['driver' => 'overlay', 'attachable' => true]);
});

it('rejects external or non overlay networks used by swarm inits', function (array $network, string $message) {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm']);
    Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => [
        'services' => [
            'wp' => ['image' => 'x'],
            'migrate' => ['image' => 'x', 'networks' => ['back'], 'x-dada' => ['init' => true]],
        ],
        'networks' => ['back' => $network],
    ]]);

    expect(fn () => buildFor($dh))->toThrow(GenerationFailed::class, $message);
})->with([
    'external' => [['external' => true], 'network "back" used by init migrate: cannot be external in swarm'],
    'bridge' => [['driver' => 'bridge'], 'network "back" used by init migrate: swarm requires the overlay driver'],
]);

it('rejects shell reserved .env keys in swarm only', function (string $key) {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm']);
    VariableSync::sync($dh, [['key' => $key, 'scope' => 'host-global', 'default' => 'x']]);
    Installation::factory()->for($dh)->create(['slug' => 'wp']);

    expect(fn () => buildFor($dh->fresh()))->toThrow(GenerationFailed::class, "{$key}: reserved shell name");

    $dh->update(['orchestrator' => 'compose']);
    expect(buildFor($dh->fresh())->compose)->toHaveKey('services');
})->with(['PATH', 'IFS', 'UID', 'SHELLOPTS', 'BASH_ENV', 'LD_PRELOAD', 'DOCKER_HOST', 'HOME', 'PS4']);

it('accepts ordinary .env keys in swarm', function () {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm']);
    VariableSync::sync($dh, [['key' => 'MY_PATH', 'scope' => 'host-global', 'default' => 'x'], ['key' => 'BASH', 'scope' => 'host-global', 'default' => 'x']]);
    Installation::factory()->for($dh)->create(['slug' => 'wp']);

    expect(buildFor($dh->fresh())->compose)->toHaveKey('services');
});
