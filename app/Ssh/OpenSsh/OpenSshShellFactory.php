<?php

namespace App\Ssh\OpenSsh;

use App\Enums\CredentialType;
use App\Infrastructure\JumpChain;
use App\Models\Host;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShell;
use App\Ssh\RemoteShellFactory;
use InvalidArgumentException;

class OpenSshShellFactory implements RemoteShellFactory
{
    public function for(Host $host): RemoteShell
    {
        try {
            $chain = JumpChain::for($host);
        } catch (InvalidArgumentException $e) {
            throw new RemoteCommandFailed($e->getMessage(), previous: $e);
        }

        $this->validate($chain);

        return new OpenSshShell($chain);
    }

    /**
     * Rivalida la catena al momento della connessione: credenziali e host possono
     * essere stati modificati dopo il salvataggio.
     *
     * @param  list<Host>  $chain
     */
    private function validate(array $chain): void
    {
        $last = array_key_last($chain);

        foreach ($chain as $i => $host) {
            $host->load('credential');
            $credential = $host->credential;

            if ($credential === null) {
                throw new RemoteCommandFailed("L'host \"{$host->name}\" non ha una credenziale.");
            }
            if ($credential->type === CredentialType::Registry) {
                throw new RemoteCommandFailed("L'host \"{$host->name}\" usa una credenziale registry, non usabile per SSH.");
            }
            if ($i !== $last && $credential->type !== CredentialType::SshKey) {
                throw new RemoteCommandFailed("Il jump host \"{$host->name}\" deve usare una credenziale ssh-key.");
            }
            if ($credential->type === CredentialType::SshKey && filled($credential->passphrase)) {
                throw new RemoteCommandFailed("La chiave dell'host \"{$host->name}\" ha una passphrase: chiavi con passphrase non supportate in questa versione.");
            }
        }
    }
}
