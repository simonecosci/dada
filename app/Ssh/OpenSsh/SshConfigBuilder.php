<?php

namespace App\Ssh\OpenSsh;

use App\Enums\CredentialType;
use App\Models\Host;

class SshConfigBuilder
{
    public static function alias(Host $host): string
    {
        return 'dada-h'.$host->id;
    }

    /**
     * Contenuto ssh_config con un blocco "Host dada-h{id}" per ogni salto.
     *
     * @param  list<Host>  $chain  dal primo salto al target
     * @param  array<int, string>  $identityFiles  host id => path della chiave privata
     */
    public function build(array $chain, array $identityFiles, string $knownHostsFile, string $controlDir): string
    {
        $blocks = [];
        $previous = null;

        foreach ($chain as $host) {
            $lines = [
                'Host '.self::alias($host),
                'HostName '.$host->hostname,
                'Port '.$host->port,
                'User '.$host->username,
                'HostKeyAlias '.self::alias($host),
            ];

            if ($previous !== null) {
                $lines[] = 'ProxyJump '.self::alias($previous);
            }

            if ($host->credential?->type === CredentialType::Password) {
                $lines[] = 'PreferredAuthentications password';
                $lines[] = 'PubkeyAuthentication no';
            } elseif (isset($identityFiles[$host->id])) {
                $lines[] = 'IdentityFile '.$identityFiles[$host->id];
                $lines[] = 'IdentitiesOnly yes';
            }

            array_push($lines,
                'UserKnownHostsFile '.$knownHostsFile,
                'StrictHostKeyChecking accept-new',
                'HashKnownHosts no',
                'ConnectTimeout '.(int) config('dada.ssh.connect_timeout'),
                'ServerAliveInterval 15',
                'ControlMaster auto',
                'ControlPath '.$controlDir.'/cm-%C',
                'ControlPersist 60',
            );

            $blocks[] = $lines[0]."\n".implode("\n", array_map(fn (string $l) => '    '.$l, array_slice($lines, 1)));
            $previous = $host;
        }

        return implode("\n\n", $blocks)."\n";
    }
}
