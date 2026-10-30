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
use RuntimeException;

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
        // Same queue and lock as deploy: never two active runs on the same deployment.
        $this->onConnection('redis-deploy')->onQueue('deploy');
    }

    /**
     * Remote command. Ruling P5: arguments are validated with restrictive regexes, so
     * they are emitted without quotes.
     */
    public static function command(DeploymentHost $dh, string $action, ?string $slug, ?string $service): string
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('invalid action');
        }

        if ($slug === null || $slug === '') {
            if ($service !== null && $service !== '') {
                throw new InvalidArgumentException('the service requires an installation');
            }

            return RemoteSteps::wrap($dh, "./restart.sh {$action}");
        }

        if (! preg_match(self::SLUG_REGEX, $slug)) {
            throw new InvalidArgumentException('invalid slug');
        }

        $target = 'all';
        if ($service !== null && $service !== '') {
            if (! preg_match(self::SERVICE_REGEX, $service)) {
                throw new InvalidArgumentException('invalid service name');
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

            $what = $slug ? ($service ? "{$slug}/{$service}" : $slug) : 'the whole host';
            $log->step($dh, "Action {$action}: {$what}");

            $shell = $shells->for($dh->host);
            try {
                $result = $shell->exec($command, fn (string $chunk) => $log->output($dh, $chunk));
                $log->flush();
                if ($result->exitCode === 127 || str_contains($result->output, 'No such file or directory')) {
                    throw new RuntimeException('script not present on the host: run a deploy first');
                }
                if (! $result->successful()) {
                    throw RemoteCommandFailed::from($command, $result);
                }
            } finally {
                $shell->close();
            }
        });
    }
}
