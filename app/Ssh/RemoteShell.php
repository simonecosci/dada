<?php

namespace App\Ssh;

interface RemoteShell
{
    /**
     * Esegue $command (già completo di cd/sudo) e unisce stdout+stderr in output (stdout resta anche separato); $onOutput riceve chunk di testo.
     *
     * @param  (callable(string): void)|null  $onOutput
     */
    public function exec(string $command, ?callable $onOutput = null, ?string $stdin = null, ?int $timeout = null): ExecResult;

    /**
     * Scrive $contents in $remotePath (via file temporaneo + mv) e applica chmod $mode.
     */
    public function put(string $remotePath, string $contents, int $mode = 0644): void;

    public function close(): void;
}
