<?php

namespace App\Jobs;

use App\Deploy\DeployHostPipeline;
use App\Deploy\DeployLogger;
use App\Deploy\SecretMasker;
use App\Enums\CredentialType;
use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Generation\HostFilesGenerator;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class DeployJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $runId)
    {
        $this->timeout = (int) config('dada.deploy.lock_ttl') - 60;
        // Connessione con retry_after > timeout: un deploy in corso non viene riconsegnato.
        $this->onConnection('redis-deploy')->onQueue('deploy');
    }

    public static function lockKey(int $deploymentId): string
    {
        return "deployment-run:{$deploymentId}";
    }

    public function handle(DeployHostPipeline $pipeline, HostFilesGenerator $generator): void
    {
        $run = DeployRun::find($this->runId);
        if ($run === null) {
            return;
        }

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
            $log = new DeployLogger($run, $this->masker($hosts, $generator));

            $run->update(['status' => RunStatus::Running, 'started_at' => now(), 'error' => null]);
            DeployRunUpdated::safeDispatch($run);

            $allSucceeded = true;
            foreach ($hosts as $dh) {
                $allSucceeded = $this->deployHost($run, $dh, $pipeline, $log, $options) && $allSucceeded;
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

    /** @param  array<string, mixed>  $options */
    private function deployHost(DeployRun $run, DeploymentHost $dh, DeployHostPipeline $pipeline, DeployLogger $log, array $options): bool
    {
        $runHost = DeployRunHost::create([
            'deploy_run_id' => $run->id,
            'deployment_host_id' => $dh->id,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);
        DeployRunUpdated::safeDispatch($run);

        try {
            $pipeline->run($run, $dh, $log, [
                'pull' => (bool) ($options['pull'] ?? true),
                'restart' => (bool) ($options['restart'] ?? true),
                'confirm_removals' => (bool) ($options['confirm_removals'] ?? false),
            ]);
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
