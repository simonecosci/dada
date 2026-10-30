<?php

namespace App\Jobs;

use App\Deploy\RemoteSteps;
use App\Models\Host;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShell;
use App\Ssh\RemoteShellFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class HostCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** Timeout (secondi) di ciascun comando di verifica. */
    public const COMMAND_TIMEOUT = 30;

    public const DOCKER_COMMAND = "docker version --format '{{.Server.Version}}'";

    public function __construct(public int $hostId) {}

    public function handle(RemoteShellFactory $shells): void
    {
        $host = Host::find($this->hostId);
        if ($host === null) {
            return;
        }

        $shell = null;

        try {
            $shell = $shells->for($host);

            $facts = ['os' => $this->run($shell, 'uname -sr'), ...$this->docker($shell, $host)];
            $facts += [
                'cpus' => (int) $this->run($shell, 'nproc'),
                'memory_mb' => (int) $this->run($shell, "awk '/MemTotal/{print int(\$2/1024)}' /proc/meminfo"),
                'disk_free_mb' => (int) $this->run($shell, "df -Pm / | awk 'NR==2{print \$4}'"),
            ];

            $host->forceFill(['reachable' => true, 'facts' => $facts, 'checked_at' => now()])->save();
        } catch (Throwable $e) {
            $host->forceFill(['reachable' => false, 'facts' => ['error' => $e->getMessage()], 'checked_at' => now()])->save();
        } finally {
            $shell?->close();
        }
    }

    /**
     * Docker con il prefisso sudo dell'host. Se fallisce (daemon spento, permessi) l'host resta
     * raggiungibile: si registra docker = null e l'errore.
     *
     * @return array{docker: ?string, docker_error?: string}
     */
    private function docker(RemoteShell $shell, Host $host): array
    {
        $result = $shell->exec(RemoteSteps::withSudo((string) $host->sudo, self::DOCKER_COMMAND), timeout: self::COMMAND_TIMEOUT);

        if ($result->successful()) {
            return ['docker' => trim($result->stdout)];
        }

        $error = trim($result->output);

        return [
            'docker' => null,
            'docker_error' => Str::limit($error !== '' ? $error : "exit {$result->exitCode}", 500),
        ];
    }

    private function run(RemoteShell $shell, string $command): string
    {
        $result = $shell->exec($command, timeout: self::COMMAND_TIMEOUT);

        if (! $result->successful()) {
            throw RemoteCommandFailed::from($command, $result);
        }

        return trim($result->stdout);
    }
}
