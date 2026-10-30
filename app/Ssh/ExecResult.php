<?php

namespace App\Ssh;

final readonly class ExecResult
{
    /** Solo lo stdout del comando: è l'unico da interpretare (stderr contiene avvisi ssh, banner...). */
    public string $stdout;

    /**
     * @param  string  $output  stdout+stderr uniti, per log e messaggi d'errore
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
