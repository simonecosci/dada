<?php

use App\Models\ProjectVersion;
use App\Models\Variable;
use App\Variables\VariableData;
use App\Variables\VariableSync;
use Illuminate\Validation\ValidationException;

it('keeps a secret value when the row omits it', function () {
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [['key' => 'DB_PASSWORD', 'secret' => true, 'scope' => 'container', 'default' => 's3cr3t', 'required' => true]]);
    $id = $v->variables()->first()->id;
    VariableSync::sync($v, [['id' => $id, 'key' => 'DB_PASSWORD', 'secret' => true, 'scope' => 'container', 'required' => true]]);
    expect($v->variables()->first()->default)->toBe('s3cr3t');
});

it('deletes rows not present and validates keys', function () {
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [['key' => 'A', 'scope' => 'compose'], ['key' => 'B', 'scope' => 'compose']]);
    VariableSync::sync($v, [['id' => $v->variables()->where('key', 'A')->first()->id, 'key' => 'A', 'scope' => 'compose']]);
    expect($v->variables()->pluck('key')->all())->toBe(['A']);
    expect(fn () => VariableSync::sync($v, [['key' => '1BAD', 'scope' => 'compose']]))->toThrow(ValidationException::class);
});

it('rejects trailing newline keys, duplicates and host-global when disallowed', function () {
    $v = ProjectVersion::factory()->create();
    expect(fn () => VariableSync::sync($v, [['key' => "A\n", 'scope' => 'compose']]))->toThrow(ValidationException::class);
    expect(fn () => VariableSync::sync($v, [['key' => 'A', 'scope' => 'compose'], ['key' => 'A', 'scope' => 'compose']]))->toThrow(ValidationException::class);
    try {
        VariableSync::sync($v, [['key' => 'A', 'scope' => 'host-global']], false);
        $this->fail('expected validation failure');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('variables.0.scope');
    }
    VariableSync::sync($v, [['key' => 'A', 'scope' => 'host-global']]);
    expect($v->variables()->count())->toBe(1);
});

it('sets position from row index', function () {
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [['key' => 'Z', 'scope' => 'compose'], ['key' => 'A', 'scope' => 'compose']]);
    expect($v->variables()->pluck('key')->all())->toBe(['Z', 'A']);
});

it('masks secrets for clients', function () {
    $var = Variable::factory()->secret()->create(['default' => 'x']);
    expect(VariableData::forClient($var))->toMatchArray(['default' => null, 'has_default' => true, 'has_value' => false]);
});

it('exposes non-secret values for clients', function () {
    $var = Variable::factory()->create(['default' => 'x']);
    expect(VariableData::forClient($var))->toMatchArray(['default' => 'x', 'has_default' => true]);
});

it('swaps and chains key renames within one payload', function () {
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [
        ['key' => 'A', 'scope' => 'compose', 'default' => 'a'],
        ['key' => 'B', 'scope' => 'compose', 'default' => 'b'],
        ['key' => 'C', 'scope' => 'compose', 'default' => 'c'],
    ]);
    $ids = $v->variables()->pluck('id', 'key');
    VariableSync::sync($v, [
        ['id' => $ids['A'], 'key' => 'B', 'scope' => 'compose', 'default' => 'a'],
        ['id' => $ids['B'], 'key' => 'A', 'scope' => 'compose', 'default' => 'b'],
        ['id' => $ids['C'], 'key' => 'D', 'scope' => 'compose', 'default' => 'c'],
    ]);
    $now = $v->variables()->get();
    expect($now->pluck('key')->all())->toBe(['B', 'A', 'D']);
    expect($now->pluck('id')->all())->toBe([$ids['A'], $ids['B'], $ids['C']]);
    expect($now->pluck('default')->all())->toBe(['a', 'b', 'c']);
});

it('rejects keys differing only by case', function () {
    $v = ProjectVersion::factory()->create();
    expect(fn () => VariableSync::sync($v, [['key' => 'Abc', 'scope' => 'compose'], ['key' => 'aBC', 'scope' => 'compose']]))
        ->toThrow(ValidationException::class);
});

it('deletes variables when the owner is deleted', function () {
    $v = ProjectVersion::factory()->create();
    VariableSync::sync($v, [['key' => 'A', 'scope' => 'compose']]);
    $v->delete();
    expect(Variable::count())->toBe(0);
});
