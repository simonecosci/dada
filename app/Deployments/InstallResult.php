<?php

namespace App\Deployments;

use App\Models\Installation;

final readonly class InstallResult
{
    /** @param  list<string>  $warnings */
    public function __construct(
        public Installation $installation,
        public array $warnings = [],
    ) {}
}
