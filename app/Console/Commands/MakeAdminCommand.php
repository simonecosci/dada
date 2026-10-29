<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('dada:admin {email : Email dell\'utente} {--name= : Nome (solo se l\'utente viene creato)}')]
#[Description('Crea un utente admin o promuove un utente esistente ad admin')]
class MakeAdminCommand extends Command
{
    public function handle(): int
    {
        $email = Str::lower((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
            $this->error("Email non valida: {$email}");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill(['is_admin' => true])->save();
            $this->info("{$email} promosso ad admin.");

            return self::SUCCESS;
        }

        $password = Str::password(20);

        User::forceCreate([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->info("Admin {$email} creato.");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }
}
