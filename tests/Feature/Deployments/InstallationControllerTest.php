<?php

use App\Models\Credential;
use App\Models\Deployment;
use App\Models\DeploymentHost;
use App\Models\Host;
use App\Models\Installation;
use App\Models\ProjectVersion;
use App\Models\User;
use App\Models\Variable;
use App\Variables\VariableSync;
use Inertia\Testing\AssertableInertia as Assert;

function operatorFor(Deployment $d): User
{
    $u = User::factory()->create();
    $d->members()->attach($u, ['role' => 'operator']);

    return $u;
}

function viewerFor(Deployment $d): User
{
    $u = User::factory()->create();
    $d->members()->attach($u, ['role' => 'viewer']);

    return $u;
}

it('adds a host to a deployment with defaults from the slug', function () {
    $d = Deployment::factory()->create(['slug' => 'cliente-x']);
    $host = Host::factory()->create();
    $this->actingAs(operatorFor($d))->post(route('deployments.hosts.store', $d), [
        'host_id' => $host->id, 'path' => '/srv/cliente-x', 'orchestrator' => 'compose',
    ])->assertRedirect();
    $dh = DeploymentHost::first();
    expect($dh->stack_name)->toBe('cliente-x')->and($dh->deployment_id)->toBe($d->id)->and($dh->compose_template)->toBe([]);
});

it('forbids viewers and non members from managing hosts', function () {
    $d = Deployment::factory()->create();
    $host = Host::factory()->create();
    $payload = ['host_id' => $host->id, 'path' => '/srv/x', 'orchestrator' => 'compose'];
    $this->actingAs(viewerFor($d))->post(route('deployments.hosts.store', $d), $payload)->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('deployments.hosts.store', $d), $payload)->assertForbidden();
    expect(DeploymentHost::count())->toBe(0);
});

it('rejects unsafe paths', function (string $path) {
    $d = Deployment::factory()->create();
    $host = Host::factory()->create();
    $this->actingAs(operatorFor($d))->post(route('deployments.hosts.store', $d), [
        'host_id' => $host->id, 'path' => $path, 'orchestrator' => 'compose',
    ])->assertSessionHasErrors('path');
})->with(['relative' => 'srv/x', 'dotdot' => '/srv/../etc', 'newline' => "/srv/x\ny", 'space' => '/srv/a b', 'semicolon' => '/srv/x;rm', 'root' => '/']);

it('rejects a duplicate path on the same host and bad stack names', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create(['path' => '/srv/a']);
    $op = operatorFor($d);
    $this->actingAs($op)->post(route('deployments.hosts.store', $d), [
        'host_id' => $dh->host_id, 'path' => '/srv/a', 'orchestrator' => 'compose',
    ])->assertSessionHasErrors('path');
    $this->actingAs($op)->post(route('deployments.hosts.store', $d), [
        'host_id' => Host::factory()->create()->id, 'path' => '/srv/b', 'orchestrator' => 'compose', 'stack_name' => "bad\nname",
    ])->assertSessionHasErrors('stack_name');
});

it('updates settings and a restricted compose template', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    $op = operatorFor($d);
    $this->actingAs($op)->put(route('deployments.hosts.update', [$d, $dh]), [
        'path' => '/srv/new', 'orchestrator' => 'swarm', 'stack_name' => 'stack-1',
        'compose_template' => "volumes:\n  data: {}\nnetworks:\n  front: {}\n",
    ])->assertRedirect();
    $dh->refresh();
    expect($dh->path)->toBe('/srv/new')->and($dh->orchestrator->value)->toBe('swarm')
        ->and($dh->compose_template)->toHaveKeys(['volumes', 'networks']);

    foreach (["services:\n  a:\n    image: x\n", "- volumes\n", str_repeat('a', 262145)] as $bad) {
        $this->actingAs($op)->put(route('deployments.hosts.update', [$d, $dh]), [
            'path' => '/srv/new', 'orchestrator' => 'swarm', 'stack_name' => 'stack-1', 'compose_template' => $bad,
        ])->assertSessionHasErrors('compose_template');
    }
});

it('does not reach hosts of another deployment', function () {
    $d = Deployment::factory()->create();
    $other = DeploymentHost::factory()->create();
    $this->actingAs(operatorFor($d))->get(route('deployments.hosts.show', [$d, $other]))->assertNotFound();
});

