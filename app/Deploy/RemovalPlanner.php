<?php

namespace App\Deploy;

use App\Models\DeploymentHost;

class RemovalPlanner
{
    /**
     * Slug presenti nell'ultimo snapshot e assenti tra le installazioni attuali.
     *
     * @return list<Removal>
     */
    public static function for(DeploymentHost $dh): array
    {
        // Query esplicita: la relazione già caricata potrebbe essere precedente all'ultimo deploy.
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
