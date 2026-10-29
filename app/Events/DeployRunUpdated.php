<?php

namespace App\Events;

use App\Models\DeployRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DeployRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly DeployRun $run) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deploy-runs.{$this->run->id}"),
            new PrivateChannel("deployments.{$this->run->deployment_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'run.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->run->id,
            'status' => $this->run->status->value,
            'hosts' => $this->run->hosts()->get()->map(fn ($h) => [
                'deployment_host_id' => $h->deployment_host_id,
                'status' => $h->status->value,
                'error' => $h->error,
            ])->all(),
        ];
    }
}
