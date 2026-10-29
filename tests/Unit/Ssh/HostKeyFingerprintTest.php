<?php

use App\Ssh\HostKeyFingerprint;

it('computes a SHA256 fingerprint from a known_hosts line', function () {
    $line = 'dada-h1 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
    expect(HostKeyFingerprint::sha256($line))->toStartWith('SHA256:')->not->toEndWith('=');
});

it('matches the ssh-keygen fingerprint format', function () {
    $blob = 'AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
    $expected = 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($blob), true)), '=');

    expect(HostKeyFingerprint::sha256("dada-h1 ssh-ed25519 {$blob}"))->toBe($expected);
});

it('rejects a malformed known_hosts line', function () {
    HostKeyFingerprint::sha256('garbage');
})->throws(InvalidArgumentException::class);
