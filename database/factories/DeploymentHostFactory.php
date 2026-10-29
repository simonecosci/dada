<?php

namespace Database\Factories;

use App\Enums\Orchestrator;
use App\Models\Deployment;
use App\Models\DeploymentHost;
use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentHost>
 */
class DeploymentHostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'host_id' => Host::factory(),
            'orchestrator' => Orchestrator::Compose,
            'path' => fn () => '/srv/'.fake()->unique()->slug(2),
            'stack_name' => fn (array $attrs) => Deployment::find($attrs['deployment_id'])->slug,
            'compose_template' => [],
        ];
    }
}
