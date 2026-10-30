<?php

namespace App\Generation;

final class BuiltCompose
{
    /**
     * @param  array<string, mixed>  $compose  Contenuto di docker-compose.yml
     * @param  array<string, array<string, array<string, mixed>>>  $initServices  slug => service name => definition (swarm only)
     * @param  array<string, list<string>>  $servicesBySlug  slug => names of the services present in $compose
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $compose,
        public readonly array $initServices,
        public readonly array $servicesBySlug,
        public readonly array $warnings = [],
    ) {}
}
