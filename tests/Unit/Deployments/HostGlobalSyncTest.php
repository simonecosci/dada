<?php

use App\Deployments\HostGlobalSync;
use App\Deployments\InstallVersion;
use App\Models\DeploymentHost;
use App\Models\ProjectVersion;
use App\Variables\VariableSync;

it('does not leak secret defaults into warnings', function () {
    $dh = DeploymentHost::factory()->create();
    VariableSync::sync($dh, [['key' => 'TOKEN', 'scope' => 'host-global', 'default' => 'old-secret']]);
    $v = ProjectVersion::factory()->published()->create();
    VariableSync::sync($v, [['key' => 'TOKEN', 'scope' => 'host-global', 'secret' => true, 'default' => 'new-secret']]);
    $r = app(InstallVersion::class)->handle($dh, $v);
    expect($r->warnings)->toHaveCount(1)
        ->and($r->warnings[0])->toContain('TOKEN')->not->toContain('new-secret')->not->toContain('old-secret')
        ->and($dh->variables()->where('key', 'TOKEN')->first()->secret)->toBeTrue();
});

it('does not warn when defaults match', function () {
    $dh = DeploymentHost::factory()->create();
    VariableSync::sync($dh, [['key' => 'TZ', 'scope' => 'host-global', 'default' => 'UTC']]);
    $v = ProjectVersion::factory()->published()->create();
    VariableSync::sync($v, [['key' => 'TZ', 'scope' => 'host-global', 'default' => 'UTC']]);
    expect(app(InstallVersion::class)->handle($dh, $v)->warnings)->toBe([]);
});

it('reports host-global keys no installed version declares', function () {
    $dh = DeploymentHost::factory()->create();
    $v = ProjectVersion::factory()->published()->create();
    VariableSync::sync($v, [['key' => 'DOMAIN', 'scope' => 'host-global', 'default' => 'a.it']]);
    $inst = app(InstallVersion::class)->handle($dh, $v)->installation;
    VariableSync::sync($dh, [
        ...$dh->variables()->get()->map(fn ($x) => ['id' => $x->id, 'key' => $x->key, 'scope' => 'host-global'])->all(),
        ['key' => 'ORPHAN', 'scope' => 'host-global'],
    ]);

    expect(HostGlobalSync::unused($dh))->toBe(['ORPHAN']);

    $inst->delete();
    expect(HostGlobalSync::unused($dh))->toBe(['DOMAIN', 'ORPHAN'])
        ->and($dh->variables()->where('key', 'DOMAIN')->exists())->toBeTrue();
});
