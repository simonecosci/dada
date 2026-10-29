<?php

namespace App\Models\Concerns;

use App\Models\Variable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasVariables
{
    public function variables(): MorphMany
    {
        return $this->morphMany(Variable::class, 'owner')->orderBy('position');
    }
}
