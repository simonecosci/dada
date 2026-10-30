<?php

namespace App\Jobs;

use App\Deploy\DeployHostPipeline;
use App\Deploy\DeployLogger;
use App\Generation\HostFilesGenerator;
use App\Jobs\Concerns\RunsWithDeploymentLock;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

class DeployJob implements ShouldQueue
{
    use Queueable, RunsWithDeploymentLock;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $runId)
    {
        $this->timeout = (int) config('dada.deploy.lock_ttl') - 60;
        // Connessione con retry_after > timeout: un deploy in corso non viene riconsegnato.
        $this->onConnection('redis-deploy')->onQueue('deploy');
    }

    /** @return list<string> */
    protected function extraSecrets(Collection $hosts): array
    {
        $generator = app(HostFilesGenerator::class);
        $secrets = [];
        foreach ($hosts as $dh) {
            $secrets = [...$secrets, ...$generator->generate($dh)->secretValues];
        }

        return $secrets;
    }

    public function handle(DeployHostPipeline $pipeline): void
    {
        $run = DeployRun::find($this->runId);
        if ($run === null) {
            return;
        }

        $options = $run->options ?? [];
        $this->runLocked($run, fn (DeploymentHost $dh, DeployLogger $log) => $pipeline->run($run, $dh, $log, [
            'pull' => (bool) ($options['pull'] ?? true),
            'restart' => (bool) ($options['restart'] ?? true),
            'confirm_removals' => (bool) ($options['confirm_removals'] ?? false),
        ]));
    }
}
