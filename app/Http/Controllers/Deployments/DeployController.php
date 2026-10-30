<?php

namespace App\Http\Controllers\Deployments;

use App\Deployments\RunStarter;
use App\Deployments\RunStartOutcome;
use App\Deployments\RunStartResult;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\Concerns\RespondsToRunStart;
use App\Http\Requests\Deployments\DeployRequest;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DeployController extends Controller
{
    use RespondsToRunStart;

    public function __invoke(DeployRequest $request, Deployment $deployment, RunStarter $starter): JsonResponse|RedirectResponse
    {
        $result = $starter->deploy($deployment, $request->user(), $request->options());

        return match ($result->outcome) {
            RunStartOutcome::Started => redirect()->route('runs.show', $result->run),
            RunStartOutcome::Busy => response()->json(['message' => RunStartResult::BUSY], 409),
            RunStartOutcome::RemovalsPending => response()->json(['removals' => $result->removals], 422),
            RunStartOutcome::DispatchFailed => $this->runStartError($request, RunStartResult::DISPATCH_FAILED, 503),
        };
    }
}
