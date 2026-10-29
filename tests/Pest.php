<?php

use App\Generation\BuiltCompose;
use App\Generation\ComposeBuilder;
use App\Generation\EffectiveVariables;
use App\Models\DeploymentHost;
use App\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit/Infrastructure', 'Unit/Ssh', 'Unit/Catalog', 'Unit/Variables', 'Unit/Generation', 'Unit/Deployments', 'Integration');

/*
|--------------------------------------------------------------------------
| Helper per la generazione degli script
|--------------------------------------------------------------------------
*/

/** @return array{DeploymentHost, Installation, BuiltCompose} */
function composeFixture(): array
{
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'compose', 'stack_name' => 'cliente-x']);
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => [
        'wp' => ['image' => 'wordpress:6'],
        'migrate' => ['image' => 'wordpress:6', 'command' => ['wp', 'core', 'update-db'], 'x-dada' => ['init' => true]],
    ]]]);

    $built = ComposeBuilder::build($dh, $dh->installations, EffectiveVariables::for($dh));

    return [$dh, $inst, $built];
}

/**
 * Host swarm con installazione 'wp': servizi wp (deploy.replicas 3) e cron, init migrate.
 *
 * @param  array<string, mixed>|null  $services
 * @param  array<string, mixed>  $extra  sezioni top-level aggiuntive (volumes, networks)
 * @return array{DeploymentHost, Installation, BuiltCompose}
 */
function swarmFixture(?array $services = null, array $extra = []): array
{
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm', 'stack_name' => 'cliente-x']);
    $services ??= [
        'wp' => ['image' => 'wordpress:6', 'deploy' => ['replicas' => 3]],
        'cron' => ['image' => 'wordpress:6'],
        'migrate' => ['image' => 'wordpress:${WP_TAG}', 'command' => ['wp', 'core', 'update-db'], 'x-dada' => ['init' => true]],
    ];
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => $services] + $extra]);

    $built = ComposeBuilder::build($dh, $dh->installations, EffectiveVariables::for($dh));

    return [$dh, $inst, $built];
}

/** Bash reale (Git Bash su Windows, mai il bash di WSL), oppure null. */
function bashBinary(): ?string
{
    static $resolved = false;
    static $bash = null;
    if ($resolved) {
        return $bash;
    }
    $resolved = true;

    if (PHP_OS_FAMILY === 'Windows') {
        foreach (['C:\Program Files\Git\bin\bash.exe', 'C:\Program Files (x86)\Git\bin\bash.exe'] as $candidate) {
            if (is_file($candidate)) {
                return $bash = $candidate;
            }
        }

        return null;
    }

    $found = trim((string) shell_exec('command -v bash 2>/dev/null'));

    return $bash = ($found !== '' ? $found : null);
}

/**
 * Scrive i file in una directory temporanea ed esegue $entry con bash.
 * `docker` è sostituito da una funzione che registra gli argomenti in docker.log
 * (una chiamata per riga, argomenti tra parentesi quadre) e fallisce con exit 1
 * se la riga di comando contiene $DOCKER_FAIL_ON.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $args
 * @return array{exit: int, output: string, log: string, dir: string}
 */
function runBashScript(array $files, string $entry, array $args = [], array $env = []): array
{
    $bash = bashBinary();
    if ($bash === null) {
        test()->markTestSkipped('bash non disponibile');
    }

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dada-sh-'.bin2hex(random_bytes(6));
    mkdir($dir);
    foreach ($files as $name => $content) {
        file_put_contents($dir.DIRECTORY_SEPARATOR.$name, $content);
        chmod($dir.DIRECTORY_SEPARATOR.$name, 0755);
    }

    $stub = <<<'SH'
docker() {
  { printf 'docker'; printf ' [%s]' "$@"; printf '\n'; } >> docker.log
  if [ -n "${DOCKER_FAIL_ON:-}" ]; then case "$*" in *"$DOCKER_FAIL_ON"*) return 1 ;; esac; fi
  return 0
}
export -f docker
: > docker.log
bash "$@"
SH;

    file_put_contents($dir.DIRECTORY_SEPARATOR.'.dada-stub.sh', $stub);

    $process = new Process(
        [$bash, '.dada-stub.sh', './'.$entry, ...$args],
        $dir,
        $env + ['LC_ALL' => 'C'],
    );
    $process->run();

    $log = (string) @file_get_contents($dir.DIRECTORY_SEPARATOR.'docker.log');

    return ['exit' => $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput(), 'log' => $log, 'dir' => $dir];
}

/** Controlla la sintassi con `bash -n`; salta il test se bash non c'è. */
function assertBashSyntax(string $script): void
{
    $bash = bashBinary();
    if ($bash === null) {
        test()->markTestSkipped('bash non disponibile');
    }

    $file = tempnam(sys_get_temp_dir(), 'dada-sh-');
    file_put_contents($file, $script);
    $process = new Process([$bash, '-n', $file]);
    $process->run();
    @unlink($file);

    expect($process->getExitCode())->toBe(0, 'bash -n: '.$process->getErrorOutput());
}
