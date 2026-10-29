<?php

use App\Catalog\ComposeFragment;
use App\Catalog\InvalidComposeFragment;

it('parses a valid fragment', function () {
    $c = ComposeFragment::parse("services:\n  web:\n    image: nginx:\${NGINX_TAG}\n    x-dada: { init: false }\nvolumes:\n  data:\n");
    expect($c['services']['web']['image'])->toBe('nginx:${NGINX_TAG}')->and($c)->toHaveKey('volumes');
    expect($c['services']['web']['x-dada'])->toBe(['init' => false]);
});

it('rejects invalid input with explicit errors', function (string $yaml, string $needle) {
    try {
        ComposeFragment::parse($yaml);
        $this->fail('expected exception');
    } catch (InvalidComposeFragment $e) {
        expect(implode("\n", $e->errors()))->toContain($needle);
    }
})->with([
    'syntax' => ["services:\n  web:\n\timage: x\n", 'line'],
    'root list' => ["- a\n- b\n", 'mappa'],
    'unknown key' => ["services:\n  web: {image: x}\nfoo: 1\n", 'foo'],
    'services list' => ["services:\n  - web\n", 'services'],
    'missing image' => ["services:\n  web:\n    ports: ['80:80']\n", 'image'],
    'bad name' => ["services:\n  'we b': {image: x}\n", 'we b'],
    'bad x-dada key' => ["services:\n  web: {image: x, x-dada: {foo: 1}}\n", 'x-dada.foo'],
    'bad x-dada init' => ["services:\n  web: {image: x, x-dada: {init: maybe}}\n", 'x-dada.init'],
]);

it('dumps a compose array back to yaml', function () {
    $compose = ['services' => ['web' => ['image' => 'nginx']]];
    expect(ComposeFragment::parse(ComposeFragment::dump($compose)))->toBe($compose);
});
