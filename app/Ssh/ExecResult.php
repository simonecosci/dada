<?php

namespace App\Ssh;

final readonly class ExecResult
{
    /** Only the command's stdout: it is the only one to interpret (stderr contains ssh warnings, banners...). */
    public string $stdout;

    /**
     * @param  string  $output  stdout+stderr merged, for logs and error messages
     * @param  string|null  $stdout  null = uguale a $output
     */
    public function __construct(public int $exitCode, public string $output, ?string $stdout = null)
    {
        $this->stdout = $stdout ?? $output;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