it('shows a host without exposing secrets and lists unused host-global keys', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    VariableSync::sync($dh, [['key' => 'TOKEN', 'scope' => 'host-global', 'secret' => true, 'default' => 'topsecret'], ['key' => 'ORPHAN', 'scope' => 'host-global']]);
    $this->actingAs(operatorFor($d))->get(route('deployments.hosts.show', [$d, $dh]))
        ->assertInertia(fn (Assert $p) => $p->component('deployments/hosts/Show')
            ->where('canReveal', true)->where('unused', ['TOKEN', 'ORPHAN'])
            ->where('variables.0.default', null)->has('variables', 2));
    $this->actingAs(User::factory()->create())->get(route('deployments.hosts.show', [$d, $dh]))->assertForbidden();
});

it('updates host-global variables', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    $this->actingAs(operatorFor($d))->put(route('deployments.hosts.variables.update', [$d, $dh]), [
        'variables' => [['key' => 'DOMAIN', 'scope' => 'host-global', 'default' => 'a.it']],
    ])->assertRedirect();
    expect($dh->variables()->first()->default)->toBe('a.it');
});

it('forces host variables to host-global scope', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    $this->actingAs(operatorFor($d))->put(route('deployments.hosts.variables.update', [$d, $dh]), [
        'variables' => [['key' => 'DOMAIN', 'scope' => 'container', 'default' => 'a.it']],
    ])->assertSessionHasErrors('variables.0.scope');
});

it('syncs registries accepting only registry credentials', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    $reg = Credential::factory()->registry()->create();
    $ssh = Credential::factory()->sshKey()->create();
    $op = operatorFor($d);
    $this->actingAs($op)->put(route('deployments.hosts.registries.update', [$d, $dh]), ['credential_ids' => [$ssh->id]])
        ->assertSessionHasErrors('credential_ids.0');
    $this->actingAs($op)->put(route('deployments.hosts.registries.update', [$d, $dh]), ['credential_ids' => [$reg->id]])->assertRedirect();
    expect($dh->registries()->pluck('credentials.id')->all())->toBe([$reg->id]);
    $this->actingAs($op)->put(route('deployments.hosts.registries.update', [$d, $dh]), ['credential_ids' => []])->assertRedirect();
    expect($dh->registries()->count())->toBe(0);
});

it('installs a version through the controller and flashes warnings', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    VariableSync::sync($dh, [['key' => 'DOMAIN', 'scope' => 'host-global', 'default' => 'a.it']]);
    $v = ProjectVersion::factory()->published()->create();
    VariableSync::sync($v, [['key' => 'DOMAIN', 'scope' => 'host-global', 'default' => 'b.it']]);
    $op = operatorFor($d);
    $this->actingAs($op)->get(route('hosts.installations.create', $dh))
        ->assertInertia(fn (Assert $p) => $p->component('deployments/installations/Create')->has('projects', 1));
    $this->actingAs($op)->post(route('hosts.installations.store', $dh), ['project_version_id' => $v->id, 'slug' => 'wp'])
        ->assertRedirect()->assertSessionHas('inertia.flash_data');
    expect($dh->installations()->first()->slug)->toBe('wp');
});

it('refuses to install drafts and duplicate slugs', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    $op = operatorFor($d);
    $draft = ProjectVersion::factory()->create();
    $this->actingAs($op)->post(route('hosts.installations.store', $dh), ['project_version_id' => $draft->id])
        ->assertSessionHasErrors('project_version_id');
    $v = ProjectVersion::factory()->published()->create();
    $this->actingAs($op)->post(route('hosts.installations.store', $dh), ['project_version_id' => $v->id, 'slug' => 'a'])->assertSessionHasNoErrors();
    $this->actingAs($op)->post(route('hosts.installations.store', $dh), ['project_version_id' => $v->id, 'slug' => 'a'])
        ->assertSessionHasErrors('slug');
    $this->actingAs($op)->post(route('hosts.installations.store', $dh), ['project_version_id' => $v->id, 'slug' => "bad\nslug"])
        ->assertSessionHasErrors('slug');
    $this->actingAs(viewerFor($d))->post(route('hosts.installations.store', $dh), ['project_version_id' => $v->id])->assertForbidden();
});

