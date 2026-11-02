<?php

use App\Deploy\DeployHostPipeline;
use App\Deploy\DeployLogger;
use App\Deploy\PrivilegeCheck;
use App\Deploy\RemoteSteps;
use App\Deploy\SecretMasker;
use App\Enums\PrivilegeMode;
use App\Generation\ScriptRenderer;
use App\Infrastructure\Privilege;
use App\Models\Credential;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Ssh\RemoteShellFactory;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! env('DADA_SSH_TEST_HOST') || ! env('DADA_SSH_TEST_SUDO')) {
        $this->markTestSkipped('DADA_SSH_TEST_HOST and DADA_SSH_TEST_SUDO not set.');
    }
});

/** Points the deployment host at the CI target, with sudo that asks for $sudoSecret. */
function sudoPasswordTarget(string $sudoSecret): DeploymentHost
{
    $dh = hostWithWordpress(['path' => '/srv/dada-it-'.Str::lower(Str::random(6))]);
    $login = Credential::factory()->password()->create(['secret' => (string) env('DADA_SSH_TEST_PASSWORD')]);
    $sudo = Credential::factory()->password()->create(['secret' => $sudoSecret]);

    $dh->host->update([
        'hostname' => env('DADA_SSH_TEST_HOST'),
        'port' => (int) env('DADA_SSH_TEST_PORT', 22),
        'username' => (string) env('DADA_SSH_TEST_USER', 'root'),
        'credential_id' => $login->id,
        'privilege_mode' => PrivilegeMode::SudoPassword,
        'sudo_credential_id' => $sudo->id,
        'host_key' => null,
    ]);

    return $dh->refresh();
}

it('deploys through sudo with a password and leaves root-owned files with the final modes', function () {
    $dh = sudoPasswordTarget((string) env('DADA_SSH_TEST_PASSWORD'));
    $run = DeployRun::factory()->for($dh->deployment)->create();

    $shell = app(RemoteShellFactory::class)->for($dh->host->fresh());

    try {
        app(DeployHostPipeline::class)->run(
            $run,
            $dh,
            new DeployLogger($run, new SecretMasker([])),
            ['pull' => false, 'restart' => false, 'confirm_removals' => false],
            new PrivilegeCheck,
        );

        $stat = fn (string $file) => trim($shell->exec("stat -c '%U %a' ".ScriptRenderer::quote("{$dh->path}/{$file}"))->stdout);

        expect($stat('.env.wp'))->toBe('root 600')
            ->and($stat('restart.sh'))->toBe('root 755')
            ->and($stat('docker-compose.yml'))->toBe('root 644')
            ->and(trim($shell->exec(RemoteSteps::wrap($dh, 'pwd'), stdin: RemoteSteps::stdin($dh))->stdout))->toBe($dh->path);
    } finally {
        $shell->exec(Privilege::for($dh->host)->wrap('rm -rf '.ScriptRenderer::quote($dh->path)), stdin: RemoteSteps::stdin($dh));
        $shell->close();
    }
})->group('ssh-integration');

it('fails the privilege check when the sudo password is wrong', function () {
    $dh = sudoPasswordTarget('wrong');
    $host = $dh->host->fresh();

    $shell = app(RemoteShellFactory::class)->for($host);

    try {
        expect(fn () => (new PrivilegeCheck)->ensure($shell, $host))
            ->toThrow(RuntimeException::class, 'sudo authentication failed');
    } finally {
        $shell->close();
    }
})->group('ssh-integration');
