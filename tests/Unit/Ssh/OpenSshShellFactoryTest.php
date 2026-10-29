<?php

use App\Models\Credential;
use App\Models\Host;
use App\Ssh\OpenSsh\OpenSshShellFactory;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShellFactory;

it('is bound as the default remote shell factory', function () {
    expect(app(RemoteShellFactory::class))->toBeInstanceOf(OpenSshShellFactory::class);
});

it('refuses a chain whose jump host no longer uses an ssh key', function () {
    $jump = Host::factory()->create(['name' => 'bastion']);
    $target = Host::factory()->create(['jump_host_id' => $jump->id]);
    $jump->credential->update(['type' => 'password', 'secret' => 'pw']);

    (new OpenSshShellFactory)->for($target);
})->throws(RemoteCommandFailed::class, 'Il jump host "bastion" deve usare una credenziale ssh-key.');

it('refuses a registry credential anywhere in the chain', function () {
    $target = Host::factory()->for(Credential::factory()->registry())->create(['name' => 'web1']);

    (new OpenSshShellFactory)->for($target);
})->throws(RemoteCommandFailed::class, 'L\'host "web1" usa una credenziale registry, non usabile per SSH.');
