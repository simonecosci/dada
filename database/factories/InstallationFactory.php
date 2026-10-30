<?php

namespace Database\Factories;

use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Models\ProjectVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installation>
 */
class InstallationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deployment_host_id' => DeploymentHost::factory(),
            'project_version_id' => ProjectVersion::factory()->published(),
            'slug' => fake()->unique()->slug(2),
            'order' => 1,
            'compose' => ['services' => ['web' => ['image' => 'nginx:latest']]],
            'commands' => [],
        ];
    }
}
