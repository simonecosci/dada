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

    protected $description = 'Mark as failed the runs queued or running for longer than lock_ttl + retry_after';

    public const ERROR = 'run expired: no progress beyond the limit';

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

        $this->info("Runs marked as failed: {$reaped}");

        return self::SUCCESS;
    }
}
