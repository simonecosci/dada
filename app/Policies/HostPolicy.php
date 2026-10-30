<?php

namespace App\Policies;

use App\Models\Host;
use App\Models\User;

class HostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Host $host): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Host $host): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Host $host): bool
    {
        return $user->isAdmin();
    }
}
