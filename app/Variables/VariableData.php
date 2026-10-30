<?php

namespace App\Variables;

use App\Models\Variable;

class VariableData
{
    /** @return array<string, mixed> */
    public static function forClient(Variable $v): array
    {
        return [
            'id' => $v->id,
            'key' => $v->key,
            'description' => $v->description,
            'required' => $v->required,
            'secret' => $v->secret,
            'scope' => $v->scope->value,
            'use_default' => $v->use_default,
            'default' => $v->secret ? null : $v->default,
            'value' => $v->secret ? null : $v->value,
            'has_default' => $v->default !== null,
            'has_value' => $v->value !== null,
        ];
    }
}
