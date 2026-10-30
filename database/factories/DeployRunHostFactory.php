<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Models\DeployRunHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeployRunHost>
 */
class DeployRunHostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deploy_run_id' => DeployRun::factory(),
            'deployment_host_id' => DeploymentHost::factory(),
            'status' => RunStatus::Queued,
        ];
    }
}
