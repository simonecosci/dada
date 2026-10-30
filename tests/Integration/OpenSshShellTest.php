<?php

use App\Models\Credential;
use App\Models\Host;
use App\Ssh\RemoteShellFactory;

beforeEach(function () {
    if (! env('DADA_SSH_TEST_HOST')) {
        $this->markTestSkipped('DADA_SSH_TEST_HOST not set.');
    }

    $this->host = Host::factory()
        ->for(Credential::factory()->password()->state(['secret' => (string) env('DADA_SSH_TEST_PASSWORD')]))
        ->create([
            'hostname' => env('DADA_SSH_TEST_HOST'),
            'port' => (int) env('DADA_SSH_TEST_PORT', 22),
            'username' => (string) env('DADA_SSH_TEST_USER', 'root'),
            'host_key' => null,
        ]);
});

it('executes commands, uploads files and records the host key', function () {
    $shell = app(RemoteShellFactory::class)->for($this->host);

    try {
        $result = $shell->exec('echo hi');
        expect($result->exitCode)->toBe(0)->and(trim($result->output))->toBe('hi')
            ->and($result->stdout)->toBe("hi\n");

        $mixed = $shell->exec('echo out; echo err >&2');
        expect($mixed->stdout)->toBe("out\n")->and($mixed->output)->toContain('err');

        $shell->put('/tmp/dada-it.txt', 'x', 0600);
        expect(trim($shell->exec('stat -c %a /tmp/dada-it.txt')->output))->toBe('600');

        expect($this->host->fresh()->host_key)->not->toBeEmpty();
    } finally {
        $shell->close();
    }
})->group('ssh-integration');

it('reaches a password target through a key-authenticated bastion', function () {
    if (! env('DADA_SSH_TEST_JUMP_HOST')) {
        $this->markTestSkipped('DADA_SSH_TEST_JUMP_HOST not set.');
    }

    $bastion = Host::factory()
        ->for(Credential::factory()->sshKey()->state(['secret' => (string) file_get_contents((string) env('DADA_SSH_TEST_JUMP_KEY_FILE'))]))
        ->create([
            'hostname' => env('DADA_SSH_TEST_JUMP_HOST'),
            'port' => (int) env('DADA_SSH_TEST_JUMP_PORT', 22),
            'username' => (string) env('DADA_SSH_TEST_JUMP_USER', 'root'),
            'host_key' => null,
        ]);
    $target = Host::factory()
        ->for(Credential::factory()->password()->state(['secret' => (string) env('DADA_SSH_TEST_PASSWORD')]))
        ->create([
            'hostname' => env('DADA_SSH_TEST_JUMP_TARGET'),
            'port' => (int) env('DADA_SSH_TEST_JUMP_TARGET_PORT', 22),
            'username' => (string) env('DADA_SSH_TEST_USER', 'root'),
            'jump_host_id' => $bastion->id,
            'host_key' => null,
        ]);

    $shell = app(RemoteShellFactory::class)->for($target);

    try {
        $result = $shell->exec('hostname; echo via-bastion');

        expect($result->exitCode)->toBe(0, $result->output)
            ->and($result->stdout)->toEndWith("via-bastion\n")
            ->and($result->stdout)->not->toContain('Warning');
        expect($bastion->fresh()->host_key)->not->toBeEmpty()
            ->and($target->fresh()->host_key)->not->toBeEmpty();
    } finally {
        $shell->close();
    }
})->group('ssh-integration');
