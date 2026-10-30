<?php

namespace App\Http\Controllers\Deployments;

use App\Catalog\CommandList;
use App\Catalog\ComposeFragment;
use App\Catalog\InvalidComposeFragment;
use App\Deployments\InstallVersion;
use App\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Variable;
use App\Variables\VariableData;
use App\Variables\VariableRules;
use App\Variables\VariableSync;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstallationController extends Controller
{
    private const SLUG = '/\A[a-z0-9][a-z0-9-]*\z/';

    public function create(DeploymentHost $deploymentHost): Response
    {
        Gate::authorize('operate', $deploymentHost->deployment);

        $projects = Project::query()
            ->whereHas('versions', fn ($q) => $q->where('status', VersionStatus::Published->value))
            ->with(['versions' => fn ($q) => $q->where('status', VersionStatus::Published->value)->orderByDesc('published_at')->orderByDesc('id')])
            ->orderBy('name')
            ->get();

        return Inertia::render('deployments/installations/Create', [
            'deployment' => $deploymentHost->deployment->only(['id', 'name', 'slug']),
            'deploymentHost' => $deploymentHost->only(['id', 'path']),
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'versions' => $p->versions->map(fn ($v) => ['id' => $v->id, 'version' => $v->version])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request, DeploymentHost $deploymentHost, InstallVersion $install): RedirectResponse
    {
        Gate::authorize('operate', $deploymentHost->deployment);

        $data = $request->validate([
            'project_version_id' => ['required', 'integer', 'exists:project_versions,id'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:'.self::SLUG],
        ], ['slug.regex' => 'Lo slug può contenere solo minuscole, cifre e trattini.']);

        $version = ProjectVersion::findOrFail($data['project_version_id']);

        try {
            $result = $install->handle($deploymentHost, $version, $data['slug'] ?? null);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['project_version_id' => $e->getMessage()]);
        }

        if ($result->warnings !== []) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => "Versione installata. Avvisi:\n".implode("\n", $result->warnings),
            ]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Versione installata.']);
        }

        return to_route('deployments.hosts.show', [$deploymentHost->deployment_id, $deploymentHost]);
    }

    public function edit(Request $request, Installation $installation): Response
    {
        $dh = $installation->deploymentHost;
        Gate::authorize('view', $dh->deployment);
        $canOperate = $request->user()->can('operate', $dh->deployment);

        $installation->load('version.project');

        return Inertia::render('deployments/installations/Edit', [
            'deployment' => $dh->deployment->only(['id', 'name', 'slug']),
            'deploymentHost' => $dh->only(['id', 'path']),
            'installation' => [
                'id' => $installation->id,
                'slug' => $installation->slug,
                'order' => $installation->order,
                'project' => $installation->version->project->name,
                'version' => $installation->version->version,
            ],
            'services' => RestartController::services($installation->compose ?? []),
            'compose_yaml' => ComposeFragment::dump($installation->compose),
            'commands' => $installation->commands,
            'variables' => $installation->variables->map(fn ($v) => VariableData::forClient($v))->all(),
            'canOperate' => $canOperate,
            'canReveal' => $canOperate,
        ]);
    }

    public function update(Request $request, Installation $installation): RedirectResponse
    {
        $dh = $installation->deploymentHost;
        Gate::authorize('operate', $dh->deployment);

        $data = $request->validate([
            'slug' => [
                'required', 'string', 'max:255', 'regex:'.self::SLUG, Rule::notIn(['restart']),
                Rule::unique('installations', 'slug')->where('deployment_host_id', $dh->id)->ignore($installation->id),
            ],
            'order' => ['required', 'integer', 'min:0', 'max:65535'],
            'compose_yaml' => ['required', 'string', 'max:262144'],
            'commands' => ['present', 'array'],
            'commands.*' => ['array'],
            ...VariableRules::rules(),
        ], [
            'slug.regex' => 'Lo slug può contenere solo minuscole, cifre e trattini.',
            'slug.not_in' => 'lo slug "restart" è riservato',
            'slug.unique' => 'Esiste già un\'installazione con questo slug su questo host.',
        ]);

        try {
            $compose = ComposeFragment::parse($data['compose_yaml']);
        } catch (InvalidComposeFragment $e) {
            throw ValidationException::withMessages(['compose_yaml' => implode("\n", $e->errors())]);
        }

        $commands = $this->normalizeCommands($data['commands']);
        $errors = [...CommandList::validate($commands), ...CommandList::validateTargets($commands, $compose)];
        if ($errors !== []) {
            throw ValidationException::withMessages(['commands' => implode("\n", $errors)]);
        }

        $rows = $this->variableRows($installation, $data['variables']);

        DB::transaction(function () use ($installation, $data, $compose, $commands, $rows): void {
            $installation->update([
                'slug' => $data['slug'],
                'order' => $data['order'],
                'compose' => $compose,
                'commands' => $commands,
            ]);
            VariableSync::sync($installation, $rows, allowHostGlobal: false);
        });

        return back();
    }

    public function destroy(Installation $installation): RedirectResponse
    {
        $dh = $installation->deploymentHost;
        Gate::authorize('operate', $dh->deployment);

        DB::transaction(fn () => $installation->delete());

        return to_route('deployments.hosts.show', [$dh->deployment_id, $dh]);
    }

    public function reorder(Request $request, DeploymentHost $deploymentHost): RedirectResponse
    {
        Gate::authorize('operate', $deploymentHost->deployment);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $current = $deploymentHost->installations()->pluck('id')->all();
        $given = array_map('intval', $data['ids']);
        $sortedCurrent = $current;
        $sortedGiven = $given;
        sort($sortedCurrent);
        sort($sortedGiven);

        if ($sortedCurrent !== $sortedGiven) {
            throw ValidationException::withMessages(['ids' => 'L\'elenco non corrisponde alle installazioni dell\'host.']);
        }

        DB::transaction(function () use ($deploymentHost, $given): void {
            foreach ($given as $i => $id) {
                $deploymentHost->installations()->reorder()->whereKey($id)->update(['order' => $i + 1]);
            }
        });

        return back();
    }

    /**
     * Di un'installazione il client può cambiare solo use_default e value delle variabili esistenti:
     * la struttura (chiave, scope, segreto, default...) resta quella copiata dalla versione.
     *
     * @param  array<int, array<string, mixed>>  $submitted
     * @return list<array<string, mixed>>
     */
    private function variableRows(Installation $installation, array $submitted): array
    {
        $stored = $installation->variables()->get()->keyBy('id');
        $byId = [];
        $errors = [];

        foreach (array_values($submitted) as $i => $row) {
            $id = $row['id'] ?? null;
            if ($id === null || ! $stored->has((int) $id)) {
                $errors["variables.$i.id"] = 'Variabile sconosciuta: su un\'installazione non si possono aggiungere variabili.';
            } else {
                $byId[(int) $id] = $row;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $stored->map(function (Variable $var) use ($byId): array {
            $in = $byId[$var->id] ?? [];
            $row = [
                'id' => $var->id,
                'key' => $var->key,
                'scope' => $var->scope->value,
                'description' => $var->description,
                'required' => $var->required,
                'secret' => $var->secret,
                'use_default' => array_key_exists('use_default', $in) && $in['use_default'] !== null ? (bool) $in['use_default'] : $var->use_default,
            ];

            // Un segreto senza la chiave resta invariato; un non segreto va sempre passato per intero.
            if (! $var->secret) {
                $row['default'] = $var->default;
                $row['value'] = array_key_exists('value', $in) ? $in['value'] : $var->value;
            } elseif (array_key_exists('value', $in)) {
                $row['value'] = $in['value'];
            }

            return $row;
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function normalizeCommands(array $commands): array
    {
        return array_map(function (array $c): array {
            if (($c['description'] ?? null) === null || $c['description'] === '') {
                unset($c['description']);
            }

            return $c;
        }, array_values($commands));
    }
}
