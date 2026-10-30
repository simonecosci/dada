<?php

namespace App\Models;

use App\Enums\VersionStatus;
use App\Models\Concerns\HasVariables;
use Database\Factories\ProjectVersionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectVersion extends Model
{
    /** @use HasFactory<ProjectVersionFactory> */
    use HasFactory, HasVariables;

    protected $guarded = [];

    protected $attributes = [
        'commands' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'compose' => 'array',
            'commands' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') !== VersionStatus::Published) {
                return;
            }

            if ($version->isDirty(['compose', 'commands', 'version'])) {
                throw new DomainException('A published version is immutable.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isPublished(): bool
    {
        return $this->status === VersionStatus::Published;
    }
}
