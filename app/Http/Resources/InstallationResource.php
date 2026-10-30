<?php

namespace App\Http\Resources;

use App\Models\Installation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Installation */
class InstallationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'order' => $this->order,
            'project' => [
                'name' => $this->version->project->name,
                'slug' => $this->version->project->slug,
            ],
            /** Installed version of the catalog project. */
            'version' => $this->version->version,
        ];
    }
}
