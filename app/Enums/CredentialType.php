<?php

namespace App\Enums;

enum CredentialType: string
{
    case Password = 'password';
    case SshKey = 'ssh-key';
    case Registry = 'registry';

    public function label(): string
    {
        return match ($this) {
            self::Password => 'Password SSH',
            self::SshKey => 'Chiave SSH',
            self::Registry => 'Registry',
        };
    }
}
