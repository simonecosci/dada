<?php

namespace Database\Factories;

use App\Models\DeployRun;
use App\Models\DeployRunLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeployRunLog>
 */
class DeployRunLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deploy_run_id' => DeployRun::factory(),
            'deployment_host_id' => null,
            'level' => 'info',
            'message' => fake()->sentence(),
            'created_at' => now(),
        ];
    }
}