it('edits and saves an installation', function () {
    $inst = Installation::factory()->create();
    $d = $inst->deploymentHost->deployment;
    $var = Variable::factory()->secret()->for($inst, 'owner')->create(['key' => 'DB_PASSWORD', 'default' => 'dd']);
    $op = operatorFor($d);
    $this->actingAs($op)->get(route('installations.edit', $inst))
        ->assertInertia(fn (Assert $p) => $p->component('deployments/installations/Edit')
            ->where('variables.0.default', null)->where('canReveal', true));
    $this->actingAs($op)->put(route('installations.update', $inst), [
        'slug' => 'wp2', 'order' => 3,
        'compose_yaml' => "services:\n  wp:\n    image: wordpress:6\n",
        'commands' => [['name' => 'flush', 'target' => 'service:wp', 'command' => 'wp cache flush', 'dangerous' => false]],
        'variables' => [['id' => $var->id, 'key' => 'DB_PASSWORD', 'scope' => 'container', 'secret' => true, 'use_default' => false, 'value' => 'mine']],
    ])->assertRedirect();
    $inst->refresh();
    $var->refresh();
    expect($inst->slug)->toBe('wp2')->and($inst->order)->toBe(3)->and($inst->compose['services'])->toHaveKey('wp')
        ->and($var->value)->toBe('mine')->and($var->default)->toBe('dd')->and($var->use_default)->toBeFalse();
});

it('validates installation updates like the catalog', function () {
    $inst = Installation::factory()->create();
    $op = operatorFor($inst->deploymentHost->deployment);
    $base = ['slug' => 'wp', 'order' => 1, 'compose_yaml' => "services:\n  wp:\n    image: x\n", 'commands' => [], 'variables' => []];
    $url = route('installations.update', $inst);
    $this->actingAs($op)->put($url, [...$base, 'compose_yaml' => "services:\n  - a\n"])->assertSessionHasErrors('compose_yaml');
    $this->actingAs($op)->put($url, [...$base, 'compose_yaml' => str_repeat('a', 262145)])->assertSessionHasErrors('compose_yaml');
    $this->actingAs($op)->put($url, [...$base, 'commands' => [['name' => 'x', 'target' => 'service:nope', 'command' => 'ls', 'dangerous' => false]]])
        ->assertSessionHasErrors('commands');
    $this->actingAs($op)->put($url, [...$base, 'slug' => "a\nb"])->assertSessionHasErrors('slug');
    $this->actingAs($op)->put($url, [...$base, 'variables' => [['key' => 'X', 'scope' => 'host-global']]])
        ->assertSessionHasErrors('variables.0.id');
});

it('rejects duplicate installation slugs on the same host on update', function () {
    $a = Installation::factory()->create(['slug' => 'a']);
    $b = Installation::factory()->create(['slug' => 'b', 'deployment_host_id' => $a->deployment_host_id]);
    $this->actingAs(operatorFor($a->deploymentHost->deployment))->put(route('installations.update', $b), [
        'slug' => 'a', 'order' => 1, 'compose_yaml' => "services:\n  wp:\n    image: x\n", 'commands' => [], 'variables' => [],
    ])->assertSessionHasErrors('slug');
});

it('forbids viewers on installation destroy', function () {
    $inst = Installation::factory()->create();
    $vw = viewerFor($inst->deploymentHost->deployment);
    $this->actingAs($vw)->delete(route('installations.destroy', $inst))->assertForbidden();
    expect(Installation::count())->toBe(1);
});

it('destroys an installation with its variables but keeps host-global ones', function () {
    $inst = Installation::factory()->create();
    $dh = $inst->deploymentHost;
    Variable::factory()->for($inst, 'owner')->create();
    Variable::factory()->for($dh, 'owner')->create(['scope' => 'host-global']);
    $this->actingAs(operatorFor($dh->deployment))->delete(route('installations.destroy', $inst))->assertRedirect();
    expect(Installation::count())->toBe(0)->and(Variable::where('owner_type', Installation::class)->count())->toBe(0)
        ->and($dh->variables()->count())->toBe(1);
});

it('reorders installations', function () {
    $a = Installation::factory()->create(['slug' => 'a', 'order' => 1]);
    $b = Installation::factory()->create(['slug' => 'b', 'order' => 2, 'deployment_host_id' => $a->deployment_host_id]);
    $dh = $a->deploymentHost;
    $this->actingAs(operatorFor($dh->deployment))->put(route('hosts.installations.reorder', $dh), ['ids' => [$b->id, $a->id]])->assertRedirect();
    expect($dh->installations()->pluck('slug')->all())->toBe(['b', 'a']);
});

