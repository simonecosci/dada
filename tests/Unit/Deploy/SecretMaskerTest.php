<?php

use App\Deploy\SecretMasker;

it('masks longest secrets first and ignores very short ones', function () {
    $m = new SecretMasker(['abc', 'abcdef', 'x']);

    expect($m->mask('pw=abcdef and abc and x'))->toBe('pw=****** and ****** and x');
});

it('returns a new instance with additional secrets', function () {
    $base = new SecretMasker(['first-secret']);
    $extended = $base->with(['second-secret']);

    expect($extended)->not->toBe($base)
        ->and($base->mask('second-secret'))->toBe('second-secret')
        ->and($extended->mask('first-secret second-secret'))->toBe('****** ******');
});

it('masks each line of a multi-line secret', function () {
    $pem = "-----BEGIN KEY-----\nAAAA1111\nBBBB2222\n-----END KEY-----";
    $m = new SecretMasker([$pem]);

    expect($m->mask("x AAAA1111\nBBBB2222 y"))->toBe("x ******\n****** y")
        ->and($m->mask($pem))->toBe('******');
});
