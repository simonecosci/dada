<?php

namespace App\Http\Controllers\Api\V1;

use App\Deployments\RunStarter;
use App\Deployments\RunStartOutcome;
use App\Deployments\RunStartResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deployments\RestartRequest;
use App\Http\Resources\DeployRunResource;
use App\Models\DeploymentHost;
use Illuminate\Http\JsonResponse;

/**
 * @tags Runs
 */
class RestartController extends Controller
{
    /**
     * Start, stop or restart services.
     *
     * Runs `action` on the whole deployment host, on one installation (`installation_slug`) or on one of its
     * services (`service`, init services excluded) in a queued run. Answers 202 with the run.
     * 409 when another run of the deployment is queued or running; 503 when the run could not be queued (the run
     * is closed as failed). Requires the Operator role (or Admin). Ability: `deploy`.
     */
    public function __invoke(RestartRequest $request, DeploymentHost $deploymentHost, RunStarter $starter): JsonResponse
    {
        $result = $starter->restart($deploymentHost, $request->user(), $request->options());

        if ($result->outcome === RunStartOutcome::Busy) {
            return response()->json(['message' => RunStartResult::BUSY], 409);
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
