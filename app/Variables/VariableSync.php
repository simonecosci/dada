<?php

namespace App\Variables;

use App\Enums\VariableScope;
use App\Models\Variable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VariableSync
{
    /**
     * @param  Model  $owner  A model that uses the HasVariables trait
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function sync(Model $owner, array $rows, bool $allowHostGlobal = true): void
    {
        $rows = array_values($rows);
        self::validate($rows, $allowHostGlobal);

        DB::transaction(function () use ($owner, $rows): void {
            $existing = $owner->variables()->reorder()->get()->keyBy('id');

            $keep = [];
            foreach ($rows as $row) {
                if (isset($row['id']) && $existing->has($row['id'])) {
                    $keep[] = (int) $row['id'];
                }
            }
            $owner->variables()->reorder()->whereNotIn('id', $keep)->delete();

            // Frees the keys that change, so swaps and rename chains do not violate uniqueness.
            foreach ($rows as $row) {
                $current = isset($row['id']) ? $existing->get($row['id']) : null;
                if ($current !== null && $current->key !== $row['key']) {
                    Variable::query()->whereKey($current->id)->update(['key' => '__dada_tmp_'.$current->id]);
                }
            }

            foreach ($rows as $i => $row) {
                $variable = isset($row['id']) ? $existing->get($row['id']) : null;
                $variable ??= new Variable;
                $isNew = ! $variable->exists;
                $secret = (bool) ($row['secret'] ?? false);

                $variable->fill([
                    'key' => $row['key'],
                    'description' => $row['description'] ?? null,
                    'required' => (bool) ($row['required'] ?? false),
                    'secret' => $secret,
                    'scope' => $row['scope'],
                    'use_default' => (bool) ($row['use_default'] ?? true),
                    'position' => $i,
                ]);

                foreach (['default', 'value'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $variable->{$field} = $row[$field] === '' ? null : $row[$field];
                    } elseif ($isNew || ! $secret) {
                        $variable->{$field} = null;
                    }
                }

                $variable->owner()->associate($owner);
                $variable->save();
            }
        });
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function validate(array $rows, bool $allowHostGlobal): void
    {
        $errors = [];
        $seen = [];
        $scopes = array_map(fn (VariableScope $s) => $s->value, VariableScope::cases());

        foreach ($rows as $i => $row) {
            $key = $row['key'] ?? null;
            if (! is_string($key) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
                $errors["variables.$i.key"] = 'The key is not valid: use letters, digits and underscores, without starting with a digit.';
            } elseif (isset($seen[strtolower($key)])) {
                $errors["variables.$i.key"] = "The key {$key} is duplicated.";
            } else {
                $seen[strtolower($key)] = true;
            }

            $scope = $row['scope'] ?? null;
            if (! is_string($scope) || ! in_array($scope, $scopes, true)) {
                $errors["variables.$i.scope"] = 'The scope is not valid.';
            } elseif ($scope === VariableScope::HostGlobal->value && ! $allowHostGlobal) {
                $errors["variables.$i.scope"] = 'The host-global scope is not allowed here.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
