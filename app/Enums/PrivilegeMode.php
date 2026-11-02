<?php

namespace App\Enums;

enum PrivilegeMode: string
{
    case None = 'none';
    case Sudo = 'sudo';
    case SudoPassword = 'sudo_password';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None (root or a user with Docker access)',
            self::Sudo => 'sudo without password',
            self::SudoPassword => 'sudo with password',
        };
    }
}
