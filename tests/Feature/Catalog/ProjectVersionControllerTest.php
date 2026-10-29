<?php

use App\Enums\VersionStatus;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\User;
use App\Models\Variable;
use App\Variables\VariableSync;
use Inertia\Testing\AssertableInertia as Assert;

function maintainer(): User
{
    return User::factory()->create(['is_maintainer' => true]);
}

it('rejects invalid yaml on update with field errors', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    $this->actingAs($m)->put(route('versions.update', $v), [
        'version' => $v->version, 'compose_yaml' => "services:\n  - web\n", 'commands' => [], 'variables' => [],
    ])->assertSessionHasErrors('compose_yaml');
});

it('caps compose_yaml at 256 KB', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    $this->actingAs($m)->put(route('versions.update', $v), [
        'version' => $v->version, 'compose_yaml' => str_repeat('a', 262145), 'commands' => [], 'variables' => [],
    ])->assertSessionHasErrors('compose_yaml');
});

it('saves compose, commands and variables of a draft', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    $this->actingAs($m)->put(route('versions.update', $v), [
        'version' => '1.0', 'compose_yaml' => "services:\n  wp:\n    image: wordpress:6\n",
        'commands' => [['name' => 'flush', 'target' => 'service:wp', 'command' => 'wp cache flush', 'dangerous' => false]],
        'variables' => [['key' => 'WP_DEBUG', 'scope' => 'container', 'default' => 'false']],
    ])->assertRedirect();
    expect($v->fresh()->compose['services'])->toHaveKey('wp')->and($v->variables()->count())->toBe(1);
});

it('rejects commands targeting a missing service', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    $this->actingAs($m)->put(route('versions.update', $v), [
        'version' => '1.0', 'compose_yaml' => "services:\n  wp:\n    image: wordpress:6\n",
        'commands' => [['name' => 'x', 'target' => 'service:nope', 'command' => 'ls', 'dangerous' => false]],
        'variables' => [],
    ])->assertSessionHasErrors('commands');
});

it('refuses to update a published version', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->published()->create();
    $r = $this->actingAs($m)->put(route('versions.update', $v), [
        'version' => '9.9', 'compose_yaml' => "services:\n  wp:\n    image: wordpress:6\n", 'commands' => [], 'variables' => [],
    ]);
    $r->assertForbidden();
    expect($v->fresh()->version)->not->toBe('9.9');
});

it('keeps an untouched secret when the row omits default', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [['key' => 'K', 'scope' => 'container', 'secret' => true, 'default' => 'TOPSECRET']]);
    $id = $v->variables()->first()->id;
    $this->actingAs($m)->put(route('versions.update', $v), [
        'version' => '1.0', 'compose_yaml' => "services:\n  wp:\n    image: wordpress:6\n", 'commands' => [],
        'variables' => [['id' => $id, 'key' => 'K', 'scope' => 'container', 'secret' => true]],
    ])->assertRedirect();
    expect($v->variables()->first()->default)->toBe('TOPSECRET');
});

it('clones and publishes', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    $this->actingAs($m)->post(route('versions.publish', $v))->assertRedirect();
    $this->actingAs($m)->post(route('versions.clone', $v), ['version' => '2.0'])->assertRedirect();
    expect($v->project->versions()->where('version', '2.0')->first()->status)->toBe(VersionStatus::Draft);
});

it('does not publish a draft with an invalid stored compose', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create(['compose' => ['services' => []]]);
    $this->actingAs($m)->post(route('versions.publish', $v))->assertSessionHasErrors();
    expect($v->fresh()->status)->toBe(VersionStatus::Draft);
});

it('returns a validation error when cloning to an existing version', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create(['version' => '1.0']);
    ProjectVersion::factory()->create(['project_id' => $v->project_id, 'version' => '2.0']);
    $this->actingAs($m)->post(route('versions.clone', $v), ['version' => '2.0'])->assertSessionHasErrors('version');
});

it('creates an empty draft version for a project', function () {
    $m = maintainer();
    $p = Project::factory()->create();
    $this->actingAs($m)->post(route('projects.versions.store', $p), ['version' => '0.1'])->assertRedirect();
    $v = $p->versions()->first();
    expect($v->status)->toBe(VersionStatus::Draft)->and($v->compose['services'])->toHaveKey('app');
});

it('deletes only draft versions', function () {
    $m = maintainer();
    $d = ProjectVersion::factory()->create();
    $p = ProjectVersion::factory()->published()->create();
    $this->actingAs($m)->delete(route('versions.destroy', $p))->assertSessionHasErrors();
    expect($p->fresh())->not->toBeNull();
    $this->actingAs($m)->delete(route('versions.destroy', $d))->assertRedirect();
    expect($d->fresh())->toBeNull();
});