it('deletes a deployment host with installations and variables', function () {
    $inst = Installation::factory()->create();
    $dh = $inst->deploymentHost;
    $d = $dh->deployment;
    Variable::factory()->for($inst, 'owner')->create();
    Variable::factory()->for($dh, 'owner')->create(['scope' => 'host-global']);
    $this->actingAs(operatorFor($d))->delete(route('deployments.hosts.destroy', [$d, $dh]))->assertRedirect();
    expect(DeploymentHost::count())->toBe(0)->and(Installation::count())->toBe(0)->and(Variable::count())->toBe(0);
});

it('deletes a deployment together with hosts, installations and variables', function () {
    $inst = Installation::factory()->create();
    $d = $inst->deploymentHost->deployment;
    Variable::factory()->for($inst, 'owner')->create();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->delete(route('deployments.destroy', $d))->assertRedirect();
    expect(Deployment::count())->toBe(0)->and(DeploymentHost::count())->toBe(0)->and(Installation::count())->toBe(0)->and(Variable::count())->toBe(0);
});

it('returns a validation error instead of a 500 when deleting a version with installations', function () {
    $v = ProjectVersion::factory()->create();
    Installation::factory()->create(['project_version_id' => $v->id]);
    $m = User::factory()->create(['is_maintainer' => true]);
    $this->actingAs($m)->delete(route('versions.destroy', $v))->assertSessionHasErrors('version');
    expect(ProjectVersion::find($v->id))->not->toBeNull();
});

it('refuses to delete a registered host or registry credential still in use', function () {
    $dh = DeploymentHost::factory()->create();
    $reg = Credential::factory()->registry()->create();
    $dh->registries()->attach($reg);
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->delete(route('admin.hosts.destroy', $dh->host))->assertSessionHasErrors('host');
    $this->actingAs($admin)->delete(route('admin.credentials.destroy', $reg))->assertSessionHasErrors('credential');
    expect(Host::find($dh->host_id))->not->toBeNull()->and(Credential::find($reg->id))->not->toBeNull();
});

it('lists registered hosts to pick from without sensitive fields', function () {
    $d = Deployment::factory()->create();
    Host::factory()->create(['name' => 'srv1']);
    $this->actingAs(operatorFor($d))->get(route('deployments.hosts.create', $d))
        ->assertInertia(fn (Assert $p) => $p->component('deployments/hosts/Form')
            ->has('hosts', 1, fn (Assert $h) => $h->hasAll(['id', 'name', 'hostname'])));
});

it('ignores structural variable fields in installation updates', function () {
    $inst = Installation::factory()->create();
    $op = operatorFor($inst->deploymentHost->deployment);
    $secret = Variable::factory()->secret()->for($inst, 'owner')->create(['key' => 'DB_PASSWORD', 'default' => 'dd', 'required' => true]);
    $plain = Variable::factory()->for($inst, 'owner')->create(['key' => 'WP_DEBUG', 'default' => 'false', 'position' => 1]);
    $base = ['slug' => 'wp', 'order' => 1, 'compose_yaml' => "services:\n  wp:\n    image: x\n", 'commands' => []];

    $this->actingAs($op)->put(route('installations.update', $inst), [...$base, 'variables' => [
        ['id' => $secret->id, 'key' => 'RENAMED', 'scope' => 'compose', 'secret' => false, 'required' => false, 'default' => 'hacked', 'use_default' => false, 'value' => 'v1'],
        ['id' => $plain->id, 'key' => 'WP_DEBUG', 'scope' => 'container', 'default' => 'true', 'use_default' => false, 'value' => 'v2'],
    ]])->assertSessionHasNoErrors();

    $secret->refresh();
    $plain->refresh();
    expect($secret->key)->toBe('DB_PASSWORD')->and($secret->secret)->toBeTrue()->and($secret->required)->toBeTrue()
        ->and($secret->scope->value)->toBe('container')->and($secret->default)->toBe('dd')
        ->and($secret->value)->toBe('v1')->and($secret->use_default)->toBeFalse()
        ->and($plain->default)->toBe('false')->and($plain->value)->toBe('v2');

    // un segreto senza la chiave value resta invariato
    $this->actingAs($op)->put(route('installations.update', $inst), [...$base, 'variables' => [
        ['id' => $secret->id, 'use_default' => false],
    ]])->assertSessionHasNoErrors();
    expect($secret->fresh()->value)->toBe('v1');
});

