<?php

namespace App\Http\Resources;

use App\Models\DeployRunLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeployRunLog */
class DeployRunLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Use the last id as the `after` cursor of the next request. */
            'id' => $this->id,
            /** Host the line refers to, null for run-wide lines. */
            'deployment_host_id' => $this->deployment_host_id,
            /** step, info, error or output. */
            'level' => $this->level,
            /** Log line, stored with secrets already masked. */
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
