<?php

namespace App\Deploy;

use App\Events\DeployRunLogged;
use App\Models\DeploymentHost;
use App\Models\DeployRun;
use App\Models\DeployRunLog;
use Throwable;

class DeployLogger
{
    public const MAX_MESSAGE_BYTES = 8192;

    /**
     * Righe incomplete di `output` in attesa del chunk successivo, per host (chiave 0 = nessun host).
     *
     * @var array<int, string>
     */
    private array $buffers = [];

    public function __construct(private readonly DeployRun $run, private SecretMasker $masker) {}

    /**
     * Aggiunge segreti scoperti durante il run (es. credenziali registry): da qui in poi vengono mascherati.
     *
     * @param  list<string>  $secrets
     */
    public function addSecrets(array $secrets): void
    {
        if ($secrets !== []) {
            $this->masker = $this->masker->with($secrets);
        }
    }

    public function step(?DeploymentHost $dh, string $msg): void
    {
        $this->write('step', $dh, $msg);
    }

    public function info(?DeploymentHost $dh, string $msg): void
    {
        $this->write('info', $dh, $msg);
    }

    public function error(?DeploymentHost $dh, string $msg): void
    {
        $this->write('error', $dh, $msg);
    }

    public function output(?DeploymentHost $dh, string $chunk): void
    {
        $key = $dh?->id ?? 0;
        $lines = explode("\n", ($this->buffers[$key] ?? '').$chunk);
        $this->buffers[$key] = array_pop($lines);

        foreach ($lines as $line) {
            $this->write('output', $dh, rtrim($line, "\r"));
        }

        // Output senza newline (es. barre di avanzamento con solo CR): non accumulare all'infinito.
        // Si maschera l'intero buffer e si emette tutto tranne la coda lunga (segreto piu lungo - 1) byte:
        // un segreto interrotto dal taglio ne resta fuori, cosi non viene mai emesso a pezzi.
        if (strlen($this->buffers[$key]) > self::MAX_MESSAGE_BYTES) {
            $masked = $this->mask($this->buffers[$key]);
            $cut = strlen($masked) - max(0, $this->masker->maxLength() - 1);
            $emit = $cut > 0 ? mb_strcut($masked, 0, $cut) : '';
            $this->buffers[$key] = substr($masked, strlen($emit));
            if ($emit !== '') {
                $this->write('output', $dh, rtrim($emit, "\r"));
            }
        }
    }

    /** Emette le righe parziali ancora in buffer. */
    public function flush(): void
    {
        $buffers = $this->buffers;
        $this->buffers = [];

        foreach ($buffers as $key => $partial) {
            $partial = rtrim($partial, "\r");
            if ($partial !== '') {
                $this->write('output', $key === 0 ? null : DeploymentHost::find($key), $partial);
            }
        }
    }

    /** Maschera un testo con gli stessi segreti del log (da usare per gli `error` salvati sul run). */
    public function mask(string $text): string
    {
        return $this->masker->mask(mb_scrub($text));
    }

    private function write(string $level, ?DeploymentHost $dh, string $message): void
    {
        // Il mascheramento avviene dopo aver ricomposto la riga: un segreto spezzato tra due chunk viene coperto.
        $message = $this->mask($message);
        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            $message = mb_strcut($message, 0, self::MAX_MESSAGE_BYTES).'…';
        }

        $log = DeployRunLog::create([
            'deploy_run_id' => $this->run->id,
            'deployment_host_id' => $dh?->id,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);

        // La persistenza è autoritativa: un Reverb irraggiungibile non deve interrompere il deploy.
        try {
            DeployRunLogged::dispatch($this->run->id, $log);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
