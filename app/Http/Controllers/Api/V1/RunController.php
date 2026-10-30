<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeployRunLogResource;
use App\Http\Resources\DeployRunResource;
use App\Models\DeployRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Runs
 */
class RunController extends Controller
{
    private const DEFAULT_LOG_LIMIT = 500;

    private const MAX_LOG_LIMIT = 1000;

    /**
     * Show a run.
     *
     * Type, status, options, error and timestamps of the run, with the status of each host. Ability: `read`.
     */
    public function show(DeployRun $run): DeployRunResource
    {
        Gate::authorize('view', $run->deployment);

        $run->load('hosts.deploymentHost.host:id,name');

        return new DeployRunResource($run);
    }

    /**
     * Read the log of a run.
     *
     * Log lines in order, secrets masked. To follow a run, poll passing the `meta.last_id` of the previous
     * response as `after`, until `meta.has_more` is false and `meta.run_status` is `succeeded` or `failed`.
     * Ability: `read`.
     */
    public function logs(Request $request, DeployRun $run): AnonymousResourceCollection
    {
        Gate::authorize('view', $run->deployment);

        $data = $request->validate([
            /** Returns only the lines with an id greater than this one (default 0: from the start). */
            'after' => ['sometimes', 'integer', 'min:0'],
            /** Maximum number of lines, 1 to 1000 (default 500). */
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $after = (int) ($data['after'] ?? 0);
        $limit = min((int) ($data['limit'] ?? self::DEFAULT_LOG_LIMIT), self::MAX_LOG_LIMIT);

        $logs = $run->logs()->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $logs->count() > $limit;
        $logs = $logs->take($limit);

        return DeployRunLogResource::collection($logs)->additional([
            'meta' => [
                /** Cursor for the next request (`after`): the id of the last line, or the `after` received. */
                'last_id' => $logs->last()->id ?? $after,
                /** More lines are already available: request them right away. */
                'has_more' => $hasMore,
                /** Current status of the run. */
                'run_status' => $run->status,
            ],
        ]);
    }
}
