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
     * Incomplete `output` lines waiting for the next chunk, per host (key 0 = no host).
     *
     * @var array<int, string>
     */
    private array $buffers = [];

    public function __construct(private readonly DeployRun $run, private SecretMasker $masker) {}

    /**
     * Adds secrets discovered during the run (e.g. registry credentials): from here on they are masked.
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

        // Output without newline (e.g. progress bars with CR only): do not accumulate forever.
        // The whole buffer is masked and everything is emitted except the tail of (longest secret - 1) bytes:
        // a secret cut by the split stays out of it, so it is never emitted in pieces.
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

    /** Emits the partial lines still in the buffer. */
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

    /** Masks a text with the same secrets as the log (to be used for the `error` values saved on the run). */
    public function mask(string $text): string
    {
        return $this->masker->mask(mb_scrub($text));
    }

    private function write(string $level, ?DeploymentHost $dh, string $message): void
    {
        // Masking happens after the line is reassembled: a secret split across two chunks is covered.
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

        // Persistence is authoritative: an unreachable Reverb must not interrupt the deploy.
        try {
            DeployRunLogged::dispatch($this->run->id, $log);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
