<?php

namespace App\Ssh\OpenSsh;

use App\Enums\CredentialType;
use App\Models\Host;
use App\Ssh\RemoteCommandFailed;

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

        $knownHostsFile = $this->safe($knownHostsFile, 'il file known_hosts', null);
        $controlDir = $this->safe($controlDir, 'la directory di controllo', null);

        foreach ($chain as $host) {
            $lines = [
                'Host '.self::alias($host),
                'HostName '.$this->safe((string) $host->hostname, 'l\'hostname', $host),
                'Port '.(int) $host->port,
                'User '.$this->safe((string) $host->username, 'lo username', $host),
                'HostKeyAlias '.self::alias($host),
            ];

            if ($previous !== null) {
                $lines[] = 'ProxyJump '.self::alias($previous);
            }

            if ($host->credential?->type === CredentialType::Password) {
                array_push($lines,
                    'PreferredAuthentications password',
                    'PubkeyAuthentication no',
                    'KbdInteractiveAuthentication no',
                    'NumberOfPasswordPrompts 1',
                );
            } else {
                // Gli hop a chiave non devono mai ripiegare su password: sshpass
                // invierebbe la password del target al jump host.
                if (isset($identityFiles[$host->id])) {
                    $lines[] = 'IdentityFile '.$this->safe($identityFiles[$host->id], 'il file della chiave', $host);
                    $lines[] = 'IdentitiesOnly yes';
                }
                array_push($lines,
                    'PreferredAuthentications publickey',
                    'PasswordAuthentication no',
                    'KbdInteractiveAuthentication no',
                    'BatchMode yes',
                );
            }

            array_push($lines,
                'UserKnownHostsFile '.$knownHostsFile,
                'GlobalKnownHostsFile /dev/null',
                'StrictHostKeyChecking accept-new',
                'HashKnownHosts no',
                // Niente "Warning: Permanently added ..." e simili su stderr.
                'LogLevel ERROR',
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

    /**
     * Rifiuta valori che potrebbero iniettare direttive ssh_config o token (%).
     */
    private function safe(string $value, string $what, ?Host $host): string
    {
        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F%"]/', $value)) {
            $where = $host !== null ? " dell'host \"{$host->name}\"" : '';

            throw new RemoteCommandFailed("Valore non valido per {$what}{$where}: spazi, caratteri di controllo, % e \" non sono ammessi.");
        }

        return $value;
    }
}
