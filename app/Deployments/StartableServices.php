<?php

namespace App\Deployments;

final class StartableServices
{
    /**
     * Startable services of the installation (inits excluded), from the saved compose.
     *
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    public static function of(array $compose): array
    {
        $names = [];
        foreach ((array) ($compose['services'] ?? []) as $name => $def) {
            $def = is_array($def) ? $def : [];
            $isInit = ($def['x-dada']['init'] ?? false) === true
                || in_array('init', (array) ($def['profiles'] ?? []), true);
            if (! $isInit) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
