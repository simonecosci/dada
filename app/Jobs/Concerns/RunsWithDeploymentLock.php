<?php

namespace App\Jobs\Concerns;

use App\Deploy\DeployLogger;
use App\Deploy\RunTerminator;
use App\Deploy\SecretMasker;
use App\Enums\CredentialType;
use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use App\Models\Installation;
use App\Models\Variable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Execution of a run (deploy/restart) under the deployment lock: run state,
 * per-host rows, broadcast and closing when the worker is interrupted (failed()).
 *
 * The class using the trait must expose `public int $runId`.
 */
trait RunsWithDeploymentLock
{
    public static function lockKey(int $deploymentId): string
    {
        return "deployment-run:{$deploymentId}";
    }

    /**
     * Runs $perHost(DeploymentHost $dh, DeployLogger $log) for each host of the run; an exception
     * marks that host as failed (masked message) and moves on to the next one.
     *
     * @param  Closure(DeploymentHost, DeployLogger): void  $perHost
     */
    protected function runLocked(DeployRun $run, Closure $perHost): void
    {
        $lock = Cache::lock(self::lockKey($run->deployment_id), (int) config('dada.deploy.lock_ttl'));
        if (! $lock->get()) {
            $this->finish($run, RunStatus::Failed, 'deployment busy');

            return;
        }

        try {
            $options = $run->options ?? [];
            // The lock owner lets failed() release only its own lock.
            $run->update(['options' => [...$options, 'lock_owner' => $lock->owner()]]);
            $hosts = $this->hosts($run, $options['deployment_host_id'] ?? null);
            $log = new DeployLogger($run, $this->masker($hosts));

            $run->update(['status' => RunStatus::Running, 'started_at' => now(), 'error' => null]);
            DeployRunUpdated::safeDispatch($run);

            $allSucceeded = true;
            foreach ($hosts as $dh) {
                $allSucceeded = $this->runHost($run, $dh, $log, $perHost) && $allSucceeded;
            }

            // An admin (or the reaper) may have already closed it as failed: do not overwrite.
            if ($run->refresh()->status === RunStatus::Running) {
                $this->finish($run, $allSucceeded ? RunStatus::Succeeded : RunStatus::Failed);
            }
        } finally {
            $lock->release();
        }
    }

    /** The worker interrupted the job (timeout, unhandled exception): closes the run and releases the lock. */
    public function failed(?Throwable $e): void
    {
        $run = DeployRun::find($this->runId);
        if ($run === null) {
            return;
        }

        // The exception message may contain secrets and there is no masker here: class only.
        // RunTerminator releases the lock only if it still belongs to this run.
        RunTerminator::fail($run, 'run interrupted'.($e !== null ? ' ('.class_basename($e).')' : ''));
    }

    /** @param  Closure(DeploymentHost, DeployLogger): void  $perHost */
    private function runHost(DeployRun $run, DeploymentHost $dh, DeployLogger $log, Closure $perHost): bool
    {
        $runHost = DeployRunHost::create([
            'deploy_run_id' => $run->id,
            'deployment_host_id' => $dh->id,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);
        DeployRunUpdated::safeDispatch($run);

        try {
            $perHost($dh, $log);
            $log->flush();
            $runHost->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
            $succeeded = true;
        } catch (Throwable $e) {
            $log->flush();
            $message = $log->mask($e->getMessage());
            if (! $e instanceof RuntimeException) {
                // Never the raw message: it might contain secrets.
                report(new RuntimeException(class_basename($e).': '.$message));
            }
            $runHost->update(['status' => RunStatus::Failed, 'error' => $message, 'finished_at' => now()]);
            $log->error($dh, $message);
            $succeeded = false;
        }

        DeployRunUpdated::safeDispatch($run);

        return $succeeded;
    }

    /** @return Collection<int, DeploymentHost> */
    private function hosts(DeployRun $run, mixed $deploymentHostId): Collection
    {
        $query = $run->deployment->hosts()->with(['host', 'registries'])->orderBy('id');
        if ($deploymentHostId !== null) {
            $query->whereKey($deploymentHostId);
        }

        return $query->get();
    }

    /**
     * Secrets read directly from the DB (secret variables of hosts and installations, registry credentials),
     * independent of generation: a broken configuration must not leave the output unmasked.
     *
     * @param  Collection<int, DeploymentHost>  $hosts
     */
    private function masker(Collection $hosts): SecretMasker
    {
        $secrets = $this->extraSecrets($hosts);
        foreach ($hosts as $dh) {
            $owners = [[DeploymentHost::class, [$dh->id]], [Installation::class, $dh->installations()->pluck('id')->all()]];
            foreach ($owners as [$type, $ids]) {
                $variables = Variable::query()->where('owner_type', (new $type)->getMorphClass())
                    ->whereIn('owner_id', $ids)->where('secret', true)->get();
                foreach ($variables as $variable) {
                    foreach ([$variable->default, $variable->value] as $candidate) {
                        if (is_string($candidate) && $candidate !== '') {
                            $secrets[] = $candidate;
                        }
                    }
                }
            }

            try {
                foreach ($dh->registries as $registry) {
                    if ($registry->type === CredentialType::Registry && is_string($registry->secret)) {
                        $secrets[] = $registry->secret;
                    }
                }
            } catch (Throwable $e) {
                // Undecryptable secret: the host pipeline will report it.
                report($e);
            }
        }

        return new SecretMasker($secrets);
    }

    /**
     * Additional secrets the class can provide (e.g. those from file generation).
     *
     * @param  Collection<int, DeploymentHost>  $hosts
     * @return list<string>
     */
    protected function extraSecrets(Collection $hosts): array
    {
        return [];
    }

    private function finish(DeployRun $run, RunStatus $status, ?string $error = null): void
    {
        $run->update(array_filter([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
        ], fn ($v) => $v !== null));
        DeployRunUpdated::safeDispatch($run);
    }
}
