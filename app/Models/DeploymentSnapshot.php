<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stato effettivamente scritto su un host da un deploy riuscito.
 * `installations`, `host_variables` e `files` contengono segreti in chiaro: cifrati a riposo.
 */
class DeploymentSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'installations' => 'encrypted:array',
            'host_variables' => 'encrypted:array',
            'files' => 'encrypted:array',
            'created_at' => 'datetime',
        ];
    }

    public function deploymentHost(): BelongsTo
    {
        return $this->belongsTo(DeploymentHost::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DeployRun::class, 'deploy_run_id');
    }
}
