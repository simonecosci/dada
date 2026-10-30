<?php

namespace App\Ssh\Fake;

use App\Ssh\ExecResult;
use App\Ssh\RemoteShell;
use Throwable;

/**
 * Shell finta per i test. I comandi senza expect() corrispondente restituiscono
 * exit 0 e output vuoto; tutti i comandi eseguiti sono registrati in commands().
 * put() registra il file in files() e NON aggiunge nulla a commands().
 */
class FakeRemoteShell implements RemoteShell
{
    /** @var list<array{pattern: string, exit: int, output: string, stderr: string}> */
    private array $expectations = [];

    /** @var list<string> */
    private array $commands = [];

    /**
     * Timeout passato a ciascun exec(), nello stesso ordine di $commands.
     *
     * @var list<?int>
     */
    private array $timeouts = [];

    /** @var array<string, array{contents: string, mode: int}> */
    private array $files = [];

    private ?Throwable $connectFailure = null;

    private bool $closed = false;

    /**
     * Risposta per i comandi che corrispondono alla regex $pattern (vince la prima registrata).
     * $output è lo stdout; $stderr (opzionale) è anteposto nell'output unito, come rumore di ssh.
     */
    public function expect(string $pattern, int $exit = 0, string $output = '', string $stderr = ''): static
    {
        $this->expectations[] = ['pattern' => $pattern, 'exit' => $exit, 'output' => $output, 'stderr' => $stderr];

        return $this;
    }

    /**
     * Ogni exec()/put() successivo lancia $e.
     */
    public function failConnect(Throwable $e): static
    {
        $this->connectFailure = $e;

        return $this;
    }

    public function exec(string $command, ?callable $onOutput = null, ?string $stdin = null, ?int $timeout = null): ExecResult
    {
        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        $this->commands[] = $command;
        $this->timeouts[] = $timeout;

        foreach ($this->expectations as $expectation) {
            if (preg_match($expectation['pattern'], $command)) {
                $merged = $expectation['stderr'].$expectation['output'];
                if ($onOutput !== null && $merged !== '') {
                    $onOutput($merged);
                }

                return new ExecResult($expectation['exit'], $merged, $expectation['output']);
            }
        }

        return new ExecResult(0, '');
    }

    public function put(string $remotePath, string $contents, int $mode = 0644): void
    {
        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        $this->files[$remotePath] = ['contents' => $contents, 'mode' => $mode];
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @return list<string> */
    public function commands(): array
    {
        return $this->commands;
    }

    /** @return list<?int> */
    public function timeouts(): array
    {
        return $this->timeouts;
    }

    /** @return array<string, array{contents: string, mode: int}> */
    public function files(): array
    {
        return $this->files;
    }

    public function closed(): bool
    {
        return $this->closed;
    }
}
