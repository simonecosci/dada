<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\VersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        return Inertia::render('catalog/projects/Index', [
            'projects' => Project::withCount('versions')->orderBy('name')->get(['id', 'name', 'slug', 'description']),
            'canManage' => $request->user()->can('create', Project::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('catalog/projects/Form', ['project' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $project = Project::create($this->validated($request, null));

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('catalog/projects/Show', [
            'project' => $project->only(['id', 'name', 'slug', 'description']),
            'versions' => $project->versions()->orderByDesc('id')
                ->get(['id', 'version', 'status', 'published_at'])
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'version' => $v->version,
                    'status' => $v->status->value,
                    'published_at' => $v->published_at?->toIso8601String(),
                ]),
            'canManage' => $request->user()->can('update', $project),
        ]);
    }

    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('catalog/projects/Form', [
            'project' => $project->only(['id', 'name', 'slug', 'description']),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($this->validated($request, $project));

        return to_route('projects.show', $project);
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        if ($project->versions()->where('status', VersionStatus::Published)->exists()) {
            throw ValidationException::withMessages([
                'project' => 'The project has published versions and cannot be deleted.',
            ]);
        }

        // Versions must be deleted via Eloquent: the DB cascade would skip the variable cleanup.
        DB::transaction(function () use ($project): void {
            $project->versions->each->delete();
            $project->delete();
        });

        return to_route('projects.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Project $project): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9][a-z0-9-]*\z/', Rule::unique('projects', 'slug')->ignore($project?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'slug.regex' => 'The slug may only contain lowercase letters, digits and hyphens.',
            'slug.unique' => 'This slug is already in use.',
        ]);
    }
}
