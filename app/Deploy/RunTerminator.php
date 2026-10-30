<?php

namespace App\Deploy;

use App\Enums\RunStatus;
use App\Events\DeployRunUpdated;
use App\Jobs\DeployJob;
use App\Models\DeployRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chiude come fallito un run rimasto attivo (worker morto, dispatch fallito, intervento admin).
 * Libera il lock del deployment solo se appartiene ancora a questo run (owner salvato in options).
 */
class RunTerminator
{
    /**
     * $error non deve contenere segreti (viene salvato e trasmesso così com'è).
     *
     * @return bool false se il run non era più attivo
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

        // Mai forceRelease: il lock potrebbe appartenere a un altro run dello stesso deployment.
        $owner = $run->options['lock_owner'] ?? null;
        if (is_string($owner) && $owner !== '') {
            Cache::restoreLock(DeployJob::lockKey($run->deployment_id), $owner)->release();
        }

        DeployRunUpdated::safeDispatch($run);

        return true;
    }
}
