<?php

namespace App\Models;

use App\Enums\Orchestrator;
use App\Models\Concerns\HasVariables;
use Database\Factories\DeploymentHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeploymentHost extends Model
{
    /** @use HasFactory<DeploymentHostFactory> */
    use HasFactory, HasVariables;

    protected $guarded = [];

    protected $attributes = [
        'compose_template' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'orchestrator' => Orchestrator::class,
            'compose_template' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Passa da Eloquent, così l'hook di HasVariables rimuove anche le variabili delle installazioni.
        static::deleting(function (self $dh): void {
            $dh->installations()->reorder()->get()->each->delete();
            $dh->registries()->detach();
        });
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class)->orderBy('order')->orderBy('id');
    }

    public function registries(): BelongsToMany
    {
        return $this->belongsToMany(Credential::class, 'deployment_host_registry');
    }
}
