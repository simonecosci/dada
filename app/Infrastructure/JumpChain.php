<?php

namespace App\Infrastructure;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Host;
use InvalidArgumentException;

class JumpChain
{
    /**
     * Hosts from the first hop to the target (included).
     *
     * @return list<Host>
     */
    public static function for(Host $host): array
    {
        $chain = [];
        $seen = [];
        $current = $host;

        while ($current !== null) {
            if (isset($seen[$current->id])) {
                throw new InvalidArgumentException('Ciclo nella catena dei jump host.');
            }
            $seen[$current->id] = true;
            array_unshift($chain, $current);
            $current = $current->jump_host_id ? Host::find($current->jump_host_id) : null;
        }

        return $chain;
    }

    /**
     * Error message if $host cannot use $jumpHostId as jump host, null otherwise.
     * $host may be unsaved.
     */
    public static function validate(Host $host, ?int $jumpHostId): ?string
    {
        if ($host->credential_id !== null
            && Credential::find($host->credential_id)?->type === CredentialType::Registry) {
            return 'Una credenziale registry non è usabile per SSH.';
        }

        $seen = [];
        $currentId = $jumpHostId;

        while ($currentId !== null) {
            if (($host->exists && $currentId === $host->id) || isset($seen[$currentId])) {
                return 'La catena di jump host contiene un ciclo.';
            }
            $seen[$currentId] = true;

            $hop = Host::with('credential')->find($currentId);
            if ($hop === null) {
                return 'Jump host inesistente.';
            }

            $type = $hop->credential->type;
            if ($type === CredentialType::Registry) {
                return 'Una credenziale registry non è usabile per SSH.';
            }
            if ($type !== CredentialType::SshKey) {
                return "Il jump host \"{$hop->name}\" deve usare una credenziale ssh-key.";
            }

            $currentId = $hop->jump_host_id;
        }

        return null;
    }
}
