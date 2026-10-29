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
