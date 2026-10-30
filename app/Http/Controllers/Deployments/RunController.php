<?php

namespace App\Http\Controllers\Deployments;

use App\Deploy\RunTerminator;
use App\Enums\RunStatus;
use App\Http\Controllers\Controller;
use App\Models\DeployRun;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    private const INITIAL_LOGS = 2000;

    private const LIST_LIMIT = 20;

    public function show(Request $request, DeployRun $run): Response
    {
        $run->load(['deployment:id,name,slug', 'user:id,name', 'hosts.deploymentHost.host:id,name,hostname']);
        Gate::authorize('view', $run->deployment);

        $logs = $run->logs()->latest('id')->limit(self::INITIAL_LOGS)->get()->reverse()->values();

        return Inertia::render('runs/Show', [
            'run' => [
                ...self::summary($run),
                'error' => $run->error,
                'deployment' => $run->deployment->only(['id', 'name']),
            ],
            'hosts' => $run->hosts->map(fn ($h) => [
                'deployment_host_id' => $h->deployment_host_id,
                'name' => $h->deploymentHost->host->name,
                'status' => $h->status->value,
                'error' => $h->error,
            ])->values(),
            'logs' => $logs->map->toPayload()->values(),
            'can' => [
                'fail' => $request->user()->isAdmin() && self::isActive($run),
            ],
        ]);
    }

    /** Admin: chiude un run rimasto bloccato (Ruling F4). */
    public function fail(Request $request, DeployRun $run): RedirectResponse
    {
        Gate::authorize('admin');

        if (! self::isActive($run) || ! RunTerminator::fail($run, 'segnata come fallita da '.$request->user()->name)) {
            throw ValidationException::withMessages(['run' => "L'esecuzione è già terminata."]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Esecuzione segnata come fallita.']);

        return back();
    }

    private static function isActive(DeployRun $run): bool
    {
        return in_array($run->status, [RunStatus::Queued, RunStatus::Running], true);
    }

    /**
     * Ultime esecuzioni per le tab "Esecuzioni".
     *
     * @param  Relation<DeployRun, *, *>  $query
     * @return list<array<string, mixed>>
     */
    public static function summaries(Relation $query): array
    {
        return $query->with('user:id,name')->latest('id')->limit(self::LIST_LIMIT)->get()
            ->map(fn (DeployRun $r) => self::summary($r))->all();
    }

    /** @return array<string, mixed> */
    private static function summary(DeployRun $run): array
    {
        return [
            'id' => $run->id,
            'type' => $run->type->value,
            'status' => $run->status->value,
            'user' => $run->user_name ?? $run->user?->name,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
