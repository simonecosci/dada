<?php

namespace App\Deployments;

use App\Deploy\Removal;
use App\Deploy\RemovalPlanner;
use App\Deploy\RunTerminator;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Jobs\DeployJob;
use App\Jobs\RestartJob;
use App\Models\Deployment;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts deploy and restart runs, shared by the web UI and the API.
 * Authorization and input validation are the caller's job; options arrive already normalized.
 */
class RunStarter
{
    /**
     * @param  array{deployment_host_id: int|null, pull: bool, restart: bool, confirm_removals: bool}  $options
     *
     * @throws ValidationException when the deployment has no hosts to update
     */
    public function deploy(Deployment $deployment, User $user, array $options): RunStartResult
    {
        // Lock on the deployment row: two concurrent requests cannot create two active runs.
        $result = DB::transaction(function () use ($deployment, $user, $options): RunStartResult|DeployRun {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->first();

            if ($deployment->runs()->active()->exists()) {
                return RunStartResult::busy();
            }

            $hosts = $deployment->hosts()->orderBy('id')
                ->when($options['deployment_host_id'] !== null, fn ($q) => $q->whereKey($options['deployment_host_id']))
                ->get();

            if ($hosts->isEmpty()) {
                throw ValidationException::withMessages(['deployment_host_id' => 'The deployment has no hosts to update.']);
            }

            if (! $options['confirm_removals']) {
                $removals = $hosts->flatMap(fn (DeploymentHost $dh) => array_map(fn (Removal $r) => [
                    'deployment_host_id' => $dh->id,
                    'slug' => $r->slug,
                    'services' => $r->services,
                ], RemovalPlanner::for($dh)))->values()->all();

                if ($removals !== []) {
                    return RunStartResult::removalsPending($removals);
                }
            }

            return $this->createRun($deployment, $user, RunType::Deploy, $options);
        });

        if ($result instanceof RunStartResult) {
            return $result;
        }

        return $this->dispatch($result, new DeployJob($result->id));
    }

    /**
     * @param  array{action: string, installation_slug: string|null, service: string|null}  $options
     */
    public function restart(DeploymentHost $deploymentHost, User $user, array $options): RunStartResult
    {
        $deployment = $deploymentHost->deployment;
        $options = ['deployment_host_id' => $deploymentHost->id, ...$options];

        // Lock on the deployment row: two concurrent requests cannot create two active runs.
        $run = DB::transaction(function () use ($deployment, $user, $options): ?DeployRun {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->first();

            if ($deployment->runs()->active()->exists()) {
                return null;
            }

            return $this->createRun($deployment, $user, RunType::Restart, $options);
        });

        if ($run === null) {
            return RunStartResult::busy();
        }

        return $this->dispatch($run, new RestartJob($run->id));
    }

    /** @param  array<string, mixed>  $options */
    private function createRun(Deployment $deployment, User $user, RunType $type, array $options): DeployRun
    {
        return $deployment->runs()->create([
            'user_id' => $user->id,
            'type' => $type,
            'status' => RunStatus::Queued,
            'options' => $options,
        ]);
    }

    /**
     * Queues $job; if the queue does not respond the run is closed as failed (Ruling F4),
     * otherwise it would stay "queued" forever, blocking the deployment.
     */
    private function dispatch(DeployRun $run, object $job): RunStartResult
    {
        try {
            Bus::dispatch($job);
        } catch (Throwable $e) {
            report($e);
            // Class only: the raw message does not go through the masker.
            RunTerminator::fail($run, 'queueing failed ('.class_basename($e).')');

            return RunStartResult::dispatchFailed($run);
        }

        return RunStartResult::started($run);
    }
}
