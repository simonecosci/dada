<?php

namespace App\Variables;

class VariableRules
{
    /**
     * Regole di validazione della richiesta per il payload `variables` (chiave e scope sono poi verificati da VariableSync).
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'variables' => ['present', 'array'],
            'variables.*' => ['array'],
            'variables.*.id' => ['nullable', 'integer'],
            'variables.*.key' => ['nullable', 'string', 'max:255'],
            'variables.*.scope' => ['nullable', 'string', 'max:50'],
            'variables.*.description' => ['nullable', 'string', 'max:1000'],
            'variables.*.required' => ['nullable', 'boolean'],
            'variables.*.secret' => ['nullable', 'boolean'],
            'variables.*.use_default' => ['nullable', 'boolean'],
            'variables.*.default' => ['nullable', 'string', 'max:65535'],
            'variables.*.value' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
