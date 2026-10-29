<?php

namespace App\Events;

use App\Models\DeployRunLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DeployRunLogged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $runId, public readonly DeployRunLog $log) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("deploy-runs.{$this->runId}")];
    }

    public function broadcastAs(): string
    {
        return 'log';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->log->toPayload();
    }
}
