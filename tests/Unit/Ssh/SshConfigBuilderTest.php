<?php

use App\Models\Credential;
use App\Models\Host;
use App\Ssh\OpenSsh\SshConfigBuilder;
use App\Ssh\RemoteCommandFailed;

it('builds a config block per hop with ProxyJump and HostKeyAlias', function () {
    $jump = Host::factory()->for(Credential::factory()->sshKey())->create(['hostname' => 'bastion.example.com']);
    $t = Host::factory()->for(Credential::factory()->password())->create(['hostname' => '10.0.0.5', 'port' => 2222, 'jump_host_id' => $jump->id]);
    $cfg = (new SshConfigBuilder)->build([$jump, $t], [$jump->id => '/tmp/x/id_h'.$jump->id], '/tmp/x/known_hosts', '/tmp/x');
    expect($cfg)
        ->toContain("Host dada-h{$t->id}")->toContain('HostName 10.0.0.5')->toContain('Port 2222')
        ->toContain("ProxyJump dada-h{$jump->id}")->toContain("HostKeyAlias dada-h{$t->id}")
        ->toContain("IdentityFile /tmp/x/id_h{$jump->id}")->toContain('PreferredAuthentications password');
});

it('emits the common connection options for every hop', function () {
    config(['dada.ssh.connect_timeout' => 7]);
    $jump = Host::factory()->create(['hostname' => 'bastion.example.com', 'username' => 'ops']);
    $t = Host::factory()->create(['jump_host_id' => $jump->id]);

    $cfg = (new SshConfigBuilder)->build([$jump, $t], [$jump->id => '/k/a', $t->id => '/k/b'], '/k/known_hosts', '/k');

    expect(substr_count($cfg, 'StrictHostKeyChecking accept-new'))->toBe(2)
        ->and($cfg)->toContain('User ops')->toContain('IdentitiesOnly yes')
        ->toContain('UserKnownHostsFile /k/known_hosts')->toContain('HashKnownHosts no')
        ->toContain('ConnectTimeout 7')->toContain('ServerAliveInterval 15')
        ->toContain('ControlMaster auto')->toContain('ControlPath /k/cm-%C')->toContain('ControlPersist 60')
        ->not->toContain('PubkeyAuthentication no');

    // the first hop has no ProxyJump
    $firstBlock = explode("Host dada-h{$t->id}", $cfg)[0];
    expect($firstBlock)->not->toContain('ProxyJump');
});

it('disables pubkey auth for password hosts', function () {
    $t = Host::factory()->for(Credential::factory()->password())->create();

    $cfg = (new SshConfigBuilder)->build([$t], [], '/k/known_hosts', '/k');

    expect($cfg)->toContain('PreferredAuthentications password')->toContain('PubkeyAuthentication no')
        ->toContain('KbdInteractiveAuthentication no')->toContain('NumberOfPasswordPrompts 1')
        ->toContain('GlobalKnownHostsFile /dev/null')
        ->not->toContain('IdentityFile')->not->toContain('BatchMode yes');
});

it('restricts key hops to publickey so the target password never reaches a jump host', function () {
    $jump = Host::factory()->create();
    $t = Host::factory()->for(Credential::factory()->password())->create(['jump_host_id' => $jump->id]);

    $cfg = (new SshConfigBuilder)->build([$jump, $t], [$jump->id => '/k/a'], '/k/known_hosts', '/k');
    [$jumpBlock, $targetBlock] = explode("Host dada-h{$t->id}", $cfg);

    expect($jumpBlock)->toContain('PreferredAuthentications publickey')->toContain('PasswordAuthentication no')
        ->toContain('KbdInteractiveAuthentication no')->toContain('BatchMode yes')
        ->toContain('GlobalKnownHostsFile /dev/null')
        ->and($targetBlock)->toContain('PreferredAuthentications password')->not->toContain('BatchMode');
});

it('rejects values that could inject ssh_config directives', function (array $attributes) {
    $t = Host::factory()->create();
    $t->forceFill($attributes); // bypassa la validazione del controller

    (new SshConfigBuilder)->build([$t], [], '/k/known_hosts', '/k');
})->with([
    'newline in hostname' => [['hostname' => "x\nProxyCommand sh -c 'id > /tmp/p'"]],
    'newline in username' => [['username' => "deploy\nProxyCommand id"]],
    'space in hostname' => [['hostname' => 'a b']],
    'percent token' => [['username' => 'u%d']],
    'double quote' => [['hostname' => 'a"b']],
])->throws(RemoteCommandFailed::class, 'Valore non valido');

it('rejects unsafe paths', function () {
    $t = Host::factory()->create();

    (new SshConfigBuilder)->build([$t], [], '/k/known hosts', '/k');
})->throws(RemoteCommandFailed::class, 'Valore non valido');
