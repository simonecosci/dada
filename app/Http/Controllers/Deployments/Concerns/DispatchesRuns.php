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
    public const DISPATCH_FAILED = 'Impossibile accodare l\'esecuzione: riprova più tardi.';

    /**
     * Accoda $job; se la coda non risponde il run viene chiuso come fallito (Ruling F4),
     * altrimenti resterebbe "in coda" per sempre bloccando il deployment.
     */
    protected function dispatchRun(Request $request, DeployRun $run, object $job): JsonResponse|RedirectResponse
    {
        try {
            Bus::dispatch($job);
        } catch (Throwable $e) {
            report($e);
            // Solo la classe: il messaggio grezzo non passa dal masker.
            RunTerminator::fail($run, 'accodamento fallito ('.class_basename($e).')');

            if ($request->expectsJson()) {
                return response()->json(['message' => self::DISPATCH_FAILED], 503);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => self::DISPATCH_FAILED]);

            return back();
        }

        return redirect()->route('runs.show', $run);
    }
}
