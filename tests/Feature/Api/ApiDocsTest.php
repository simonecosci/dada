<?php

use App\Models\User;

it('requires authentication for the API docs', function () {
    $this->get('/docs/api')->assertRedirect(route('login'));
    $this->get('/docs/api.json')->assertRedirect(route('login'));
});

it('renders the API docs for a logged-in user', function () {
    $this->actingAs(User::factory()->create())->get('/docs/api')
        ->assertOk()
        ->assertSee('dada API');
});

it('documents the seven endpoints with bearer authentication', function () {
    $json = $this->actingAs(User::factory()->create())->getJson('/docs/api.json')->assertOk()->json();

    expect($json['info']['title'])->toBe('dada API')
        ->and($json['info']['version'])->toBe('1.0.0')
        ->and($json['components']['securitySchemes'])->toHaveKey('http')
        ->and(array_keys($json['paths']))->toEqualCanonicalizing([
            '/deployments',
            '/deployments/{deployment}',
            '/deployments/{deployment}/runs',
            '/deployments/{deployment}/deploy',
            '/deployment-hosts/{deploymentHost}/restart',
            '/runs/{run}',
            '/runs/{run}/logs',
        ])
        ->and($json['paths']['/deployments/{deployment}/deploy']['post']['responses'])->toHaveKeys(['202', '401', '403', '404', '409', '422', '503'])
        ->and($json['paths']['/deployments/{deployment}/deploy']['post'])->toHaveKey('requestBody')
        ->and($json['paths']['/deployment-hosts/{deploymentHost}/restart']['post'])->toHaveKey('requestBody');
});