it('rejects new or unknown variables in installation updates and never drops existing ones', function () {
    $inst = Installation::factory()->create();
    $other = Installation::factory()->create();
    $op = operatorFor($inst->deploymentHost->deployment);
    Variable::factory()->for($inst, 'owner')->create(['key' => 'KEEP']);
    $foreign = Variable::factory()->for($other, 'owner')->create();
    $base = ['slug' => 'wp', 'order' => 1, 'compose_yaml' => "services:\n  wp:\n    image: x\n", 'commands' => []];

    $this->actingAs($op)->put(route('installations.update', $inst), [...$base, 'variables' => [['key' => 'NEW', 'scope' => 'container']]])
        ->assertSessionHasErrors('variables.0.id');
    $this->actingAs($op)->put(route('installations.update', $inst), [...$base, 'variables' => [['id' => $foreign->id, 'value' => 'x']]])
        ->assertSessionHasErrors('variables.0.id');
    $this->actingAs($op)->put(route('installations.update', $inst), [...$base, 'variables' => []])->assertSessionHasNoErrors();
    expect($inst->variables()->count())->toBe(1)->and($foreign->fresh()->value)->toBeNull();
});

it('lets viewers see a host read-only with masked secrets', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    VariableSync::sync($dh, [['key' => 'TOKEN', 'scope' => 'host-global', 'secret' => true, 'default' => 'topsecret']]);
    $vw = viewerFor($d);
    $res = $this->actingAs($vw)->get(route('deployments.hosts.show', [$d, $dh]));
    $res->assertInertia(fn (Assert $p) => $p->component('deployments/hosts/Show')
        ->where('canOperate', false)->where('canReveal', false)->where('variables.0.default', null));
    expect($res->getContent())->not->toContain('topsecret');
    $this->actingAs($vw)->put(route('deployments.hosts.variables.update', [$d, $dh]), ['variables' => []])->assertForbidden();
    $this->actingAs($vw)->get(route('deployments.show', $d))
        ->assertInertia(fn (Assert $p) => $p->has('hosts', 1)->where('canOperate', false));
});

it('lets viewers open an installation read-only', function () {
    $inst = Installation::factory()->create();
    $vw = viewerFor($inst->deploymentHost->deployment);
    Variable::factory()->secret()->for($inst, 'owner')->create(['default' => 'hiddenvalue']);
    $res = $this->actingAs($vw)->get(route('installations.edit', $inst));
    $res->assertInertia(fn (Assert $p) => $p->where('canOperate', false)->where('canReveal', false)->where('variables.0.default', null));
    expect($res->getContent())->not->toContain('hiddenvalue');
    $this->actingAs(User::factory()->create())->get(route('installations.edit', $inst))->assertForbidden();
});

it('rejects a stack name already used on the same host', function () {
    $d = Deployment::factory()->create();
    $a = DeploymentHost::factory()->for($d)->create(['stack_name' => 'shared']);
    $d2 = Deployment::factory()->create(['slug' => 'shared']);
    $op = operatorFor($d2);
    $this->actingAs($op)->post(route('deployments.hosts.store', $d2), ['host_id' => $a->host_id, 'path' => '/srv/other', 'orchestrator' => 'compose'])
        ->assertSessionHasErrors('stack_name');
    $this->actingAs($op)->post(route('deployments.hosts.store', $d2), ['host_id' => Host::factory()->create()->id, 'path' => '/srv/other', 'orchestrator' => 'compose'])
        ->assertSessionHasNoErrors();
    $b = DeploymentHost::factory()->for($d2)->create(['host_id' => $a->host_id, 'stack_name' => 'mine']);
    $this->actingAs($op)->put(route('deployments.hosts.update', [$d2, $b]), ['path' => $b->path, 'orchestrator' => 'compose', 'stack_name' => 'shared'])
        ->assertSessionHasErrors('stack_name');
});

it('reserves the restart slug on install and update', function () {
    $d = Deployment::factory()->create();
    $dh = DeploymentHost::factory()->for($d)->create();
    $op = operatorFor($d);
    $v = ProjectVersion::factory()->published()->create();

    $this->actingAs($op)->post(route('hosts.installations.store', $dh), ['project_version_id' => $v->id, 'slug' => 'restart'])
        ->assertSessionHasErrors(['slug' => 'lo slug "restart" è riservato']);
    expect($dh->installations()->count())->toBe(0);

    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp']);
    $this->actingAs($op)->put(route('installations.update', $inst), [
        'slug' => 'restart', 'order' => 1, 'compose_yaml' => "services:\n  wp:\n    image: x\n", 'commands' => [], 'variables' => [],
    ])->assertSessionHasErrors(['slug' => 'lo slug "restart" è riservato']);
    expect($inst->refresh()->slug)->toBe('wp');
});
