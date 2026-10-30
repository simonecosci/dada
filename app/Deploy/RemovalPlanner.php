<?php

namespace App\Deploy;

use App\Models\DeploymentHost;

class RemovalPlanner
{
    /**
     * Slugs present in the last snapshot and absent from the current installations.
     *
     * @return list<Removal>
     */
    public static function for(DeploymentHost $dh): array
    {
        // Explicit query: the already loaded relation might predate the last deploy.
        $snapshot = $dh->latestSnapshot()->first();
        if ($snapshot === null) {
            return [];
        }

        $current = $dh->installations()->pluck('slug')->all();
        $removals = [];

        foreach ($snapshot->installations as $installation) {
            $slug = (string) ($installation['slug'] ?? '');
            if ($slug === '' || in_array($slug, $current, true)) {
                continue;
            }

            $removals[] = new Removal(
                slug: $slug,
                services: array_values(array_map('strval', $installation['services'] ?? [])),
                files: [".env.{$slug}", "{$slug}.sh"],
            );
        }

        return $removals;
    }
}
