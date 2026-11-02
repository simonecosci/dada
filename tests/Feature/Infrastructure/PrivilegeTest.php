<?php

use App\Enums\PrivilegeMode;
use App\Infrastructure\Privilege;
use App\Models\Credential;
use App\Models\Host;

it('builds the command for each mode', function (PrivilegeMode $mode, string $expected) {
    expect((new Privilege($mode, 'pw'))->wrap("echo 'a'"))->toBe($expected);
})->with([
    [PrivilegeMode::None, "echo 'a'"],
    [PrivilegeMode::Sudo, "sudo -n -H sh -c 'echo '\''a'\'''"],
    [PrivilegeMode::SudoPassword, "sudo -S -p '' -H sh -c 'echo '\''a'\'''"],
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
