<?php

namespace App\Ssh\OpenSsh;

use App\Enums\CredentialType;
use App\Models\Host;
use App\Ssh\ExecResult;
use App\Ssh\HostKeyChanged;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Shell remota basata sul client OpenSSH. Va creata tramite OpenSshShellFactory,
 * che valida la catena; close() va chiamato in finally da chi la usa.
 */
class OpenSshShell implements RemoteShell
{
    private string $workDir;

    /**
     * Directory dei socket ControlMaster. È separata da $workDir e volutamente corta:
     * il path di un socket unix è limitato a ~104 caratteri e ssh vi aggiunge
     * hash (%C, 40 caratteri) e un suffisso temporaneo.
     */
    private string $controlDir;

    private string $configFile;

    private string $knownHostsFile;

    private Host $target;

    private bool $closed = false;

    /**
     * @param  list<Host>  $chain  dal primo salto al target, già validata
     */
    public function __construct(private readonly array $chain)
    {
        $this->target = $chain[array_key_last($chain)];

        $this->workDir = rtrim((string) config('dada.ssh.tmp_path'), '/\\').'/'.Str::uuid();
        $this->controlDir = rtrim(sys_get_temp_dir(), '/\\').'/dcm-'.Str::lower(Str::random(8));
        File::ensureDirectoryExists($this->workDir, 0700);
        File::ensureDirectoryExists($this->controlDir, 0700);

        $this->knownHostsFile = $this->workDir.'/known_hosts';
        $this->configFile = $this->workDir.'/config';

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

        try {
            $process->run(function (string $type, string $chunk) use (&$output, $onOutput): void {
                $output .= $chunk;
                if ($onOutput !== null) {
                    $onOutput($chunk);
                }
            });
        } catch (ProcessTimedOutException) {
            throw new RemoteCommandFailed("Timeout del comando remoto su \"{$this->target->name}\": {$command}");
        } finally {
            $this->recordNewHostKeys();
        }

        $result = new ExecResult((int) $process->getExitCode(), $output);

        if ($result->exitCode === 255) {
            if (str_contains($output, 'REMOTE HOST IDENTIFICATION HAS CHANGED')
                || str_contains($output, 'Host key verification failed')) {
                throw new HostKeyChanged('Chiave host cambiata per '.$this->hostNamedIn($output));
            }

            throw RemoteCommandFailed::from($command, $result);
        }

        return $result;
    }

    public function put(string $remotePath, string $contents, int $mode = 0644): void
    {
        $tmp = escapeshellarg($remotePath.'.dada-tmp');
        $path = escapeshellarg($remotePath);
        $command = sprintf('cat > %s && chmod %s %s && mv %s %s', $tmp, decoct($mode), $tmp, $tmp, $path);

        $result = $this->exec($command, stdin: $contents);

        if (! $result->successful()) {
            throw RemoteCommandFailed::from($command, $result);
        }
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
        } catch (\Throwable) {
            // Il master potrebbe non esistere: la pulizia continua comunque.
        } finally {
            File::deleteDirectory($this->workDir);
            File::deleteDirectory($this->controlDir);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * TOFU: salva le righe known_hosts nuove sugli host della catena che non avevano host_key.
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

    private function hostNamedIn(string $output): string
    {
        foreach (array_reverse($this->chain) as $host) {
            if (preg_match('/\b'.preg_quote(SshConfigBuilder::alias($host), '/').'\b/', $output)) {
                return $host->name;
            }
        }

        return $this->target->name;
    }
}
