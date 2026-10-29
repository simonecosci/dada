<?php

namespace App\Ssh;

use RuntimeException;

final class RemoteCommandFailed extends RuntimeException
{
    public static function from(string $command, ExecResult $r): self
    {
        $output = trim($r->output);

        return new self(
            "Comando remoto fallito (exit {$r->exitCode}): {$command}".($output !== '' ? "\n{$output}" : ''),
            $r->exitCode,
        );
    }
}
