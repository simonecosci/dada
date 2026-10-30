<?php

namespace App\Http\Resources;

use App\Models\DeployRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin DeployRun */
class DeployRunResource extends JsonResource
{
    /** Options chosen by the user: internal keys (e.g. the lock owner) stay out. */
    private const OPTION_KEYS = ['deployment_host_id', 'pull', 'restart', 'confirm_removals', 'action', 'installation_slug', 'service'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deployment_id' => $this->deployment_id,
            'type' => $this->type,
            'status' => $this->status,
            /**
             * Deploy: deployment_host_id, pull, restart, confirm_removals.
             * Restart: deployment_host_id, action, installation_slug, service.
             *
             * @var array<string, mixed>
             */
            'options' => (object) Arr::only($this->options ?? [], self::OPTION_KEYS),
            /** Failure reason, with secrets masked. */
            'error' => $this->error,
            /** Name of the user who started the run. */
            'user' => $this->user_name,
            /** Per-host status (run detail only). */
            'hosts' => DeployRunHostResource::collection($this->whenLoaded('hosts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
