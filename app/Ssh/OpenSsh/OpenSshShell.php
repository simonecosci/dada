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
        $this->knownHostsFile = $this->workDir.'/known_hosts';
        $this->configFile = $this->workDir.'/config';

        // Se il costruttore fallisce PHP non chiama __destruct: le chiavi già
        // scritte vanno rimosse qui.
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
            throw new RemoteCommandFailed("Timeout del comando remoto su \"{$this->target->name}\": {$command}");
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
     * Exit 255: chiave host cambiata (solo se l'output nomina un alias della catena)
     * o altro errore di connessione.
     */
    private function connectionFailure(string $command, ExecResult $result): HostKeyChanged|RemoteCommandFailed
    {
        $named = $this->hostNamedIn($result->output);

        if ($named !== null && (str_contains($result->output, 'REMOTE HOST IDENTIFICATION HAS CHANGED')
            || str_contains($result->output, 'Host key verification failed'))) {
            return new HostKeyChanged('Chiave host cambiata per '.$named);
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
     * Comando remoto di put(): umask 077 così il file temporaneo non è mai leggibile da altri
     * prima del chmod. Quoting POSIX (escapeshellarg su Windows produrrebbe apici in stile cmd).
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
            // Il master potrebbe non esistere: la pulizia continua comunque.
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

    /**
     * Nome dell'host della catena il cui alias (dada-h{id}) compare nell'output, se c'è.
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
