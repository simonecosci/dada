<?php

namespace App\Catalog;

use App\Enums\VersionStatus;
use App\Models\ProjectVersion;
use DomainException;

class VersionPublisher
{
    public function publish(ProjectVersion $version): void
    {
        if ($version->isPublished()) {
            throw new DomainException('The version is already published.');
        }

        $version->status = VersionStatus::Published;
        $version->published_at = now();
        $version->save();
    }
}
