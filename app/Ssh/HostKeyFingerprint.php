<?php

namespace App\Ssh;

use InvalidArgumentException;

final class HostKeyFingerprint
{
    /**
     * Fingerprint "SHA256:<base64 without padding>" of the key in a known_hosts line
     * ("<host> <type> <base64> [comment]"), as shown by ssh-keygen -l.
     */
    public static function sha256(string $knownHostsLine): string
    {
        $parts = preg_split('/\s+/', trim($knownHostsLine));
        $blob = isset($parts[2]) ? base64_decode($parts[2], true) : false;

        if ($blob === false || $blob === '') {
            throw new InvalidArgumentException('Invalid known_hosts line.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