it('forbids non maintainers from writing but lets them read', function () {
    $u = User::factory()->create();
    $v = ProjectVersion::factory()->create();
    $this->actingAs($u)->get(route('projects.index'))->assertOk();
    $this->actingAs($u)->get(route('projects.show', $v->project))->assertOk();
    $this->actingAs($u)->post(route('versions.publish', $v))->assertForbidden();
    $this->actingAs($u)->post(route('projects.store'), ['name' => 'A', 'slug' => 'a'])->assertForbidden();
});

it('creates, updates and deletes projects', function () {
    $m = maintainer();
    $this->actingAs($m)->post(route('projects.store'), ['name' => 'WP', 'slug' => 'wp'])->assertRedirect();
    $p = Project::where('slug', 'wp')->firstOrFail();
    $this->actingAs($m)->post(route('projects.store'), ['name' => 'X', 'slug' => 'Bad Slug'])->assertSessionHasErrors('slug');
    $this->actingAs($m)->put(route('projects.update', $p), ['name' => 'WP2', 'slug' => 'wp'])->assertRedirect();
    expect($p->fresh()->name)->toBe('WP2');
    $this->actingAs($m)->delete(route('projects.destroy', $p))->assertRedirect();
    expect(Project::count())->toBe(0);
});

it('hides secret defaults from the edit page props', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [['key' => 'K', 'scope' => 'container', 'secret' => true, 'default' => 'TOPSECRET']]);
    $this->actingAs($m)->get(route('versions.edit', $v))->assertDontSee('TOPSECRET')
        ->assertInertia(fn (Assert $page) => $page->component('catalog/versions/Edit')
            ->where('variables.0.default', null)->where('variables.0.has_default', true));
});

it('deletes a project with draft versions and their variables', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [['key' => 'K', 'scope' => 'container', 'secret' => true, 'default' => 'x']]);
    $this->actingAs($m)->delete(route('projects.destroy', $v->project))->assertRedirect();
    expect(Project::count())->toBe(0)->and(ProjectVersion::count())->toBe(0)->and(Variable::count())->toBe(0);
});

it('refuses to delete a project with a published version', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->published()->create();
    $this->actingAs($m)->delete(route('projects.destroy', $v->project))->assertSessionHasErrors('project');
    expect(Project::count())->toBe(1)->and(ProjectVersion::count())->toBe(1);
});

it('keeps variable ids stable across consecutive saves', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    $payload = fn (array $vars) => [
        'version' => '1.0', 'compose_yaml' => "services:\n  wp:\n    image: wordpress:6\n", 'commands' => [], 'variables' => $vars,
    ];
    $this->actingAs($m)->put(route('versions.update', $v), $payload([['key' => 'A', 'scope' => 'container', 'default' => '1']]))->assertRedirect();
    $rows = null;
    $this->actingAs($m)->get(route('versions.edit', $v))
        ->assertInertia(function (Assert $page) use (&$rows) {
            $rows = $page->toArray()['props']['variables'];
        });
    $id = $rows[0]['id'];
    $this->actingAs($m)->put(route('versions.update', $v), $payload([['id' => $id, 'key' => 'A', 'scope' => 'container', 'default' => '2']]))->assertRedirect();
    expect($v->variables()->pluck('id')->all())->toBe([$id])->and($v->variables()->first()->default)->toBe('2');
});

it('forbids non maintainers on every write route', function () {
    $u = User::factory()->create();
    $v = ProjectVersion::factory()->create();
    $this->actingAs($u)->put(route('versions.update', $v), [])->assertForbidden();
    $this->actingAs($u)->post(route('versions.clone', $v), ['version' => '2.0'])->assertForbidden();
    $this->actingAs($u)->delete(route('versions.destroy', $v))->assertForbidden();
    $this->actingAs($u)->post(route('projects.versions.store', $v->project), ['version' => '3.0'])->assertForbidden();
});

it('leaves compose unchanged when variable sync fails', function () {
    $m = maintainer();
    $v = ProjectVersion::factory()->create();
    $before = $v->compose;
    $this->actingAs($m)->put(route('versions.update', $v), [
        'version' => '1.0', 'compose_yaml' => "services:\n  wp:\n    image: wordpress:6\n", 'commands' => [],
        'variables' => [['key' => 'bad key', 'scope' => 'container']],
    ])->assertSessionHasErrors('variables.0.key');
    expect($v->fresh()->compose)->toBe($before);
});
