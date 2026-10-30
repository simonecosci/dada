<?php

namespace App\Http\Controllers\Catalog;

use App\Catalog\CommandList;
use App\Catalog\ComposeFragment;
use App\Catalog\InvalidComposeFragment;
use App\Catalog\VersionCloner;
use App\Catalog\VersionPublisher;
use App\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Installation;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Variables\VariableData;
use App\Variables\VariableSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectVersionController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['version' => $this->versionRules($project, null)]);

        // Template minimo: l'immagine vuota fa fallire la pubblicazione finché non viene compilata.
        $version = $project->versions()->create([
            'version' => $data['version'],
            'status' => VersionStatus::Draft,
            'compose' => ['services' => ['app' => ['image' => '']]],
            'commands' => [],
        ]);

        return to_route('versions.edit', $version);
    }

    public function edit(Request $request, ProjectVersion $version): Response
    {
        Gate::authorize('view', $version->project);

        $canManage = $request->user()->can('update', $version->project);

        return Inertia::render('catalog/versions/Edit', [
            'project' => $version->project->only(['id', 'name', 'slug']),
            'version' => [
                'id' => $version->id,
                'version' => $version->version,
                'notes' => $version->notes,
                'status' => $version->status->value,
            ],
            'compose_yaml' => ComposeFragment::dump($version->compose),
            'commands' => $version->commands,
            'variables' => $version->variables->map(fn ($v) => VariableData::forClient($v))->all(),
            'canEdit' => $canManage && ! $version->isPublished(),
            'canManage' => $canManage,
            'canReveal' => $request->user()->isMaintainer(),
        ]);
    }

    public function update(Request $request, ProjectVersion $version): RedirectResponse
    {
        Gate::authorize('update', $version->project);

        if ($version->isPublished()) {
            abort(403, 'Una versione pubblicata è immutabile.');
        }

        $data = $request->validate([
            'version' => $this->versionRules($version->project, $version),
            'notes' => ['nullable', 'string', 'max:5000'],
            'compose_yaml' => ['required', 'string', 'max:262144'],
            'commands' => ['present', 'array'],
            'commands.*' => ['array'],
            'variables' => ['present', 'array'],
            'variables.*' => ['array'],
            'variables.*.id' => ['nullable', 'integer'],
            'variables.*.key' => ['nullable', 'string', 'max:255'],
            'variables.*.scope' => ['nullable', 'string', 'max:50'],
            'variables.*.description' => ['nullable', 'string', 'max:1000'],
            'variables.*.required' => ['nullable', 'boolean'],
            'variables.*.secret' => ['nullable', 'boolean'],
            'variables.*.use_default' => ['nullable', 'boolean'],
            'variables.*.default' => ['nullable', 'string', 'max:65535'],
            'variables.*.value' => ['nullable', 'string', 'max:65535'],
        ]);

        try {
            $compose = ComposeFragment::parse($data['compose_yaml']);
        } catch (InvalidComposeFragment $e) {
            throw ValidationException::withMessages(['compose_yaml' => implode("\n", $e->errors())]);
        }

        $commands = $this->normalizeCommands($data['commands']);
        $this->assertCommands($commands, $compose);

        DB::transaction(function () use ($version, $data, $compose, $commands): void {
            $version->update([
                'version' => $data['version'],
                'notes' => $data['notes'] ?? null,
                'compose' => $compose,
                'commands' => $commands,
            ]);
            VariableSync::sync($version, $data['variables']);
        });

        return back();
    }

    public function publish(ProjectVersion $version): RedirectResponse
    {
        Gate::authorize('publish', $version->project);

        if ($version->isPublished()) {
            throw ValidationException::withMessages(['version' => 'La versione è già pubblicata.']);
        }

        try {
            $compose = ComposeFragment::parse(ComposeFragment::dump($version->compose));
        } catch (InvalidComposeFragment $e) {
            throw ValidationException::withMessages(['compose_yaml' => implode("\n", $e->errors())]);
        }
        $this->assertCommands($version->commands, $compose);

        (new VersionPublisher)->publish($version);

        return back();
    }

    public function clone(Request $request, ProjectVersion $version): RedirectResponse
    {
        Gate::authorize('clone', $version->project);

        $data = $request->validate(['version' => $this->versionRules($version->project, null)]);

        $new = VersionCloner::clone($version, $data['version']);

        return to_route('versions.edit', $new);
    }

    public function destroy(ProjectVersion $version): RedirectResponse
    {
        Gate::authorize('delete', $version->project);

        if ($version->isPublished()) {
            throw ValidationException::withMessages(['version' => 'Una versione pubblicata non può essere cancellata.']);
        }

        if (Installation::where('project_version_id', $version->id)->exists()) {
            throw ValidationException::withMessages(['version' => 'La versione è installata su uno o più host e non può essere cancellata.']);
        }

        $project = $version->project;
        $version->delete();

        return to_route('projects.show', $project);
    }

    /** @return list<mixed> */
    private function versionRules(Project $project, ?ProjectVersion $ignore): array
    {
        return [
            'required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._+-]*\z/',
            Rule::unique('project_versions', 'version')->where('project_id', $project->id)->ignore($ignore?->id),
        ];
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

    /**
     * @param  array<mixed>  $commands
     * @param  array<string, mixed>  $compose
     */
    private function assertCommands(array $commands, array $compose): void
    {
        $errors = [...CommandList::validate($commands), ...CommandList::validateTargets($commands, $compose)];

        if ($errors !== []) {
            throw ValidationException::withMessages(['commands' => implode("\n", $errors)]);
        }
    }
}
