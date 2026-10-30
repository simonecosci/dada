<?php

namespace App\Console\Commands;

use App\Deploy\RunTerminator;
use App\Enums\RunStatus;
use App\Models\DeployRun;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ReapRunsCommand extends Command
{
    protected $signature = 'dada:runs:reap';

    protected $description = 'Segna come falliti i run in coda o in corso da oltre lock_ttl + retry_after';

    public const ERROR = 'esecuzione scaduta: nessun avanzamento oltre il limite';

    public function handle(): int
    {
        $limit = (int) config('dada.deploy.lock_ttl') + (int) config('queue.connections.redis-deploy.retry_after');
        $cutoff = now()->subSeconds($limit);

        $runs = DeployRun::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', RunStatus::Running->value)
                    ->where(fn (Builder $q) => $q->where('started_at', '<', $cutoff)
                        ->orWhere(fn (Builder $q) => $q->whereNull('started_at')->where('created_at', '<', $cutoff))))
                ->orWhere(fn (Builder $q) => $q->where('status', RunStatus::Queued->value)->where('created_at', '<', $cutoff)))
            ->orderBy('id')
            ->get();

        $reaped = 0;
        foreach ($runs as $run) {
            $reaped += RunTerminator::fail($run, self::ERROR) ? 1 : 0;
        }

        $this->info("Run segnati come falliti: {$reaped}");

        return self::SUCCESS;
    }
}
