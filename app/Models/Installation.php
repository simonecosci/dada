<?php

namespace App\Models;

use App\Models\Concerns\HasVariables;
use Database\Factories\InstallationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installation extends Model
{
    /** @use HasFactory<InstallationFactory> */
    use HasFactory, HasVariables;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'compose' => 'array',
            'commands' => 'array',
        ];
    }

    public function deploymentHost(): BelongsTo
    {
        return $this->belongsTo(DeploymentHost::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class, 'project_version_id');
    }
}
