<?php

namespace App\Policies;

use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Models\ProjectVersion;
use App\Models\User;
use App\Models\Variable;

class VariablePolicy
{
    public function reveal(User $user, Variable $variable): bool
    {
        $owner = $variable->owner;

        return match (true) {
            $owner instanceof ProjectVersion => $user->isMaintainer(),
            $owner instanceof Installation => $user->can('operate', $owner->deploymentHost->deployment),
            $owner instanceof DeploymentHost => $user->can('operate', $owner->deployment),
            default => false,
        };
    }
}
