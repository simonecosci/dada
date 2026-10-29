<?php

use App\Models\Credential;
use App\Models\Host;
use App\Ssh\RemoteShellFactory;

beforeEach(function () {
    if (! env('DADA_SSH_TEST_HOST')) {
        $this->markTestSkipped('DADA_SSH_TEST_HOST non impostato.');
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
            ->and($result->stdout)->toBe('hi
');

        $mixed = $shell->exec('echo out; echo err >&2');
        expect($mixed->stdout)->toBe('out
')->and($mixed->output)->toContain('err');

        $shell->put('/tmp/dada-it.txt', 'x', 0600);
        expect(trim($shell->exec('stat -c %a /tmp/dada-it.txt')->output))->toBe('600');

        expect($this->host->fresh()->host_key)->not->toBeEmpty();
    } finally {
        $shell->close();
    }
})->group('ssh-integration');
