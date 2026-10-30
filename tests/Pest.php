<?php

use App\Generation\BuiltCompose;
use App\Generation\ComposeBuilder;
use App\Generation\EffectiveVariables;
use App\Models\Deployment;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Models\User;
use App\Variables\VariableSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit/Infrastructure', 'Unit/Ssh', 'Unit/Catalog', 'Unit/Variables', 'Unit/Generation', 'Unit/Deployments', 'Unit/Deploy', 'Integration');

/*
|--------------------------------------------------------------------------
| Helpers for script generation
|--------------------------------------------------------------------------
*/

/** @return array{DeploymentHost, Installation, BuiltCompose} */
function composeFixture(): array
{
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'compose', 'stack_name' => 'client-x']);
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => [
        'wp' => ['image' => 'wordpress:6'],
        'migrate' => ['image' => 'wordpress:6', 'command' => ['wp', 'core', 'update-db'], 'x-dada' => ['init' => true]],
    ]]]);

    $built = ComposeBuilder::build($dh, $dh->installations, EffectiveVariables::for($dh));

    return [$dh, $inst, $built];
}

/**
 * Swarm host with installation 'wp': services wp (deploy.replicas 3) and cron, init migrate.
 *
 * @param  array<string, mixed>|null  $services
 * @param  array<string, mixed>  $extra  sezioni top-level aggiuntive (volumes, networks)
 * @return array{DeploymentHost, Installation, BuiltCompose}
 */
function swarmFixture(?array $services = null, array $extra = []): array
{
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'swarm', 'stack_name' => 'client-x']);
    $services ??= [
        'wp' => ['image' => 'wordpress:6', 'deploy' => ['replicas' => 3]],
        'cron' => ['image' => 'wordpress:6'],
        'migrate' => ['image' => 'wordpress:${WP_TAG}', 'command' => ['wp', 'core', 'update-db'], 'x-dada' => ['init' => true]],
    ];
    $inst = Installation::factory()->for($dh)->create(['slug' => 'wp', 'compose' => ['services' => $services] + $extra]);

    $built = ComposeBuilder::build($dh, $dh->installations, EffectiveVariables::for($dh));

    return [$dh, $inst, $built];
}

/** Real bash (Git Bash on Windows, never WSL's bash), or null. */
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
 * Writes the files to a temporary directory and runs $entry with bash.
 * `docker` is replaced by a function that records the arguments in docker.log
 * (one call per line, arguments in square brackets) and fails with exit 1
 * if the command line contains $DOCKER_FAIL_ON.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $args
 *                              The directory is removed: `files` reports the final content (name => content).
 * @return array{exit: int, output: string, log: string, files: array<string, string>}
 */
function runBashScript(array $files, string $entry, array $args = [], array $env = []): array
{
    $bash = bashBinary();
    if ($bash === null) {
        test()->markTestSkipped('bash not available');
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

    $left = [];
    foreach (array_diff(scandir($dir), ['.', '..']) as $name) {
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        $left[$name] = is_file($path) ? (string) file_get_contents($path) : '';
        is_dir($path) ? @rmdir($path) : @unlink($path);
    }
    @rmdir($dir);

    return [
        'exit' => $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'log' => $left['docker.log'] ?? '',
        'files' => $left,
    ];
}

/** Checks the syntax with `bash -n`; skips the test if bash is missing. */
function assertBashSyntax(string $script): void
{
    $bash = bashBinary();
    if ($bash === null) {
        test()->markTestSkipped('bash not available');
    }

    $file = tempnam(sys_get_temp_dir(), 'dada-sh-');
    file_put_contents($file, $script);
    $process = new Process([$bash, '-n', $file]);
    $process->run();
    @unlink($file);

    expect($process->getExitCode())->toBe(0, 'bash -n: '.$process->getErrorOutput());
}

/**
 * Compose host with installation 'wp' (service wp, image wordpress:${WP_TAG}),
 * secret container variable DB_PASSWORD='pw-123' and compose variable WP_TAG='6'.
 *
 * @param  array<string, mixed>  $dhAttributes
 * @param  array<string, mixed>  $hostAttributes  installation attributes
 */
function hostWithWordpress(array $dhAttributes = [], array $hostAttributes = []): DeploymentHost
{
    $dh = DeploymentHost::factory()->create(['orchestrator' => 'compose', 'stack_name' => 'client-x', ...$dhAttributes]);
    $inst = Installation::factory()->for($dh)->create([
        'slug' => 'wp',
        'compose' => ['services' => ['wp' => ['image' => 'wordpress:${WP_TAG}']]],
        ...$hostAttributes,
    ]);
    VariableSync::sync($inst, [
        ['key' => 'DB_PASSWORD', 'scope' => 'container', 'secret' => true, 'default' => 'pw-123'],
        ['key' => 'WP_TAG', 'scope' => 'compose', 'default' => '6'],
    ], allowHostGlobal: false);

    return $dh->refresh();
}

/**
 * Deployment with two compose hosts (A and B, distinct SSH hosts), each with the 'wp' installation of hostWithWordpress().
 *
 * @return array{Deployment, DeploymentHost, DeploymentHost}
 */
function deploymentWithTwoHosts(): array
{
    $deployment = Deployment::factory()->create();
    $a = hostWithWordpress(['deployment_id' => $deployment->id]);
    $b = hostWithWordpress(['deployment_id' => $deployment->id]);

    return [$deployment, $a, $b];
}

/** User with the operator role on the deployment. */
function operatorOf(Deployment $deployment): User
{
    $user = User::factory()->create();
    $deployment->members()->attach($user, ['role' => 'operator']);

    return $user;
}
