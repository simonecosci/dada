<?php

namespace App\Generation;

use RuntimeException;

class GenerationFailed extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
