<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Deployment;
use App\Models\DeployRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeployRun>
 */
class DeployRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'user_id' => User::factory(),
            'type' => RunType::Deploy,
            'status' => RunStatus::Queued,
            'options' => [],
        ];
    }
}
