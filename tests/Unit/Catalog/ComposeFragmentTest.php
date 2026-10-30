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
    'root list' => ["- a\n- b\n", 'must be a map'],
    'unknown key' => ["services:\n  web: {image: x}\nfoo: 1\n", 'foo'],
    'services list' => ["services:\n  - web\n", 'services: must be a non-empty map'],
    'empty document' => ['', 'must be a map'],
    'scalar root' => ['hello', 'must be a map'],
    'php object tag' => ["services:\n  web: !php/object 'O:8:\"stdClass\":0:{}'\n", 'services.web: must be a map'],
    'php const tag' => ["services:\n  web: {image: !php/const PHP_VERSION}\n", 'services.web: manca "image"'],
    'null service body' => ["services:\n  web:\n", 'services.web: must be a map'],
    'newline in name' => ["services:\n  \"we\\n\": {image: x}\n", 'invalid name'],
    'volumes list' => ["services:\n  web: {image: x}\nvolumes: [a, b]\n", 'volumes: must be a map'],
    'networks scalar' => ["services:\n  web: {image: x}\nnetworks: foo\n", 'networks: must be a map'],
    'missing image' => ["services:\n  web:\n    ports: ['80:80']\n", 'image'],
    'bad name' => ["services:\n  'we b': {image: x}\n", 'we b'],
    'bad x-dada key' => ["services:\n  web: {image: x, x-dada: {foo: 1}}\n", 'x-dada.foo'],
    'bad x-dada init' => ["services:\n  web: {image: x, x-dada: {init: maybe}}\n", 'x-dada.init'],
]);

it('dumps a compose array back to yaml', function () {
    $compose = ['services' => ['web' => ['image' => 'nginx']]];
    expect(ComposeFragment::parse(ComposeFragment::dump($compose)))->toBe($compose);
});
