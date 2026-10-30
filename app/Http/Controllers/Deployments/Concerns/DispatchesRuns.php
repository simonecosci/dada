<?php

namespace App\Http\Controllers\Deployments\Concerns;

use App\Deploy\RunTerminator;
use App\Models\DeployRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Inertia\Inertia;
use Throwable;

trait DispatchesRuns
{
    public const DISPATCH_FAILED = 'Unable to queue the run: try again later.';

    /**
     * Queues $job; if the queue does not respond the run is closed as failed (Ruling F4),
     * otherwise it would stay "queued" forever, blocking the deployment.
     */
    protected function dispatchRun(Request $request, DeployRun $run, object $job): JsonResponse|RedirectResponse
    {
        try {
            Bus::dispatch($job);
        } catch (Throwable $e) {
            report($e);
            // Class only: the raw message does not go through the masker.
            RunTerminator::fail($run, 'queueing failed ('.class_basename($e).')');

            if ($request->expectsJson()) {
                return response()->json(['message' => self::DISPATCH_FAILED], 503);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => self::DISPATCH_FAILED]);

            return back();
        }

        return redirect()->route('runs.show', $run);
    }
}
