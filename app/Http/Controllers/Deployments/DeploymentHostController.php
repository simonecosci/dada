<?php

namespace App\Http\Controllers\Deployments;

use App\Catalog\ComposeFragment;
use App\Deployments\HostGlobalSync;
use App\Enums\CredentialType;
use App\Enums\Orchestrator;
use App\Enums\VariableScope;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\DeploymentHost;
use App\Models\Host;
use App\Variables\VariableData;
use App\Variables\VariableRules;
use App\Variables\VariableSync;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class DeploymentHostController extends Controller
{
    private const SLUG = '/\A[a-z0-9][a-z0-9-]*\z/';

    private const TEMPLATE_KEYS = ['volumes', 'networks'];

    public function create(Deployment $deployment): Response
    {
        Gate::authorize('operate', $deployment);

        return Inertia::render('deployments/hosts/Form', [
            'deployment' => $deployment->only(['id', 'name', 'slug']),
            'deploymentHost' => null,
            'hosts' => Host::orderBy('name')->get(['id', 'name', 'hostname']),
            'orchestrators' => array_map(fn (Orchestrator $o) => $o->value, Orchestrator::cases()),
        ]);
    }

    public function store(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('operate', $deployment);

        $request->merge(['stack_name' => $request->input('stack_name') ?: $deployment->slug]);

        $data = $request->validate([
            'host_id' => ['required', 'integer', 'exists:hosts,id'],
            'path' => $this->pathRules(null, (int) $request->input('host_id')),
            'orchestrator' => ['required', Rule::enum(Orchestrator::class)],
            'stack_name' => ['required', 'string', 'max:255', 'regex:'.self::SLUG, $this->stackRule(null, (int) $request->input('host_id'))],
        ], $this->messages());

        $dh = $deployment->hosts()->create([
            'host_id' => $data['host_id'],
            'path' => $data['path'],
            'orchestrator' => $data['orchestrator'],
            'stack_name' => $data['stack_name'],
            'compose_template' => [],
        ]);

        return to_route('deployments.hosts.show', [$deployment, $dh]);
    }

    public function show(Request $request, Deployment $deployment, DeploymentHost $deploymentHost): Response
    {
        $this->authorizeHost($deployment, $deploymentHost, 'view');
        $canOperate = $request->user()->can('operate', $deployment);

        return Inertia::render('deployments/hosts/Show', [
            'deployment' => $deployment->only(['id', 'name', 'slug']),
            'deploymentHost' => $this->hostData($deploymentHost),
            'installations' => $deploymentHost->installations()->with('version.project')->get()->map(fn ($i) => [
                'id' => $i->id,
                'slug' => $i->slug,
                'order' => $i->order,
                'project' => $i->version->project->name,
                'version' => $i->version->version,
            ])->values(),
            'variables' => $deploymentHost->variables->map(fn ($v) => VariableData::forClient($v))->all(),
            'unused' => HostGlobalSync::unused($deploymentHost),
            'registries' => $deploymentHost->registries()->pluck('credentials.id')->all(),
            'availableRegistries' => Credential::where('type', CredentialType::Registry->value)
                ->orderBy('name')->get(['id', 'name', 'registry_url']),
            'compose_template' => $deploymentHost->compose_template === []
                ? ''
                : ComposeFragment::dump($deploymentHost->compose_template),
            'canOperate' => $canOperate,
            'canReveal' => $canOperate,
            'runs' => RunController::summaries(
                $deployment->runs()->whereHas('hosts', fn ($q) => $q->where('deployment_host_id', $deploymentHost->id)),
            ),
        ]);
    }

    public function edit(Deployment $deployment, DeploymentHost $deploymentHost): Response
    {
        $this->authorizeHost($deployment, $deploymentHost);

        return Inertia::render('deployments/hosts/Form', [
            'deployment' => $deployment->only(['id', 'name', 'slug']),
            'deploymentHost' => $this->hostData($deploymentHost),
            'hosts' => [],
            'orchestrators' => array_map(fn (Orchestrator $o) => $o->value, Orchestrator::cases()),
        ]);
    }

    public function update(Request $request, Deployment $deployment, DeploymentHost $deploymentHost): RedirectResponse
    {
        $this->authorizeHost($deployment, $deploymentHost);

        $data = $request->validate([
            'path' => $this->pathRules($deploymentHost, $deploymentHost->host_id),
            'orchestrator' => ['required', Rule::enum(Orchestrator::class)],
            'stack_name' => ['required', 'string', 'max:255', 'regex:'.self::SLUG, $this->stackRule($deploymentHost, $deploymentHost->host_id)],
            'compose_template' => ['nullable', 'string', 'max:262144'],
        ], $this->messages());

        $attributes = [
            'path' => $data['path'],
            'orchestrator' => $data['orchestrator'],
            'stack_name' => $data['stack_name'],
        ];

        // Il template cambia solo se la richiesta lo include: il form delle impostazioni non lo invia.
        if (array_key_exists('compose_template', $data)) {
            $attributes['compose_template'] = $this->parseTemplate($data['compose_template']);
        }

        $deploymentHost->update($attributes);

        return back();
    }

    public function destroy(Deployment $deployment, DeploymentHost $deploymentHost): RedirectResponse
    {
        $this->authorizeHost($deployment, $deploymentHost);

        DB::transaction(fn () => $deploymentHost->delete());

        return to_route('deployments.show', $deployment);
    }

    public function variables(Request $request, Deployment $deployment, DeploymentHost $deploymentHost): RedirectResponse
    {
        $this->authorizeHost($deployment, $deploymentHost);

        $data = $request->validate(VariableRules::rules());

        $errors = [];
        foreach (array_values($data['variables']) as $i => $row) {
            if (($row['scope'] ?? null) !== VariableScope::HostGlobal->value) {
                $errors["variables.$i.scope"] = "Sull'host sono ammesse solo variabili host-global.";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        VariableSync::sync($deploymentHost, $data['variables']);

        return back();
    }

    public function registries(Request $request, Deployment $deployment, DeploymentHost $deploymentHost): RedirectResponse
    {
        $this->authorizeHost($deployment, $deploymentHost);

        $data = $request->validate([
            'credential_ids' => ['present', 'array'],
            'credential_ids.*' => [
                'integer',
                Rule::exists('credentials', 'id')->where('type', CredentialType::Registry->value),
            ],
        ], ['credential_ids.*.exists' => 'Sono ammesse solo credenziali di tipo registry.']);

        $deploymentHost->registries()->sync($data['credential_ids']);

        return back();
    }

    private function authorizeHost(Deployment $deployment, DeploymentHost $deploymentHost, string $ability = 'operate'): void
    {
        Gate::authorize($ability, $deployment);
        abort_unless($deploymentHost->deployment_id === $deployment->id, 404);
    }

    /** @return array<string, mixed> */
    private function hostData(DeploymentHost $dh): array
    {
        return [
            'id' => $dh->id,
            'path' => $dh->path,
            'orchestrator' => $dh->orchestrator->value,
            'stack_name' => $dh->stack_name,
            'host' => $dh->host->only(['id', 'name', 'hostname']),
        ];
    }

    /** @return list<mixed> */
    private function pathRules(?DeploymentHost $ignore, int $hostId): array
    {
        return [
            'required', 'string', 'max:255', 'regex:/\A\/[A-Za-z0-9._\/-]*\z/',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (in_array('..', explode('/', (string) $value), true) || trim((string) $value, '/') === '') {
                    $fail('Il percorso non può essere la radice né contenere segmenti "..".');
                }
            },
            Rule::unique('deployment_hosts', 'path')->where('host_id', $hostId)->ignore($ignore?->id),
        ];
    }

    private function stackRule(?DeploymentHost $ignore, int $hostId): Unique
    {
        return Rule::unique('deployment_hosts', 'stack_name')->where('host_id', $hostId)->ignore($ignore?->id);
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'path.regex' => 'Il percorso deve essere assoluto e contenere solo lettere, cifre, punto, trattino, underscore e barre.',
            'path.unique' => 'Questo percorso è già usato su questo host.',
            'stack_name.unique' => 'Questo nome di stack è già usato su questo host.',
            'stack_name.regex' => 'Il nome dello stack può contenere solo minuscole, cifre e trattini.',
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function parseTemplate(?string $yaml): array
    {
        if ($yaml === null || trim($yaml) === '') {
            return [];
        }

        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw ValidationException::withMessages(['compose_template' => 'YAML non valido: '.$e->getMessage()]);
        }

        if ($data === null) {
            return [];
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ValidationException::withMessages(['compose_template' => '(radice): deve essere una mappa']);
        }

        $errors = [];
        $template = [];
        foreach ($data as $key => $value) {
            if (! in_array($key, self::TEMPLATE_KEYS, true)) {
                $errors[] = "{$key}: chiave non consentita (ammesse: ".implode(', ', self::TEMPLATE_KEYS).')';
            } elseif ($value === null) {
                continue;
            } elseif (! is_array($value) || ($value !== [] && array_is_list($value))) {
                $errors[] = "{$key}: deve essere una mappa";
            } else {
                $template[$key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['compose_template' => implode("\n", $errors)]);
        }

        return $template;
    }
}
