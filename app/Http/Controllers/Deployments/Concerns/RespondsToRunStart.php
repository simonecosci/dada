<?php

namespace App\Http\Controllers\Deployments\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Web error responses shared by the deploy and restart buttons. */
trait RespondsToRunStart
{
    /** JSON for expectsJson; otherwise an error toast and back (an Inertia visit would show a JSON error as a modal). */
    protected function runStartError(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
