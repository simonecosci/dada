<?php

namespace App\Policies;

use App\Models\ProjectVersion;
use App\Models\User;
use App\Models\Variable;

class VariablePolicy
{
    public function reveal(User $user, Variable $variable): bool
    {
        return match (true) {
            $variable->owner instanceof ProjectVersion => $user->isMaintainer(),
            default => false,
        };
    }
}
