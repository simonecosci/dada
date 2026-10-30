<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates the first admin from DADA_ADMIN_* (run by the init container at every start).
 * Idempotent: if an admin already exists it does nothing, so changing the password
 * in the .env after the first installation has no effect.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('is_admin', true)->exists()) {
            return;
        }

        $email = Str::lower((string) config('dada.admin.email'));

        if ($email === '') {
            $this->command?->warn('DADA_ADMIN_EMAIL not set: no admin created (use `php artisan dada:admin <email>`).');

            return;
        }

        if (Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
            throw new InvalidArgumentException("DADA_ADMIN_EMAIL is invalid: {$email}");
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill(['is_admin' => true])->save();
            $this->command?->info("{$email} promoted to admin.");

            return;
        }

        $password = config('dada.admin.password');
        $generated = blank($password);

        if ($generated) {
            $password = Str::password(20);
        }

        User::forceCreate([
            'name' => config('dada.admin.name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->command?->info("Admin {$email} created.");

        if ($generated) {
            $this->command?->line("Password: {$password}");
        }
    }
}
