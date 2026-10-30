<?php

namespace App\Models;

use App\Enums\RunStatus;
use Database\Factories\DeployRunHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeployRunHost extends Model
{
    /** @use HasFactory<DeployRunHostFactory> */
    use HasFactory;

    public $timestamps = false;

    /** `error` va sempre mascherato con DeployLogger::mask() prima del salvataggio. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DeployRun::class, 'deploy_run_id');
    }

    public function deploymentHost(): BelongsTo
    {
        return $this->belongsTo(DeploymentHost::class);
    }
}
