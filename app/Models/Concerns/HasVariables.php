<?php

namespace App\Models\Concerns;

use App\Models\Variable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasVariables
{
    public static function bootHasVariables(): void
    {
        static::deleting(function ($model): void {
            $model->variables()->reorder()->delete();
        });
    }

    public function variables(): MorphMany
    {
        return $this->morphMany(Variable::class, 'owner')->orderBy('position');
    }
}
