<?php

namespace App\Deploy;

use App\Events\DeployRunLogged;
use App\Models\DeployRun;
use App\Models\DeployRunLog;
use App\Models\DeploymentHost;

class DeployLogger
{
    public const MAX_MESSAGE_BYTES = 8192;

    /**
     * Righe incomplete di `output` in attesa del chunk successivo, per host (chiave 0 = nessun host).
     *
     * @var array<int, string>
     */
    private array $buffers = [];

    public function __construct(private readonly DeployRun $run, private readonly SecretMasker $masker) {}

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

    private function write(string $level, ?DeploymentHost $dh, string $message): void
    {
        // Il mascheramento avviene dopo aver ricomposto la riga: un segreto spezzato tra due chunk viene coperto.
        $message = $this->masker->mask($message);
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

        DeployRunLogged::dispatch($this->run->id, $log);
    }
}
