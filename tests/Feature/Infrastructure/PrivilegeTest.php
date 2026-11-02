<?php

use App\Enums\PrivilegeMode;
use App\Infrastructure\Privilege;
use App\Models\Credential;
use App\Models\Host;

it('builds the command for each mode', function (PrivilegeMode $mode, string $expected) {
    expect((new Privilege($mode, 'pw'))->wrap("echo 'a'"))->toBe($expected);
})->with([
    [PrivilegeMode::None, "echo 'a'"],
    [PrivilegeMode::Sudo, "sudo -n -H sh -c 'exec </dev/null; echo '\''a'\'''"],
    [PrivilegeMode::SudoPassword, "sudo -S -p '' -H sh -c 'exec </dev/null; echo '\''a'\'''"],
]);

it('sends only the password on stdin', function () {
    expect((new Privilege(PrivilegeMode::SudoPassword, "p'a\$s w\"d\\"))->stdin())->toBe("p'a\$s w\"d\\\n")
        ->and((new Privilege(PrivilegeMode::Sudo))->stdin())->toBeNull()
        ->and((new Privilege(PrivilegeMode::None))->stdin())->toBeNull();
});

it('exposes the validation command and the secrets', function () {
    expect((new Privilege(PrivilegeMode::SudoPassword, 'pw'))->validateCommand())->toBe("sudo -S -p '' -v")
        ->and((new Privilege(PrivilegeMode::Sudo))->validateCommand())->toBe('sudo -n -v')
        ->and((new Privilege(PrivilegeMode::None))->validateCommand())->toBeNull()
        ->and((new Privilege(PrivilegeMode::SudoPassword, 'pw'))->secrets())->toBe(['pw'])
        ->and((new Privilege(PrivilegeMode::Sudo))->secrets())->toBe([]);
});

it('reads the password from the sudo credential', function () {
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => 's3cret']))->create();
    expect(Privilege::for($host)->stdin())->toBe("s3cret\n");
});

it('fails clearly when the sudo credential is missing', function () {
    $host = Host::factory()->create(['privilege_mode' => PrivilegeMode::SudoPassword, 'sudo_credential_id' => null]);
    expect(fn () => Privilege::for($host))->toThrow(RuntimeException::class, 'the sudo password credential is missing');
});

it('rejects a sudo password with line breaks', function (string $secret) {
    $host = Host::factory()->sudoPassword(Credential::factory()->password()->create(['secret' => $secret]))->create(['name' => 'h1']);
    expect(fn () => Privilege::for($host))->toThrow(RuntimeException::class, 'host h1: the sudo password must not contain line breaks');
})->with([
    'newline' => ["pw\nid"],
    'carriage return' => ["pw\rid"],
    'trailing newline' => ["pw\n"],
]);

it('keeps the password away from the stdin of the command when sudo does not read it', function (PrivilegeMode $mode) {
    // A sudo that never reads stdin (NOPASSWD, cached timestamp): it just runs `sh -c <cmd>`.
    $script = "sudo() { while [ \"\$1\" != sh ]; do shift; done; \"\$@\"; }\n"
        ."printf 'pw\\n' | ".(new Privilege($mode, 'pw'))->wrap('cat; echo end')."\n";

    $r = runBashScript(['t.sh' => $script], 't.sh');

    expect($r['exit'])->toBe(0, $r['output'])->and(trim($r['output']))->toBe('end');
})->with([PrivilegeMode::Sudo, PrivilegeMode::SudoPassword]);
