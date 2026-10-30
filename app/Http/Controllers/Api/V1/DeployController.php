<?php

namespace App\Http\Controllers\Api\V1;

use App\Deployments\RunStarter;
use App\Deployments\RunStartOutcome;
use App\Deployments\RunStartResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deployments\DeployRequest;
use App\Http\Resources\DeployRunResource;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;

/**
 * @tags Runs
 */
class DeployController extends Controller
{
    /**
     * Deploy a deployment.
     *
     * Generates the files and updates the hosts of the deployment (or only `deployment_host_id`) in a queued run.
     * Answers 202 with the run; follow it with the run and log endpoints.
     * 409 when another run is queued or running; 422 with `removals` when the deploy would remove installations
     * no longer configured (repeat with `confirm_removals: true`); 503 when the run could not be queued (the run is
     * closed as failed). Requires the Operator role (or Admin). Ability: `deploy`.
     */
    public function __invoke(DeployRequest $request, Deployment $deployment, RunStarter $starter): JsonResponse
    {
        $result = $starter->deploy($deployment, $request->user(), $request->options());

        if ($result->outcome === RunStartOutcome::Busy) {
            return response()->json(['message' => RunStartResult::BUSY], 409);
        }

        if ($result->outcome === RunStartOutcome::RemovalsPending) {
            return response()->json([
                'message' => 'The deploy removes installations: repeat it with confirm_removals to proceed.',
                /** Installations (and their services) the deploy would remove, per host. */
                'removals' => $result->removals,
            ], 422);
        }

        if ($result->outcome === RunStartOutcome::DispatchFailed) {
            return response()->json([
                'message' => RunStartResult::DISPATCH_FAILED,
                /** The run, already closed as failed. */
                'run_id' => $result->run->id,
            ], 503);
        }

        return (new DeployRunResource($result->run))->response()->setStatusCode(202);
    }
}
