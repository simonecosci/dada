<?php

namespace App\Deploy;

/** Installazione presente nell'ultimo deploy e non più tra quelle attuali dell'host. */
final readonly class Removal
{
    /**
     * @param  list<string>  $services
     * @param  list<string>  $files  file da cancellare, relativi alla cartella del deployment host
     */
    public function __construct(
        public string $slug,
        public array $services,
        public array $files,
    ) {}
}
