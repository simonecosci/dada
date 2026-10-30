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
     * ssh_config content with an "Host dada-h{id}" block for each hop.
     *
     * @param  list<Host>  $chain  from the first hop to the target
     * @param  array<int, string>  $identityFiles  host id => private key path
     */
    public function build(array $chain, array $identityFiles, string $knownHostsFile, string $controlDir): string
    {
        $blocks = [];
        $previous = null;

        $knownHostsFile = $this->safe($knownHostsFile, 'the known_hosts file', null);
        $controlDir = $this->safe($controlDir, 'the control directory', null);

        foreach ($chain as $host) {
            $lines = [
                'Host '.self::alias($host),
                'HostName '.$this->safe((string) $host->hostname, 'l\'hostname', $host),
                'Port '.(int) $host->port,
                'User '.$this->safe((string) $host->username, 'the username', $host),
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
                // Key hops must never fall back to password: sshpass
                // would send the target's password to the jump host.
                if (isset($identityFiles[$host->id])) {
                    $lines[] = 'IdentityFile '.$this->safe($identityFiles[$host->id], 'the key file', $host);
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
                // No "Warning: Permanently added ..." and the like on stderr.
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
     * Rejects values that could inject ssh_config directives or tokens (%).
     */
    private function safe(string $value, string $what, ?Host $host): string
    {
        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F%"]/', $value)) {
            $where = $host !== null ? " of host \"{$host->name}\"" : '';

            throw new RemoteCommandFailed("Invalid value for {$what}{$where}: spaces, control characters, % and \" are not allowed.");
        }

        return $value;
    }
}
