<?php

namespace App\Models;

use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    protected $guarded = [];

    public function hosts(): HasMany
    {
        return $this->hasMany(DeploymentHost::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DeployRun::class);
    }

    /** The queued or running run (at most one, see RunStarter). */
    public function activeRun(): HasOne
    {
        return $this->hasOne(DeployRun::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q->active());
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @param Builder<Deployment> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->whereIn('deployments.id', $user->deployments()->select('deployments.id'));
    }
}
