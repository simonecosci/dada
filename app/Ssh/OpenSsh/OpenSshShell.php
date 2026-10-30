<?php

namespace App\Ssh\OpenSsh;

use App\Enums\CredentialType;
use App\Generation\ScriptRenderer;
use App\Models\Host;
use App\Ssh\ExecResult;
use App\Ssh\HostKeyChanged;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Remote shell based on the OpenSSH client. It must be created via OpenSshShellFactory,
 * which validates the chain; close() must be called in a finally by whoever uses it.
 */
class OpenSshShell implements RemoteShell
{
    private string $workDir;

    /**
     * Directory of the ControlMaster sockets. It is separate from $workDir and deliberately short:
     * a unix socket path is limited to ~104 characters and ssh appends to it
     * a hash (%C, 40 characters) and a temporary suffix.
     */
    private string $controlDir;

    private string $configFile;

    private string $knownHostsFile;

    private Host $target;

    private bool $closed = false;

    /**
     * @param  list<Host>  $chain  from the first hop to the target, already validated
     */
    public function __construct(private readonly array $chain)
    {
        $this->target = $chain[array_key_last($chain)];

        $this->workDir = rtrim((string) config('dada.ssh.tmp_path'), '/\\').'/'.Str::uuid();
        $this->controlDir = rtrim(sys_get_temp_dir(), '/\\').'/dcm-'.Str::lower(Str::random(8));
        $this->knownHostsFile = $this->workDir.'/known_hosts';
        $this->configFile = $this->workDir.'/config';

        // If the constructor fails PHP does not call __destruct: the keys already
        // written must be removed here.
        try {
            $this->setUp();
        } catch (Throwable $e) {
            $this->closed = true;
            $this->deleteDirectories();

            throw $e;
        }
    }

    private function setUp(): void
    {
        $chain = $this->chain;

        File::ensureDirectoryExists($this->workDir, 0700);
        File::ensureDirectoryExists($this->controlDir, 0700);

        $identityFiles = [];
        $knownHosts = '';

        foreach ($chain as $host) {
            if ($host->credential->type === CredentialType::SshKey) {
                $keyFile = $this->workDir.'/id_h'.$host->id;
                $key = rtrim(str_replace("\r\n", "\n", (string) $host->credential->secret))."\n";
                File::put($keyFile, $key);
                @chmod($keyFile, 0600);
                $identityFiles[$host->id] = $keyFile;
            }

            if (filled($host->host_key)) {
                $knownHosts .= rtrim(str_replace("\r\n", "\n", $host->host_key))."\n";
            }
        }

        File::put($this->knownHostsFile, $knownHosts);
        @chmod($this->knownHostsFile, 0600);

        File::put($this->configFile, (new SshConfigBuilder)->build($chain, $identityFiles, $this->knownHostsFile, $this->controlDir));
        @chmod($this->configFile, 0600);
    }

    public function exec(string $command, ?callable $onOutput = null, ?string $stdin = null, ?int $timeout = null): ExecResult
    {
        $argv = ['ssh', '-F', $this->configFile, SshConfigBuilder::alias($this->target), '--', $command];
        $env = [];

        if ($this->target->credential->type === CredentialType::Password) {
            array_unshift($argv, 'sshpass', '-e');
            $env['SSHPASS'] = (string) $this->target->credential->secret;
        }

        $process = new Process($argv, null, $env, $stdin, $timeout ?? (int) config('dada.ssh.command_timeout'));

        $output = '';
        $stdout = '';

        try {
            $process->run(function (string $type, string $chunk) use (&$output, &$stdout, $onOutput): void {
                $output .= $chunk;
                if ($type === Process::OUT) {
                    $stdout .= $chunk;
                }
                if ($onOutput !== null) {
                    $onOutput($chunk);
                }
            });
        } catch (ProcessTimedOutException) {
            throw new RemoteCommandFailed("Remote command timeout on \"{$this->target->name}\": {$command}");
        } finally {
            $this->recordNewHostKeys();
        }

        $result = new ExecResult((int) $process->getExitCode(), $output, $stdout);

        if ($result->exitCode === 255) {
            throw $this->connectionFailure($command, $result);
        }

        return $result;
    }

    /**
     * Exit 255: host key changed (only if the output names an alias of the chain)
     * or other connection error.
     */
    private function connectionFailure(string $command, ExecResult $result): HostKeyChanged|RemoteCommandFailed
    {
        $named = $this->hostNamedIn($result->output);

        if ($named !== null && (str_contains($result->output, 'REMOTE HOST IDENTIFICATION HAS CHANGED')
            || str_contains($result->output, 'Host key verification failed'))) {
            return new HostKeyChanged('Host key changed for '.$named);
        }

        return RemoteCommandFailed::from($command, $result);
    }

    public function put(string $remotePath, string $contents, int $mode = 0644): void
    {
        $command = self::putCommand($remotePath, $mode);

        $result = $this->exec($command, stdin: $contents);

        if (! $result->successful()) {
            throw RemoteCommandFailed::from($command, $result);
        }
    }

    /**
     * Remote command of put(): umask 077 so the temporary file is never readable by others
     * before the chmod. POSIX quoting (escapeshellarg on Windows would produce cmd-style quotes).
     */
    public static function putCommand(string $remotePath, int $mode): string
    {
        $tmp = ScriptRenderer::quote($remotePath.'.dada-tmp');
        $path = ScriptRenderer::quote($remotePath);

        return sprintf('umask 077; cat > %s && chmod %s %s && mv %s %s', $tmp, decoct($mode), $tmp, $tmp, $path);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            if (is_file($this->configFile)) {
                (new Process(['ssh', '-F', $this->configFile, '-O', 'exit', SshConfigBuilder::alias($this->target)], timeout: 10))->run();
            }
        } catch (Throwable) {
            // The master might not exist: cleanup continues anyway.
        } finally {
            $this->deleteDirectories();
        }
    }

    private function deleteDirectories(): void
    {
        File::deleteDirectory($this->workDir);
        File::deleteDirectory($this->controlDir);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * TOFU: saves the new known_hosts lines on the chain hosts that had no host_key.
     */
    private function recordNewHostKeys(): void
    {
        if (! is_file($this->knownHostsFile)) {
            return;
        }

        $byAlias = [];
        foreach (preg_split('/\R/', (string) file_get_contents($this->knownHostsFile)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $byAlias[strtok($line, " \t")][] = $line;
        }

        foreach ($this->chain as $host) {
            $lines = $byAlias[SshConfigBuilder::alias($host)] ?? [];
            if ($lines !== [] && blank($host->host_key)) {
                $host->forceFill(['host_key' => implode("\n", $lines)])->save();
            }
        }
    }

    /**
     * Name of the chain host whose alias (dada-h{id}) appears in the output, if any.
     */
    private function hostNamedIn(string $output): ?string
    {
        foreach (array_reverse($this->chain) as $host) {
            if (preg_match('/\b'.preg_quote(SshConfigBuilder::alias($host), '/').'\b/', $output)) {
                return $host->name;
            }
        }

        return null;
    }
}
