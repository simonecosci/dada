<?php

namespace App\Jobs;

use App\Deploy\DeployLogger;
use App\Deploy\RemoteSteps;
use App\Jobs\Concerns\RunsWithDeploymentLock;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShellFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class RestartJob implements ShouldQueue
{
    use Queueable, RunsWithDeploymentLock;

    public const ACTIONS = ['start', 'stop', 'restart'];

    public const SLUG_REGEX = '/\A[a-z0-9][a-z0-9-]*\z/';

    public const SERVICE_REGEX = '/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/';

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $runId)
    {
        $this->timeout = (int) config('dada.deploy.lock_ttl') - 60;
        // Stessa coda e stesso lock del deploy: mai due run attivi sullo stesso deployment.
        $this->onConnection('redis-deploy')->onQueue('deploy');
    }

    /**
     * Comando remoto. Ruling P5: gli argomenti sono validati con regex restrittive, quindi
     * vengono emessi senza apici.
     */
    public static function command(DeploymentHost $dh, string $action, ?string $slug, ?string $service): string
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('azione non valida');
        }

        if ($slug === null || $slug === '') {
            if ($service !== null && $service !== '') {
                throw new InvalidArgumentException('il servizio richiede un\'installazione');
            }

            return RemoteSteps::wrap($dh, "./restart.sh {$action}");
        }

        if (! preg_match(self::SLUG_REGEX, $slug)) {
            throw new InvalidArgumentException('slug non valido');
        }

        $target = 'all';
        if ($service !== null && $service !== '') {
            if (! preg_match(self::SERVICE_REGEX, $service)) {
                throw new InvalidArgumentException('nome servizio non valido');
            }
            $target = $service;
        }

        return RemoteSteps::wrap($dh, "./{$slug}.sh {$action} {$target}");
    }

    public function handle(RemoteShellFactory $shells): void
    {
        $run = DeployRun::find($this->runId);
        if ($run === null) {
            return;
        }

        $options = $run->options ?? [];
        $this->runLocked($run, function (DeploymentHost $dh, DeployLogger $log) use ($shells, $options) {
            $action = (string) ($options['action'] ?? '');
            $slug = $options['installation_slug'] ?? null;
            $service = $options['service'] ?? null;
            $command = self::command($dh, $action, $slug, $service);

            $what = $slug ? ($service ? "{$slug}/{$service}" : $slug) : 'tutto l\'host';
            $log->step($dh, "Azione {$action}: {$what}");

            $shell = $shells->for($dh->host);
            try {
                $result = $shell->exec($command, fn (string $chunk) => $log->output($dh, $chunk));
                $log->flush();
                if (! $result->successful()) {
                    throw RemoteCommandFailed::from($command, $result);
                }
            } finally {
                $shell->close();
            }
        });
    }
}
