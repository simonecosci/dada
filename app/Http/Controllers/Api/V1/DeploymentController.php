<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\DeployRunResource;
use App\Models\Deployment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Deployments
 */
class DeploymentController extends Controller
{
    /**
     * List deployments.
     *
     * The deployments the user can see (all of them for an Admin), ordered by name. Ability: `read`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            /** Page size, 1 to 100 (default 25). */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return DeploymentResource::collection(
            Deployment::visibleTo($request->user())->orderBy('name')->paginate((int) ($data['per_page'] ?? 25)),
        );
    }

    /**
     * Show a deployment.
     *
     * The deployment with its hosts, the installations of each host and the active run, if any. Ability: `read`.
     */
    public function show(Deployment $deployment): DeploymentResource
    {
        Gate::authorize('view', $deployment);

        $deployment->load([
            'hosts' => fn ($q) => $q->orderBy('id'),
            'hosts.host:id,name,hostname',
            'hosts.installations.version.project:id,name,slug',
            'activeRun',
        ]);

        return new DeploymentResource($deployment);
    }

    /**
     * List the runs of a deployment.
     *
     * Deploy and restart runs, newest first. Ability: `read`.
     */
    public function runs(Request $request, Deployment $deployment): AnonymousResourceCollection
    {
        Gate::authorize('view', $deployment);

        $data = $request->validate([
            /** Page size, 1 to 100 (default 25). */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return DeployRunResource::collection(
            $deployment->runs()->latest('id')->paginate((int) ($data['per_page'] ?? 25)),
        );
    }
}
