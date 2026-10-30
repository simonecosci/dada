<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Crea il primo admin da DADA_ADMIN_* (lanciato dal container init a ogni avvio).
 * Idempotente: se esiste gia' un admin non fa nulla, quindi cambiare la password
 * nel .env dopo la prima installazione non ha effetto.
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
            $this->command?->warn('DADA_ADMIN_EMAIL non impostata: nessun admin creato (usa `php artisan dada:admin <email>`).');

            return;
        }

        if (Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
            throw new InvalidArgumentException("DADA_ADMIN_EMAIL non valida: {$email}");
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill(['is_admin' => true])->save();
            $this->command?->info("{$email} promosso ad admin.");

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

        $this->command?->info("Admin {$email} creato.");

        if ($generated) {
            $this->command?->line("Password: {$password}");
        }
    }
}
