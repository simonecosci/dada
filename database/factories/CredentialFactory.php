<?php

namespace Database\Factories;

use App\Enums\CredentialType;
use App\Models\Credential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'type' => CredentialType::SshKey,
            'username' => null,
            'secret' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".fake()->sha256()."\n-----END OPENSSH PRIVATE KEY-----",
            'passphrase' => null,
            'registry_url' => null,
        ];
    }

    public function sshKey(): static
    {
        return $this->state(fn () => ['type' => CredentialType::SshKey]);
    }

    public function password(): static
    {
        return $this->state(fn () => ['type' => CredentialType::Password, 'secret' => fake()->password(12)]);
    }

    public function registry(): static
    {
        return $this->state(fn () => [
            'type' => CredentialType::Registry,
            'username' => fake()->userName(),
            'secret' => fake()->sha256(),
            'registry_url' => 'ghcr.io',
        ]);
    }
}
