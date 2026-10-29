<?php

namespace App\Jobs\Concerns;

use App\Deploy\DeployLogger;
use App\Deploy\SecretMasker;
use App\Enums\CredentialType;
use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Generation\HostFilesGenerator;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Esecuzione di un run (deploy/restart) sotto il lock del deployment: stato del run,
 * righe per host, broadcast e chiusura in caso di interruzione del worker (failed()).
 */
trait RunsWithDeploymentLock
{
    public static function lockKey(int $deploymentId): string
    {
        return "deployment-run:{$deploymentId}";
    }

    /**
     * Esegue $perHost(DeploymentHost $dh, DeployLogger $log) per ogni host del run; un'eccezione
     * marca fallito quell'host (messaggio mascherato) e si passa al successivo.
     *
     * @param  Closure(DeploymentHost, DeployLogger): void  $perHost
     */
    protected function runLocked(DeployRun $run, Closure $perHost): void
    {
        $lock = Cache::lock(self::lockKey($run->deployment_id), (int) config('dada.deploy.lock_ttl'));
        if (! $lock->get()) {
            $this->finish($run, RunStatus::Failed, 'deployment occupato');

            return;
        }

        try {
            $options = $run->options ?? [];
            // Il proprietario del lock permette a failed() di rilasciare solo il proprio.
            $run->update(['options' => [...$options, 'lock_owner' => $lock->owner()]]);
            $hosts = $this->hosts($run, $options['deployment_host_id'] ?? null);
            $log = new DeployLogger($run, $this->masker($hosts, app(HostFilesGenerator::class)));

            $run->update(['status' => RunStatus::Running, 'started_at' => now(), 'error' => null]);
            DeployRunUpdated::safeDispatch($run);

            $allSucceeded = true;
            foreach ($hosts as $dh) {
                $allSucceeded = $this->runHost($run, $dh, $log, $perHost) && $allSucceeded;
            }

            $this->finish($run, $allSucceeded ? RunStatus::Succeeded : RunStatus::Failed);
        } finally {
            $lock->release();
        }
    }

    /** Il worker ha interrotto il job (timeout, eccezione non gestita): chiude il run e libera il lock. */
    public function failed(?Throwable $e): void
    {
        $run = DeployRun::find($this->runId);
        if ($run === null) {
            return;
        }

        // Il messaggio dell'eccezione può contenere segreti e qui non c'è un masker: solo la classe.
        $error = 'esecuzione interrotta'.($e !== null ? ' ('.class_basename($e).')' : '');

        $run->hosts()->where('status', RunStatus::Running->value)->update([
            'status' => RunStatus::Failed->value,
            'error' => $error,
            'finished_at' => now(),
        ]);

        $wasRunning = $run->status === RunStatus::Running;
        if (in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)) {
            $this->finish($run, RunStatus::Failed, $error);
        }

        // Mai forceRelease: il lock potrebbe appartenere a un altro run dello stesso deployment.
        $owner = $run->options['lock_owner'] ?? null;
        if ($wasRunning && is_string($owner) && $owner !== '') {
            Cache::restoreLock(self::lockKey($run->deployment_id), $owner)->release();
        }
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
                // Mai il messaggio grezzo: potrebbe contenere segreti.
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

    /** @param  Collection<int, DeploymentHost>  $hosts */
    private function masker(Collection $hosts, HostFilesGenerator $generator): SecretMasker
    {
        $secrets = [];
        foreach ($hosts as $dh) {
            $secrets = [...$secrets, ...$generator->generate($dh)->secretValues];

            try {
                foreach ($dh->registries as $registry) {
                    if ($registry->type === CredentialType::Registry && is_string($registry->secret)) {
                        $secrets[] = $registry->secret;
                    }
                }
            } catch (Throwable $e) {
                // Secret non decifrabile: lo segnalerà la pipeline dell'host.
                report($e);
            }
        }

        return new SecretMasker($secrets);
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
