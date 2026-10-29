<?php

namespace Database\Factories;

use App\Models\Credential;
use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Host>
 */
class HostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'hostname' => fake()->unique()->domainName(),
            'port' => 22,
            'username' => 'deploy',
            'credential_id' => Credential::factory()->sshKey(),
            'jump_host_id' => null,
            'sudo' => '',
        ];
    }
}
