<?php

namespace App\Deploy;

use App\Enums\CredentialType;
use App\Enums\Orchestrator;
use App\Generation\GeneratedFiles;
use App\Generation\HostFilesGenerator;
use App\Generation\ScriptRenderer;
use App\Models\Credential;
use App\Models\DeploymentHost;
use App\Models\DeploymentSnapshot;
use App\Models\DeployRun;
use App\Models\Installation;
use App\Models\Variable;
use App\Ssh\ExecResult;
use App\Ssh\RemoteCommandFailed;
use App\Ssh\RemoteShell;
use App\Ssh\RemoteShellFactory;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Deploy di un singolo deployment host. Lancia un'eccezione al primo passo fallito:
 * il messaggio (mascherato dal chiamante) finisce in deploy_run_hosts.error.
 */
class DeployHostPipeline
{
    public function __construct(
        private readonly RemoteShellFactory $shells,
        private readonly HostFilesGenerator $generator,
    ) {}

    /** @param  array{pull?: bool, restart?: bool, confirm_removals?: bool}  $options */
    public function run(DeployRun $run, DeploymentHost $dh, DeployLogger $log, array $options): void
    {
        $dh->loadMissing('host');

        $log->step($dh, 'Generazione dei file');
        $generated = $this->generator->generate($dh);
        $log->addSecrets($generated->secretValues);
        $this->ensureGenerated($generated);
        foreach ($generated->warnings as $warning) {
            $log->info($dh, "Attenzione: {$warning}");
        }
        $installations = $this->installationsSnapshot($dh, $generated);
        $hostVariables = $dh->variables()->get()->map(fn (Variable $v) => $this->variableSnapshot($v))->values()->all();

        $log->step($dh, 'Calcolo delle rimozioni');
        $removals = RemovalPlanner::for($dh);
        if ($removals !== [] && ! ($options['confirm_removals'] ?? false)) {
            throw new RuntimeException('rimozioni da confermare: '.implode(', ', array_map(
                fn (Removal $r) => $r->slug.($r->services !== [] ? ' ('.implode(', ', $r->services).')' : ''),
                $removals,
            )));
        }
        foreach ($removals as $removal) {
            $log->info($dh, "Verrà rimossa l'installazione {$removal->slug}");
        }

        $registries = $dh->registries()->where('type', CredentialType::Registry->value)->get();
        $log->addSecrets($registries->pluck('secret')->filter(fn ($s) => is_string($s) && $s !== '')->values()->all());

        $log->step($dh, "Connessione a {$dh->host->name}");
        $shell = $this->shells->for($dh->host);

        try {
            $this->checkDiskSpace($shell, $dh, $log);
            $this->prepareFolder($shell, $dh, $log);
            $this->backup($shell, $dh, $log, $generated);
            $this->upload($shell, $dh, $log, $generated);
            $this->remove($shell, $dh, $log, $removals);
            $this->login($shell, $dh, $log, $registries);

            if (($options['pull'] ?? false) && $dh->orchestrator === Orchestrator::Compose) {
                $log->step($dh, 'Pull delle immagini');
                $this->exec($shell, $dh, $log, RemoteSteps::wrap($dh, 'docker compose pull --ignore-buildable'));
            }

            if ($options['restart'] ?? false) {
                $log->step($dh, 'Riavvio');
                $this->exec($shell, $dh, $log, RemoteSteps::wrap($dh, './restart.sh restart'));
            }
        } finally {
            $shell->close();
        }

        $log->step($dh, 'Salvataggio dello snapshot');
        $snapshot = DeploymentSnapshot::create([
            'deployment_host_id' => $dh->id,
            'deploy_run_id' => $run->id,
            'installations' => $installations,
            'host_variables' => $hostVariables,
            'files' => $generated->files,
        ]);
        $dh->setRelation('latestSnapshot', $snapshot);
    }

    private function ensureGenerated(GeneratedFiles $generated): void
    {
        if ($generated->ok()) {
            return;
        }

        $lines = [
            ...$generated->errors,
            ...array_map(fn (string $m) => "variabile obbligatoria mancante: {$m}", $generated->missingRequired),
        ];

        throw new RuntimeException("generazione dei file fallita:\n- ".implode("\n- ", $lines));
    }

    private function checkDiskSpace(RemoteShell $shell, DeploymentHost $dh, DeployLogger $log): void
    {
        $log->step($dh, 'Controllo dello spazio disco');
        $result = $this->exec($shell, $dh, $log, self::diskFreeCommand($dh->path));

        $free = trim($result->stdout);
        if (preg_match('/\A\d+\z/', $free) !== 1) {
            throw new RuntimeException("impossibile leggere lo spazio libero su {$dh->path}");
        }

        $min = (int) config('dada.deploy.min_free_mb');
        if ((int) $free < $min) {
            throw new RuntimeException("spazio insufficiente: {$free} MB liberi, minimo {$min}");
        }
    }

    /**
     * MB liberi sul filesystem di $path: se $path (o i suoi genitori) non esiste ancora
     * si risale al primo antenato esistente.
     */
    public static function diskFreeCommand(string $path): string
    {
        return 'p='.ScriptRenderer::quote($path).'; while [ ! -d "$p" ]; do p=$(dirname "$p"); done; '
            .'df -Pm "$p" | awk \'NR==2{print $4}\'';
    }

