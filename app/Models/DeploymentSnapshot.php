<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * State actually written to a host by a successful deploy.
 * `installations`, `host_variables` and `files` contain secrets in clear text: encrypted at rest.
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
