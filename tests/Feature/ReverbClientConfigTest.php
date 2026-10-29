<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the public reverb client config without the secret', function () {
    config([
        'reverb.apps.apps.0.key' => 'pub-key-1',
        'reverb.apps.apps.0.secret' => 'very-secret-9',
        'dada.reverb.host' => 'dada.example.com',
        'dada.reverb.port' => 443,
        'dada.reverb.scheme' => 'https',
    ]);

    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('reverb', [
        'key' => 'pub-key-1',
        'host' => 'dada.example.com',
        'port' => 443,
        'scheme' => 'https',
    ]));
    expect($response->getContent())->not->toContain('very-secret-9');
});

it('leaves unset client values null so the browser falls back to the page origin', function () {
    config(['dada.reverb.host' => null, 'dada.reverb.port' => null, 'dada.reverb.scheme' => null]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('reverb.host', null)->where('reverb.port', null)->where('reverb.scheme', null));
});
