<?php

namespace App\Http\Controllers\Deployments;

use App\Deploy\Removal;
use App\Deploy\RemovalPlanner;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\Concerns\DispatchesRuns;
use App\Jobs\DeployJob;
use App\Models\Deployment;
use App\Models\DeploymentHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeployController extends Controller
{
    use DispatchesRuns;

    public function __invoke(Request $request, Deployment $deployment): JsonResponse|RedirectResponse
    {
        Gate::authorize('operate', $deployment);

        $data = $request->validate([
            'deployment_host_id' => [
                'nullable', 'integer',
                Rule::exists('deployment_hosts', 'id')->where('deployment_id', $deployment->id),
            ],
            'pull' => ['sometimes', 'boolean'],
            'restart' => ['sometimes', 'boolean'],
            'confirm_removals' => ['sometimes', 'boolean'],
        ]);

        $options = [
            'deployment_host_id' => isset($data['deployment_host_id']) ? (int) $data['deployment_host_id'] : null,
            'pull' => $request->boolean('pull', true),
            'restart' => $request->boolean('restart', true),
            'confirm_removals' => $request->boolean('confirm_removals'),
        ];

        // Lock on the deployment row: two concurrent requests cannot create two active runs.
        $result = DB::transaction(function () use ($request, $deployment, $options) {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->first();

            if ($deployment->runs()->active()->exists()) {
                return response()->json(['message' => 'A run is already in progress'], 409);
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
                    return response()->json(['removals' => $removals], 422);
                }
            }

            return $deployment->runs()->create([
                'user_id' => $request->user()->id,
                'type' => RunType::Deploy,
                'status' => RunStatus::Queued,
                'options' => $options,
            ]);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return $this->dispatchRun($request, $result, new DeployJob($result->id));
    }
}
