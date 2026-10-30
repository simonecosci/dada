<?php

namespace App\Http\Controllers\Deployments;

use App\Deployments\RunStarter;
use App\Deployments\RunStartOutcome;
use App\Deployments\RunStartResult;
use App\Deployments\StartableServices;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\Concerns\RespondsToRunStart;
use App\Http\Requests\Deployments\RestartRequest;
use App\Models\DeploymentHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use LogicException;

class RestartController extends Controller
{
    use RespondsToRunStart;

    public function __invoke(RestartRequest $request, DeploymentHost $deploymentHost, RunStarter $starter): JsonResponse|RedirectResponse
    {
        $result = $starter->restart($deploymentHost, $request->user(), $request->options());

        return match ($result->outcome) {
            RunStartOutcome::Started => redirect()->route('runs.show', $result->run),
            // RestartControls uses an Inertia visit: a JSON 409 would open the invalid-response modal.
            RunStartOutcome::Busy => $this->runStartError($request, RunStartResult::BUSY, 409),
            RunStartOutcome::DispatchFailed => $this->runStartError($request, RunStartResult::DISPATCH_FAILED, 503),
            RunStartOutcome::RemovalsPending => throw new LogicException('A restart never plans removals.'),
        };
    }

    /**
     * Startable services of the installation (inits excluded), from the saved compose.
     *
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    public static function services(array $compose): array
    {
        return StartableServices::of($compose);
    }
}
