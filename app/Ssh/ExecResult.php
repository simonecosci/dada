<?php

namespace App\Ssh;

final readonly class ExecResult
{
    public function __construct(public int $exitCode, public string $output) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
