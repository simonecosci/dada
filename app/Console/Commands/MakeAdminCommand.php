<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('dada:admin {email : User email} {--name= : Name (only if the user is created)}')]
#[Description('Create an admin user or promote an existing user to admin')]
class MakeAdminCommand extends Command
{
    public function handle(): int
    {
        $email = Str::lower((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
            $this->error("Invalid email: {$email}");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill(['is_admin' => true])->save();
            $this->info("{$email} promoted to admin.");

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

        $this->info("Admin {$email} created.");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }
}
