<?php

use App\Infrastructure\JumpChain;
use App\Models\Credential;
use App\Models\Host;
use App\Ssh\OpenSsh\OpenSshShell;

function shellState(OpenSshShell $shell, string $property): mixed
{
    return (new ReflectionProperty($shell, $property))->getValue($shell);
}

beforeEach(function () {
    config(['dada.ssh.tmp_path' => sys_get_temp_dir().'/dada-ssh-test']);
});

it('writes keys with a trailing newline, known hosts and config, and cleans up on close', function () {
    $jump = Host::factory()->for(Credential::factory()->sshKey()->state(['secret' => "KEY-A\r\nLINE"]))
        ->create(['host_key' => 'dada-h0 ssh-ed25519 AAAA']);
    $target = Host::factory()->for(Credential::factory()->password())->create(['jump_host_id' => $jump->id]);

    $shell = new OpenSshShell(JumpChain::for($target->load('credential')));
    $dir = shellState($shell, 'workDir');

    expect(file_get_contents("{$dir}/id_h{$jump->id}"))->toBe("KEY-A\nLINE\n")
        ->and(file_exists("{$dir}/id_h{$target->id}"))->toBeFalse()
        ->and(file_get_contents("{$dir}/known_hosts"))->toBe("dada-h0 ssh-ed25519 AAAA\n")
        ->and(file_get_contents("{$dir}/config"))->toContain("Host dada-h{$target->id}")
        ->toContain("ProxyJump dada-h{$jump->id}");

    $shell->close();

    expect(is_dir($dir))->toBeFalse()->and(is_dir(shellState($shell, 'controlDir')))->toBeFalse();
});

it('saves newly accepted host keys only for hosts without one (TOFU)', function () {
    $jump = Host::factory()->create(['host_key' => 'old-line']);
    $target = Host::factory()->create(['jump_host_id' => $jump->id, 'host_key' => null]);

    $shell = new OpenSshShell(JumpChain::for($target));
    file_put_contents(shellState($shell, 'knownHostsFile'),
        "old-line\n"
        ."dada-h{$jump->id} ssh-ed25519 JUMPKEY\n"
        ."dada-h{$target->id} ssh-ed25519 TARGETKEY\n");

    (new ReflectionMethod($shell, 'recordNewHostKeys'))->invoke($shell);
    $shell->close();

    expect($target->fresh()->host_key)->toBe("dada-h{$target->id} ssh-ed25519 TARGETKEY")
        ->and($jump->fresh()->host_key)->toBe('old-line');
});
