<?php

namespace Database\Factories;

use App\Enums\VariableScope;
use App\Models\ProjectVersion;
use App\Models\Variable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variable>
 */
class VariableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_type' => ProjectVersion::class,
            'owner_id' => ProjectVersion::factory(),
            'key' => strtoupper(fake()->unique()->lexify('VAR_????????')),
            'default' => null,
            'value' => null,
            'use_default' => true,
            'description' => null,
            'required' => false,
            'secret' => false,
            'scope' => VariableScope::Container,
            'position' => 0,
        ];
    }

    public function secret(): static
    {
        return $this->state(fn () => ['secret' => true]);
    }
}
