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
     * Revalidates the chain at connection time: credentials and hosts may
     * have been modified after saving.
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
                throw new RemoteCommandFailed("Host \"{$host->name}\" has no credential.");
            }
            if ($credential->type === CredentialType::Registry) {
                throw new RemoteCommandFailed("Host \"{$host->name}\" uses a registry credential, which cannot be used for SSH.");
            }
            if ($i !== $last && $credential->type !== CredentialType::SshKey) {
                throw new RemoteCommandFailed("The jump host \"{$host->name}\" must use an ssh-key credential.");
            }
            if ($credential->type === CredentialType::SshKey && filled($credential->passphrase)) {
                throw new RemoteCommandFailed("The key of host \"{$host->name}\" has a passphrase: keys with a passphrase are not supported in this version.");
            }
        }
    }
}
