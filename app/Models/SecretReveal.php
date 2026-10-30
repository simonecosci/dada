<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecretReveal extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** Ruling F2: the user's name stays in the history even after the user is deleted. */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->user_name === null && $model->user_id !== null) {
                $model->user_name = User::query()->whereKey($model->user_id)->value('name');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }
}
