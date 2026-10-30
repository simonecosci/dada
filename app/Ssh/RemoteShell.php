<?php

namespace App\Ssh;

interface RemoteShell
{
    /**
     * Runs $command (already complete with cd/sudo) and merges stdout+stderr into output (stdout is also kept separate); $onOutput receives text chunks.
     *
     * @param  (callable(string): void)|null  $onOutput
     */
    public function exec(string $command, ?callable $onOutput = null, ?string $stdin = null, ?int $timeout = null): ExecResult;

    /**
     * Writes $contents to $remotePath (via temporary file + mv) and applies chmod $mode.
     */
    public function put(string $remotePath, string $contents, int $mode = 0644): void;

    public function close(): void;
}
