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

    /** Ruling F2: il nome dell'utente resta nello storico anche dopo la sua cancellazione. */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->user_name === null && $model->user_id !== null) {
                $model->user_name = User::query()->whereKey($model->user_id)->value('name');
            }
        });
    }

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
