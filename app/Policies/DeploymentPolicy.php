<?php

namespace App\Policies;

use App\Enums\DeploymentRole;
use App\Models\Deployment;
use App\Models\User;

class DeploymentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Deployment $deployment): bool
    {
        return $user->isAdmin() || $user->roleIn($deployment) !== null;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Deployment $deployment): bool
    {
        return $this->isAdminOrOperator($user, $deployment);
    }

    public function operate(User $user, Deployment $deployment): bool
    {
        return $this->isAdminOrOperator($user, $deployment);
    }

    public function manageMembers(User $user, Deployment $deployment): bool
    {
        return $this->isAdminOrOperator($user, $deployment);
    }

    public function delete(User $user, Deployment $deployment): bool
    {
        return $user->isAdmin();
    }

    private function isAdminOrOperator(User $user, Deployment $deployment): bool
    {
        return $user->isAdmin() || $user->roleIn($deployment) === DeploymentRole::Operator;
    }
}
