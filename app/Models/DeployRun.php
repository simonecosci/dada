<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\RunType;
use Database\Factories\DeployRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeployRun extends Model
{
    /** @use HasFactory<DeployRunFactory> */
    use HasFactory;

    /** `error` va sempre mascherato con DeployLogger::mask() prima del salvataggio. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => RunType::class,
            'status' => RunStatus::class,
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(DeployRunHost::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeployRunLog::class);
    }

    /** @param Builder<DeployRun> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value]);
    }
}