    private function prepareFolder(RemoteShell $shell, DeploymentHost $dh, DeployLogger $log): void
    {
        $log->step($dh, 'Preparazione della cartella');
        $base = rtrim($dh->path, '/');
        $path = ScriptRenderer::quote($dh->path);
        $backups = ScriptRenderer::quote($base.'/.dada/backups');
        $sudo = RemoteSteps::sudo($dh);

        if ($sudo === '') {
            $this->exec($shell, $dh, $log, "mkdir -p {$backups}");

            return;
        }

        // chown ricorsivo solo su .dada: la cartella può contenere dati bind-montati (es. DB) da non toccare.
        $user = ScriptRenderer::quote($dh->host->username);
        $dada = ScriptRenderer::quote($base.'/.dada');
        $this->exec($shell, $dh, $log, "{$sudo} mkdir -p {$backups} && {$sudo} chown {$user} {$path} && {$sudo} chown -R {$user} {$dada}");
    }

    private function backup(RemoteShell $shell, DeploymentHost $dh, DeployLogger $log, GeneratedFiles $generated): void
    {
        $log->step($dh, 'Backup dei file attuali');
        $previous = array_keys($dh->latestSnapshot()->first()?->files ?? []);
        $names = array_values(array_unique([...array_keys($generated->files), ...array_map('strval', $previous)]));
        $list = implode(' ', array_map(ScriptRenderer::quote(...), $names));
        $keep = max(1, (int) config('dada.deploy.backup_retention')) + 1;

        $this->exec($shell, $dh, $log, RemoteSteps::wrap($dh, implode(' && ', [
            'ts=$(date +%Y%m%d_%H%M%S)',
            'mkdir -p .dada/backups/$ts',
            "for f in {$list}; do if [ -e \"\$f\" ]; then cp -p \"\$f\" .dada/backups/\$ts/ || exit 1; fi; done",
            "ls -1dt .dada/backups/*/ | tail -n +{$keep} | xargs -r rm -rf",
        ])));
    }

    private function upload(RemoteShell $shell, DeploymentHost $dh, DeployLogger $log, GeneratedFiles $generated): void
    {
        $log->step($dh, 'Caricamento dei file');
        $base = rtrim($dh->path, '/');

        foreach ($generated->files as $name => $contents) {
            $mode = match (true) {
                str_starts_with($name, '.env') => 0600,
                str_ends_with($name, '.sh') => 0755,
                default => 0644,
            };
            $shell->put("{$base}/{$name}", $contents, $mode);
            $log->info($dh, sprintf('%s (%o)', $name, $mode));
        }
    }

    /** @param  list<Removal>  $removals */
    private function remove(RemoteShell $shell, DeploymentHost $dh, DeployLogger $log, array $removals): void
    {
        if ($removals === []) {
            return;
        }

        $log->step($dh, 'Rimozione delle installazioni eliminate');
        $project = ScriptRenderer::quote('label=com.docker.compose.project='.$dh->stack_name);

        foreach ($removals as $removal) {
            $this->exec($shell, $dh, $log, RemoteSteps::wrap($dh, 'rm -f '.implode(' ', array_map(ScriptRenderer::quote(...), $removal->files))));

            // In swarm i servizi rimossi li elimina `docker stack deploy --prune` di restart.sh.
            if ($dh->orchestrator !== Orchestrator::Compose) {
                continue;
            }

            foreach ($removal->services as $service) {
                $filter = ScriptRenderer::quote('label=com.docker.compose.service='.$service);
                $this->exec($shell, $dh, $log, RemoteSteps::wrap($dh, "docker ps -aq --filter {$project} --filter {$filter} | xargs -r docker rm -f"));
            }
        }
    }

    /** @param  Collection<int, Credential>  $registries */
    private function login(RemoteShell $shell, DeploymentHost $dh, DeployLogger $log, Collection $registries): void
    {
        foreach ($registries as $registry) {
            $url = trim((string) $registry->registry_url);
            $log->step($dh, 'Login al registry '.($url !== '' ? $url : $registry->name));

            $command = 'docker login'.($url !== '' ? ' '.ScriptRenderer::quote($url) : '')
                .' -u '.ScriptRenderer::quote((string) $registry->username).' --password-stdin';

            // Il secret passa solo via stdin: non compare mai nella riga di comando.
            $this->exec($shell, $dh, $log, RemoteSteps::wrap($dh, $command), (string) $registry->secret);
        }
    }

    private function exec(RemoteShell $shell, DeploymentHost $dh, DeployLogger $log, string $command, ?string $stdin = null): ExecResult
    {
        $result = $shell->exec($command, fn (string $chunk) => $log->output($dh, $chunk), $stdin);
        $log->flush();

        if (! $result->successful()) {
            throw RemoteCommandFailed::from($command, $result);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function installationsSnapshot(DeploymentHost $dh, GeneratedFiles $generated): array
    {
        return $dh->installations()->with('variables')->get()->map(fn (Installation $i) => [
            'slug' => $i->slug,
            'project_version_id' => $i->project_version_id,
            'order' => $i->order,
            'compose' => $i->compose,
            'commands' => $i->commands,
            'variables' => $i->variables->map(fn (Variable $v) => $this->variableSnapshot($v))->values()->all(),
            'services' => $generated->servicesBySlug[$i->slug] ?? [],
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function variableSnapshot(Variable $v): array
    {
        return [
            'key' => $v->key,
            'default' => $v->default,
            'value' => $v->value,
            'use_default' => $v->use_default,
            'required' => $v->required,
            'secret' => $v->secret,
            'scope' => $v->scope->value,
            'description' => $v->description,
        ];
    }
}
