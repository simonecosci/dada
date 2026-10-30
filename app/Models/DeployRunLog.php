<?php

namespace App\Models;

use Database\Factories\DeployRunLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeployRunLog extends Model
{
    /** @use HasFactory<DeployRunLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DeployRun::class, 'deploy_run_id');
    }

    /** @return array{id: int, deployment_host_id: int|null, level: string, message: string, created_at: string|null} */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'deployment_host_id' => $this->deployment_host_id,
            'level' => $this->level,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
