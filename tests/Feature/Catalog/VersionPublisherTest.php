<?php

use App\Catalog\VersionPublisher;
use App\Enums\VersionStatus;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\User;

it('publishes a draft version', function () {
    $v = ProjectVersion::factory()->create();
    (new VersionPublisher)->publish($v);

    $v->refresh();
    expect($v->isPublished())->toBeTrue()
        ->and($v->status)->toBe(VersionStatus::Published)
        ->and($v->published_at)->not->toBeNull();
});

it('refuses to publish twice', function () {
    $v = ProjectVersion::factory()->published()->create();
    expect(fn () => (new VersionPublisher)->publish($v))->toThrow(DomainException::class);
});

it('forbids editing a published version', function () {
    $v = ProjectVersion::factory()->published()->create();
    $v->compose = ['services' => ['x' => ['image' => 'y']]];
    expect(fn () => $v->save())->toThrow(DomainException::class);

    $v->refresh();
    $v->commands = [['name' => 'a']];
    expect(fn () => $v->save())->toThrow(DomainException::class);

    $v->refresh();
    $v->version = '9.9.9';
    expect(fn () => $v->save())->toThrow(DomainException::class);
});

it('allows editing a draft and notes of a published version', function () {
    $d = ProjectVersion::factory()->create();
    $d->compose = ['services' => ['x' => ['image' => 'y']]];
    $d->save();
    expect($d->fresh()->compose['services'])->toHaveKey('x');

    $p = ProjectVersion::factory()->published()->create();
    $p->notes = 'changelog';
    $p->save();
    expect($p->fresh()->notes)->toBe('changelog');
});

it('enforces unique version per project', function () {
    $project = Project::factory()->create();
    ProjectVersion::factory()->for($project)->create(['version' => '1.0.0']);
    expect(fn () => ProjectVersion::factory()->for($project)->create(['version' => '1.0.0']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('restricts project management to maintainers', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $maintainer = User::factory()->create(['is_maintainer' => true]);

    expect($user->can('view', $project))->toBeTrue()->and($user->can('viewAny', Project::class))->toBeTrue();
    foreach (['update', 'delete', 'publish', 'clone'] as $a) {
        expect($user->can($a, $project))->toBeFalse()->and($maintainer->can($a, $project))->toBeTrue();
    }
    expect($user->can('create', Project::class))->toBeFalse()
        ->and($maintainer->can('create', Project::class))->toBeTrue();
});
