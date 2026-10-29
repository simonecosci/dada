<?php

use App\Ssh\OpenSsh\OpenSshShell;

it('writes put() temp files under umask 077 with POSIX quoting', function () {
    expect(OpenSshShell::putCommand("/srv/o'x/.env", 0600))
        ->toBe("umask 077; cat > '/srv/o'\''x/.env.dada-tmp' && chmod 600 '/srv/o'\''x/.env.dada-tmp' && mv '/srv/o'\''x/.env.dada-tmp' '/srv/o'\''x/.env'");
});
