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
     * @param  Model  $owner  Un modello che usa il trait HasVariables
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

            // Libera le chiavi che cambiano, cos� scambi e catene di rinomine non violano l'unicit�.
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
                $errors["variables.$i.key"] = 'La chiave non è valida: usa lettere, cifre e underscore, senza iniziare con una cifra.';
            } elseif (isset($seen[strtolower($key)])) {
                $errors["variables.$i.key"] = "La chiave {$key} è duplicata.";
            } else {
                $seen[strtolower($key)] = true;
            }

            $scope = $row['scope'] ?? null;
            if (! is_string($scope) || ! in_array($scope, $scopes, true)) {
                $errors["variables.$i.scope"] = 'Lo scope non è valido.';
            } elseif ($scope === VariableScope::HostGlobal->value && ! $allowHostGlobal) {
                $errors["variables.$i.scope"] = 'Lo scope host-global non è ammesso qui.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
