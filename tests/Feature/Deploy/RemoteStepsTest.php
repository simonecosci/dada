<?php

use App\Deploy\RemoteSteps;
use App\Models\Credential;
use App\Models\DeploymentHost;
use App\Models\Host;

it('wraps without sudo as a plain cd', function () {
    $dh = hostWithWordpress(['path' => '/srv/x']);

    expect(RemoteSteps::wrap($dh, 'ls'))->toBe("cd '/srv/x' && ls")
        ->and(RemoteSteps::stdin($dh))->toBeNull();
});

it('wraps with sudo and quotes the path through both levels', function () {
    $dh = DeploymentHost::factory()->for(Host::factory()->sudo())->create(['path' => "/srv/o'x"]);

    expect(RemoteSteps::wrap($dh, 'ls'))
        ->toBe("sudo -n -H sh -c 'cd '\''/srv/o'\''\'\'''\''x'\'' && ls'")
        ->and(RemoteSteps::stdin($dh))->toBeNull();
});

it('exposes the sudo password as stdin only for sudo_password hosts', function () {
    $credential = Credential::factory()->password()->create(['secret' => 'pw-1']);
    $dh = DeploymentHost::factory()->for(Host::factory()->sudoPassword($credential))->create(['path' => '/srv/x']);

    expect(RemoteSteps::wrap($dh, 'ls'))->toBe("sudo -S -p '' -H sh -c 'cd '\''/srv/x'\'' && ls'")
        ->and(RemoteSteps::stdin($dh))->toBe("pw-1\n");
});

it('builds the install command with quoting and modes', function () {
    expect(RemoteSteps::installCommand('/tmp/tmp.Ab1', "/srv/o'x/", ['.env' => 0600, 'restart.sh' => 0755]))->toBe(
        "umask 077 && cp '/tmp/tmp.Ab1/.env' '/srv/o'\''x/.env.dada-tmp' && chmod 600 '/srv/o'\''x/.env.dada-tmp' && mv -f '/srv/o'\''x/.env.dada-tmp' '/srv/o'\''x/.env'"
        ." && cp '/tmp/tmp.Ab1/restart.sh' '/srv/o'\''x/restart.sh.dada-tmp' && chmod 755 '/srv/o'\''x/restart.sh.dada-tmp' && mv -f '/srv/o'\''x/restart.sh.dada-tmp' '/srv/o'\''x/restart.sh'"
    );
});
