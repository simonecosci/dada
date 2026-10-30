<?php

namespace App\Models;

use App\Enums\VariableScope;
use Database\Factories\VariableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Variable extends Model
{
    /** @use HasFactory<VariableFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default' => 'encrypted',
            'value' => 'encrypted',
            'use_default' => 'boolean',
            'required' => 'boolean',
            'secret' => 'boolean',
            'scope' => VariableScope::class,
            'position' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function effectiveValue(): ?string
    {
        return $this->use_default ? $this->default : $this->value;
    }
}
