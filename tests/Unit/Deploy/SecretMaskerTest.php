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
