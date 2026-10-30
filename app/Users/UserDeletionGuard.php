<?php

namespace App\Users;

use App\Enums\DeploymentRole;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Ruling F2: a user cannot be deleted if they are the last administrator or the only
 * operator of a deployment (it would be left with no one able to manage it).
 */
class UserDeletionGuard
{
    public static function check(User $user): void
    {
        if ($user->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'You cannot delete the last administrator.']);
        }

        $operator = DeploymentRole::Operator->value;
        $orphaned = Deployment::query()
            ->whereHas('members', fn ($q) => $q->whereKey($user->id)->where('deployment_user.role', $operator))
            ->whereDoesntHave('members', fn ($q) => $q->whereKeyNot($user->id)->where('deployment_user.role', $operator))
            ->orderBy('name')
            ->first();

        if ($orphaned !== null) {
            throw ValidationException::withMessages([
                'user' => "The user is the only operator of the deployment \"{$orphaned->name}\": assign another operator first.",
            ]);
        }
    }
}
