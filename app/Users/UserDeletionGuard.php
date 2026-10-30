<?php

namespace App\Users;

use App\Enums\DeploymentRole;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Ruling F2: un utente non si cancella se è l'ultimo amministratore o l'unico
 * operatore di un deployment (resterebbe senza nessuno che possa gestirlo).
 */
class UserDeletionGuard
{
    public static function check(User $user): void
    {
        if ($user->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
            throw ValidationException::withMessages(['user' => "Non puoi cancellare l'ultimo amministratore."]);
        }

        $operator = DeploymentRole::Operator->value;
        $orphaned = Deployment::query()
            ->whereHas('members', fn ($q) => $q->whereKey($user->id)->where('deployment_user.role', $operator))
            ->whereDoesntHave('members', fn ($q) => $q->whereKeyNot($user->id)->where('deployment_user.role', $operator))
            ->orderBy('name')
            ->first();

        if ($orphaned !== null) {
            throw ValidationException::withMessages([
                'user' => "L'utente è l'unico operatore del deployment \"{$orphaned->name}\": assegna prima un altro operatore.",
            ]);
        }
    }
}
