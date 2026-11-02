<?php

namespace App\Deploy;

use App\Infrastructure\Privilege;
use App\Models\Host;
use App\Ssh\RemoteShell;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Validates sudo on each host once per run: a wrong password fails every deployment host
 * of that host without trying it again (and without locking the account out).
 */
final class PrivilegeCheck
{
    /** @var array<int, true> host id => validated */
    private array $valid = [];

    /** @var array<int, string> host id => failure message */
    private array $failed = [];

    public function ensure(RemoteShell $shell, Host $host): void
    {
        if (isset($this->valid[$host->id])) {
            return;
        }

        if (isset($this->failed[$host->id])) {
            throw new RuntimeException($this->failed[$host->id]);
        }

        $privilege = Privilege::for($host);
        $command = $privilege->validateCommand();
        if ($command === null) {
            return;
        }

        // The password goes only on stdin; the output never echoes it (prompt disabled with -p '').
        $result = $shell->exec($command, null, $privilege->stdin());

        if ($result->successful()) {
            $this->valid[$host->id] = true;

            return;
        }

        $this->failed[$host->id] = "sudo authentication failed on {$host->name}: ".Str::limit(trim($result->output), 200);

        throw new RuntimeException($this->failed[$host->id]);
    }
}
