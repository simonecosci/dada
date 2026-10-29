<?php

namespace App\Http\Controllers\Deployments;

use App\Enums\DeploymentRole;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Deployment::class);

        return Inertia::render('deployments/Index', [
            'deployments' => Deployment::visibleTo($request->user())->orderBy('name')
                ->get(['id', 'name', 'slug', 'description', 'environment']),
            'canCreate' => $request->user()->can('create', Deployment::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Deployment::class);

        return Inertia::render('deployments/Form', ['deployment' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Deployment::class);

        $data = $this->validated($request, null);

        $deployment = DB::transaction(function () use ($data, $request): Deployment {
            $deployment = Deployment::create($data);
            $deployment->members()->attach($request->user(), ['role' => DeploymentRole::Operator->value]);

            return $deployment;
        });

        return to_route('deployments.show', $deployment);
    }

    public function show(Request $request, Deployment $deployment): Response
    {
        Gate::authorize('view', $deployment);

        $canManageMembers = $request->user()->can('manageMembers', $deployment);
        $members = $deployment->members()->orderBy('name')->get();

        return Inertia::render('deployments/Show', [
            'deployment' => $deployment->only(['id', 'name', 'slug', 'description', 'environment']),
            'members' => $members->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role,
            ])->values(),
            'candidates' => $canManageMembers
                ? User::whereNotIn('id', $members->modelKeys())->orderBy('name')->get(['id', 'name', 'email'])
                : [],
            'hosts' => $request->user()->can('operate', $deployment)
                ? $deployment->hosts()->with('host:id,name,hostname')->withCount('installations')->orderBy('id')->get()->map(fn ($dh) => [
                    'id' => $dh->id,
                    'name' => $dh->host->name,
                    'hostname' => $dh->host->hostname,
                    'path' => $dh->path,
                    'orchestrator' => $dh->orchestrator->value,
                    'installations_count' => $dh->installations_count,
                ])->values()
                : [],
            'canOperate' => $request->user()->can('operate', $deployment),
            'canUpdate' => $request->user()->can('update', $deployment),
            'canDelete' => $request->user()->can('delete', $deployment),
            'canManageMembers' => $canManageMembers,
        ]);
    }

    public function edit(Deployment $deployment): Response
    {
        Gate::authorize('update', $deployment);

        return Inertia::render('deployments/Form', [
            'deployment' => $deployment->only(['id', 'name', 'slug', 'description', 'environment']),
        ]);
    }

    public function update(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('update', $deployment);

        $deployment->update($this->validated($request, $deployment));

        return to_route('deployments.show', $deployment);
    }

    public function destroy(Deployment $deployment): RedirectResponse
    {
        Gate::authorize('delete', $deployment);

        // Passa da Eloquent, così gli hook di HasVariables rimuovono anche le variabili.
        DB::transaction(function () use ($deployment): void {
            $deployment->hosts()->get()->each->delete();
            $deployment->delete();
        });

        return to_route('deployments.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Deployment $deployment): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9][a-z0-9-]*\z/', Rule::unique('deployments', 'slug')->ignore($deployment?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'environment' => ['nullable', 'string', 'max:255'],
        ], [
            'slug.regex' => 'Lo slug può contenere solo minuscole, cifre e trattini.',
            'slug.unique' => 'Questo slug è già in uso.',
        ]);
    }
}
