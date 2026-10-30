<?php

namespace App\Catalog;

use App\Enums\VersionStatus;
use App\Models\ProjectVersion;
use Illuminate\Support\Facades\DB;

class VersionCloner
{
    public static function clone(ProjectVersion $source, string $newVersion): ProjectVersion
    {
        return DB::transaction(function () use ($source, $newVersion): ProjectVersion {
            $new = ProjectVersion::create([
                'project_id' => $source->project_id,
                'version' => $newVersion,
                'status' => VersionStatus::Draft,
                'compose' => $source->compose,
                'commands' => $source->commands,
            ]);

            foreach ($source->variables as $variable) {
                $new->variables()->create([
                    'key' => $variable->key,
                    'default' => $variable->default,
                    'value' => $variable->value,
                    'use_default' => $variable->use_default,
                    'description' => $variable->description,
                    'required' => $variable->required,
                    'secret' => $variable->secret,
                    'scope' => $variable->scope,
                    'position' => $variable->position,
                ]);
            }

            return $new;
        });
    }
}
