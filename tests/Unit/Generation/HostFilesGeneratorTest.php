<?php

use App\Generation\HostFilesGenerator;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Variables\VariableSync;

it('generates all files for a compose host', function () {
    $dh = hostWithWordpress();
    $g = app(HostFilesGenerator::class)->generate($dh);

    expect($g->ok())->toBeTrue()
        ->and(array_keys($g->files))->toEqualCanonicalizing(['docker-compose.yml', '.env', '.env.wp', 'wp.sh', 'restart.sh'])
        ->and($g->files['.env.wp'])->toContain("DB_PASSWORD='pw-123'")
        ->and($g->files['.env'])->toContain("WP_TAG='6'")
        ->and($g->masked()['.env.wp'])->toContain("DB_PASSWORD='******'")->not->toContain('pw-123');
});

it('uses raw env format for swarm hosts', function () {
    $dh = hostWithWordpress(['orchestrator' => 'swarm']);
    $g = app(HostFilesGenerator::class)->generate($dh);

    expect($g->ok())->toBeTrue()
        ->and($g->files['.env.wp'])->toContain("DB_PASSWORD=pw-123\n")
        ->and($g->files['.env'])->toContain("WP_TAG=6\n");
});

it('reports errors instead of throwing', function () {
    $dh = hostWithWordpress();
    Installation::factory()->for($dh)->create([
        'slug' => 'other', 'order' => 2,
        'compose' => ['services' => ['wp' => ['image' => 'nginx']]],
    ]);

    $g = app(HostFilesGenerator::class)->generate($dh);

    expect($g->ok())->toBeFalse()
        ->and($g->errors)->not->toBeEmpty()
        ->and($g->files)->toBe([]);
});

it('reports writer errors and missing required variables', function () {
    $dh = hostWithWordpress();
    $inst = $dh->installations()->first();
    VariableSync::sync($inst, [
        ['key' => 'BAD', 'scope' => 'container', 'default' => "a\nb"],
        ['key' => 'NEEDED', 'scope' => 'container', 'required' => true],
    ], allowHostGlobal: false);

    $g = app(HostFilesGenerator::class)->generate($dh);

    expect($g->errors)->not->toBeEmpty()
        ->and($g->files)->toBe([])
        ->and($g->missingRequired)->toBe(['wp: NEEDED'])
        ->and($g->ok())->toBeFalse();
});

it('masks secrets also in their escaped form, longest first', function () {
    $dh = hostWithWordpress();
    $inst = $dh->installations()->first();
    VariableSync::sync($inst, [
        ['key' => 'A', 'scope' => 'container', 'secret' => true, 'default' => 'it\'s "x"'],
        ['key' => 'B', 'scope' => 'container', 'secret' => true, 'default' => 'abc'],
        ['key' => 'C', 'scope' => 'container', 'secret' => true, 'default' => 'abcdef'],
    ], allowHostGlobal: false);

    $g = app(HostFilesGenerator::class)->generate($dh);
    $masked = $g->masked()['.env.wp'];

    expect($g->files['.env.wp'])->toContain('it\'s \\"x\\"')
        ->and($masked)->toContain('A="******"')->toContain("C='******'")->toContain("B='******'")
        ->not->toContain('abc')->not->toContain('x\\"');
});

it('does not generate an env file for installations without container variables', function () {
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'compose']);
    Installation::factory()->for($dh)->create(['slug' => 'plain']);

    $g = app(HostFilesGenerator::class)->generate($dh);

    expect($g->ok())->toBeTrue()
        ->and(array_keys($g->files))->toEqualCanonicalizing(['docker-compose.yml', '.env', 'plain.sh', 'restart.sh']);
});

it('reports a name clash between generated files', function () {
    $dh = hostWithWordpress();
    Installation::factory()->for($dh)->create([
        'slug' => 'restart', 'order' => 2,
        'compose' => ['services' => ['other' => ['image' => 'nginx']]],
    ]);

    $g = app(HostFilesGenerator::class)->generate($dh);

    expect($g->ok())->toBeFalse()
        ->and($g->files)->toBe([])
        ->and(implode(' ', $g->errors))->toContain('restart.sh');
});

it('turns a decrypt failure into an error without leaking details', function () {
    $dh = hostWithWordpress();
    $dh->installations()->first()->variables()->update(['default' => 'not-encrypted-garbage']);

    $g = app(HostFilesGenerator::class)->generate($dh->refresh());

    expect($g->ok())->toBeFalse()
        ->and($g->files)->toBe([])
        ->and($g->errors)->toBe(['unable to decrypt a secret variable']);
});

it('turns unexpected exceptions into a generic error', function () {
    $dh = hostWithWordpress();
    $dh->update(['orchestrator' => 'compose']);
    $dh->installations()->first()->update(['compose' => ['services' => 'boom']]);

    $g = app(HostFilesGenerator::class)->generate($dh->refresh());

    expect($g->ok())->toBeFalse()->and($g->files)->toBe([])->and($g->errors)->not->toBeEmpty();
});
