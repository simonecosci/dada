<?php

namespace Database\Factories;

use App\Enums\PrivilegeMode;
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
            'privilege_mode' => PrivilegeMode::None,
        ];
    }

    public function sudo(): static
    {
        return $this->state(fn () => ['privilege_mode' => PrivilegeMode::Sudo]);
    }

    public function sudoPassword(?Credential $credential = null): static
    {
        return $this->state(fn () => [
            'privilege_mode' => PrivilegeMode::SudoPassword,
            'sudo_credential_id' => $credential?->id ?? Credential::factory()->password(),
        ]);
    }
}
