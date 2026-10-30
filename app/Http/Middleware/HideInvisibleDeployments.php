<?php

namespace App\Http\Middleware;

use App\Models\Deployment;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API: a deployment the user cannot view (directly or through a host or a run) answers 404,
 * so the API does not reveal that it exists.
 */
class HideInvisibleDeployments
{
    public function handle(Request $request, Closure $next): Response
    {
        $deployment = $this->deployment($request);

        if ($deployment !== null && $request->user()?->cannot('view', $deployment)) {
            abort(404);
        }

        return $next($request);
    }

    private function deployment(Request $request): ?Deployment
    {
        $route = $request->route();

        foreach (['deployment', 'deploymentHost', 'run'] as $name) {
            $bound = $route?->parameter($name);
            if ($bound instanceof Deployment) {
                return $bound;
            }
            if ($bound instanceof DeploymentHost || $bound instanceof DeployRun) {
                return $bound->deployment;
            }
        }

        return null;
    }
}
