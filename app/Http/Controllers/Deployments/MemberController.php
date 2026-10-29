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
use Illuminate\Validation\ValidationException;

class MemberController extends Controller
{
    public function store(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('manageMembers', $deployment);

        $data = $request->validate([
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('deployment_user', 'user_id')->where('deployment_id', $deployment->id),
            ],
            'role' => ['required', Rule::enum(DeploymentRole::class)],
        ], [
            'user_id.unique' => 'Questo utente è già membro del deployment.',
        ]);

        $deployment->members()->attach($data['user_id'], ['role' => $data['role']]);

        return back();
    }

    public function update(Request $request, Deployment $deployment, User $user): RedirectResponse
    {
        Gate::authorize('manageMembers', $deployment);

        $data = $request->validate(['role' => ['required', Rule::enum(DeploymentRole::class)]]);

        DB::transaction(function () use ($deployment, $user, $data): void {
            if ($data['role'] !== DeploymentRole::Operator->value) {
                $this->ensureNotLastOperator($deployment, $user, "Non si può declassare l'ultimo operatore del deployment.");
            }

            $deployment->members()->updateExistingPivot($user->id, ['role' => $data['role']]);
        });

        return back();
    }

    public function destroy(Deployment $deployment, User $user): RedirectResponse
    {
        Gate::authorize('manageMembers', $deployment);

        DB::transaction(function () use ($deployment, $user): void {
            $this->ensureNotLastOperator($deployment, $user, "Non si può rimuovere l'ultimo operatore del deployment.");

            $deployment->members()->detach($user->id);
        });

        return back();
    }

    private function ensureNotLastOperator(Deployment $deployment, User $user, string $message): void
    {
        $operators = $deployment->members()
            ->wherePivot('role', DeploymentRole::Operator->value)
            ->pluck('users.id');

        if ($operators->all() === [$user->id]) {
            throw ValidationException::withMessages(['member' => $message]);
        }
    }
}
