<?php

namespace App\Deploy;

use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Jobs\DeployJob;
use App\Models\DeployRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Closes as failed a run left active (dead worker, failed dispatch, admin intervention).
 * Releases the deployment lock only if it still belongs to this run (owner saved in options).
 */
class RunTerminator
{
    /**
     * $error must not contain secrets (it is saved and broadcast as is).
     *
     * @return bool false if the run was no longer active
     */
    public static function fail(DeployRun $run, string $error): bool
    {
        $failed = DB::transaction(function () use ($run, $error): bool {
            $locked = DeployRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, [RunStatus::Queued, RunStatus::Running], true)) {
                return false;
            }

            $locked->hosts()->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])->update([
                'status' => RunStatus::Failed->value,
                'error' => $error,
                'finished_at' => now(),
            ]);
            $locked->update(['status' => RunStatus::Failed, 'error' => $error, 'finished_at' => now()]);
            $run->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $failed) {
            return false;
        }

        // Never forceRelease: the lock might belong to another run of the same deployment.
        $owner = $run->options['lock_owner'] ?? null;
        if (is_string($owner) && $owner !== '') {
            Cache::restoreLock(DeployJob::lockKey($run->deployment_id), $owner)->release();
        }

        DeployRunUpdated::safeDispatch($run);

        return true;
    }
}
