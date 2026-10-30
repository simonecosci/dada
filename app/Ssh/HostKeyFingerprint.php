<?php

namespace App\Ssh;

use InvalidArgumentException;

final class HostKeyFingerprint
{
    /**
     * Fingerprint "SHA256:<base64 senza padding>" della chiave in una riga known_hosts
     * ("<host> <tipo> <base64> [commento]"), come mostrato da ssh-keygen -l.
     */
    public static function sha256(string $knownHostsLine): string
    {
        $parts = preg_split('/\s+/', trim($knownHostsLine));
        $blob = isset($parts[2]) ? base64_decode($parts[2], true) : false;

        if ($blob === false || $blob === '') {
            throw new InvalidArgumentException('Riga known_hosts non valida.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
