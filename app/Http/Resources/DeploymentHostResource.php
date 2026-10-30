<?php

namespace App\Http\Resources;

use App\Models\DeploymentHost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeploymentHost */
class DeploymentHostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Name of the registered host. */
            'name' => $this->host->name,
            'hostname' => $this->host->hostname,
            /** Directory of the deployment on the host. */
            'path' => $this->path,
            'orchestrator' => $this->orchestrator,
            'stack_name' => $this->stack_name,
            'installations' => InstallationResource::collection($this->whenLoaded('installations')),
        ];
    }
}
