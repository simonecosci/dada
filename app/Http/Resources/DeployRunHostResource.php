<?php

namespace App\Http\Resources;

use App\Models\DeployRunHost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeployRunHost */
class DeployRunHostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'deployment_host_id' => $this->deployment_host_id,
            /** Name of the registered host. */
            'name' => $this->deploymentHost?->host->name,
            'status' => $this->status,
            /** Failure reason, with secrets masked. */
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
