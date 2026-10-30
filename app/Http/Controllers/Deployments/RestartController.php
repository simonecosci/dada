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

    private const BUSY = 'Esecuzione già in corso';

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
            'installation_slug.required_with' => 'Il servizio richiede un\'installazione.',
            'installation_slug.exists' => 'Installazione non trovata su questo host.',
        ]);

        $slug = $data['installation_slug'] ?? null;
        $service = $data['service'] ?? null;

        if ($service !== null) {
            $installation = $deploymentHost->installations()->where('slug', $slug)->first();
            if (! in_array($service, self::services($installation->compose ?? []), true)) {
                throw ValidationException::withMessages(['service' => 'Servizio non presente in questa installazione.']);
            }
        }

        $options = [
            'deployment_host_id' => $deploymentHost->id,
            'action' => $data['action'],
            'installation_slug' => $slug,
            'service' => $service,
        ];

        // Lock sulla riga del deployment: due richieste concorrenti non possono creare due run attivi.
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
            // RestartControls usa una visita Inertia: un JSON 409 aprirebbe il modale di risposta non valida.
            if ($request->expectsJson()) {
                return response()->json(['message' => self::BUSY], 409);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => self::BUSY]);

            return back();
        }

        return $this->dispatchRun($request, $result, new RestartJob($result->id));
    }

    /**
     * Servizi avviabili dell'installazione (esclusi gli init), dal compose salvato.
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
