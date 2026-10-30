<?php

namespace App\Http\Resources;

use App\Models\Deployment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Deployment */
class DeploymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'environment' => $this->environment,
            /** Hosts of the deployment (detail endpoint only). */
            'hosts' => DeploymentHostResource::collection($this->whenLoaded('hosts')),
            /** The queued or running run, null when the deployment is idle (detail endpoint only). */
            'active_run' => $this->whenLoaded('activeRun', fn () => $this->activeRun === null ? null : new DeployRunResource($this->activeRun)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
