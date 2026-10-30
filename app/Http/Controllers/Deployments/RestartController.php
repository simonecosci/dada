<?php

namespace App\Http\Controllers\Deployments;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\Concerns\DispatchesRuns;
use App\Jobs\RestartJob;
use App\Models\Deployment;
use App\Models\DeploymentHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RestartController extends Controller
{
    use DispatchesRuns;

    private const BUSY = 'A run is already in progress';

    public function __invoke(Request $request, DeploymentHost $deploymentHost): JsonResponse|RedirectResponse
    {
        $deployment = $deploymentHost->deployment;
        Gate::authorize('operate', $deployment);

        $data = $request->validate([
            'action' => ['required', Rule::in(RestartJob::ACTIONS)],
            'installation_slug' => [
                'nullable', 'required_with:service', 'string', 'max:255', 'regex:'.RestartJob::SLUG_REGEX.'D',
                Rule::exists('installations', 'slug')->where('deployment_host_id', $deploymentHost->id),
            ],
            'service' => ['nullable', 'string', 'max:255', 'regex:'.RestartJob::SERVICE_REGEX.'D'],
        ], [
            'installation_slug.required_with' => 'The service requires an installation.',
            'installation_slug.exists' => 'Installation not found on this host.',
        ]);

        $slug = $data['installation_slug'] ?? null;
        $service = $data['service'] ?? null;

        if ($service !== null) {
            $installation = $deploymentHost->installations()->where('slug', $slug)->first();
            if (! in_array($service, self::services($installation->compose ?? []), true)) {
                throw ValidationException::withMessages(['service' => 'Service not found in this installation.']);
            }
        }

        $options = [
            'deployment_host_id' => $deploymentHost->id,
            'action' => $data['action'],
            'installation_slug' => $slug,
            'service' => $service,
        ];

        // Lock on the deployment row: two concurrent requests cannot create two active runs.
        $result = DB::transaction(function () use ($request, $deployment, $options) {
            Deployment::query()->whereKey($deployment->id)->lockForUpdate()->first();

            if ($deployment->runs()->active()->exists()) {
                return null;
            }

            return $deployment->runs()->create([
                'user_id' => $request->user()->id,
                'type' => RunType::Restart,
                'status' => RunStatus::Queued,
                'options' => $options,
            ]);
        });

        if ($result === null) {
            // RestartControls uses an Inertia visit: a JSON 409 would open the invalid-response modal.
            if ($request->expectsJson()) {
                return response()->json(['message' => self::BUSY], 409);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => self::BUSY]);

            return back();
        }

        return $this->dispatchRun($request, $result, new RestartJob($result->id));
    }

    /**
     * Startable services of the installation (inits excluded), from the saved compose.
     *
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    public static function services(array $compose): array
    {
        $names = [];
        foreach ((array) ($compose['services'] ?? []) as $name => $def) {
            $def = is_array($def) ? $def : [];
            $isInit = ($def['x-dada']['init'] ?? false) === true
                || in_array('init', (array) ($def['profiles'] ?? []), true);
            if (! $isInit) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
