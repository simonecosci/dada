<?php

namespace App\Deploy;

/** Installation present in the last deploy and no longer among the host's current ones. */
final readonly class Removal
{
    /**
     * @param  list<string>  $services
     * @param  list<string>  $files  files to delete, relative to the deployment host directory
     */
    public function __construct(
        public string $slug,
        public array $services,
        public array $files,
    ) {}
}
