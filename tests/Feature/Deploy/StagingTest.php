<?php

use App\Deploy\Staging;
use App\Ssh\Fake\FakeRemoteShell;

it('opens, fills and removes a staging directory as the login user', function () {
    $shell = (new FakeRemoteShell)->expect('/\Amktemp -d\z/', 0, "/tmp/tmp.Ab1\n");
    $staging = Staging::open($shell);
    $path = $staging->put('.env.wp', 'A=1', 0600);
    $staging->close();
    expect($path)->toBe('/tmp/tmp.Ab1/.env.wp')
        ->and($shell->files())->toBe(['/tmp/tmp.Ab1/.env.wp' => ['contents' => 'A=1', 'mode' => 0600]])
        ->and($shell->commands())->toBe(['mktemp -d', "rm -rf '/tmp/tmp.Ab1'"])
        ->and($shell->stdins())->toBe([null, null]);
});

it('refuses an unexpected mktemp output and unsafe names', function () {
    expect(fn () => Staging::open((new FakeRemoteShell)->expect('/mktemp/', 0, "/tmp/x; rm -rf /\n")))
        ->toThrow(RuntimeException::class, 'unable to create a temporary directory');
    $staging = Staging::open((new FakeRemoteShell)->expect('/mktemp/', 0, "/tmp/t\n"));
    expect(fn () => $staging->put('../x', '', 0600))->toThrow(InvalidArgumentException::class);
});
