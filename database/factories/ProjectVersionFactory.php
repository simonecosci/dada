<?php

namespace Database\Factories;

use App\Enums\VersionStatus;
use App\Models\Project;
use App\Models\ProjectVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectVersion>
 */
class ProjectVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => fake()->unique()->numerify('#.#.#'),
            'notes' => null,
            'status' => VersionStatus::Draft,
            'compose' => ['services' => ['web' => ['image' => 'nginx:latest']]],
            'commands' => [],
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => VersionStatus::Published,
            'published_at' => now(),
        ]);
    }
}
