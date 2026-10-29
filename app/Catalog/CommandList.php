<?php

namespace App\Catalog;

class CommandList
{
    /**
     * @param  array<mixed>  $commands
     * @return list<string>
     */
    public static function validate(array $commands): array
    {
        $errors = [];
        $seen = [];

        foreach (array_values($commands) as $i => $c) {
            $p = "commands.{$i}";

            if (! is_array($c)) {
                $errors[] = "{$p}: deve essere una mappa";

                continue;
            }

            if (! isset($c['name']) || ! is_string($c['name']) || trim($c['name']) === '') {
                $errors[] = "{$p}.name: obbligatorio (testo)";
            } elseif (isset($seen[$c['name']])) {
                $errors[] = "{$p}.name: \"{$c['name']}\" duplicato";
            } else {
                $seen[$c['name']] = true;
            }

            if (isset($c['description']) && ! is_string($c['description'])) {
                $errors[] = "{$p}.description: deve essere testo";
            }

            if (! isset($c['target']) || ! is_string($c['target'])
                || ! preg_match('/^(host|service:[a-zA-Z0-9][a-zA-Z0-9_.-]*)$/', $c['target'])) {
                $errors[] = "{$p}.target: deve essere \"host\" o \"service:<nome>\"";
            }

            if (! isset($c['command']) || ! is_string($c['command']) || trim($c['command']) === '') {
                $errors[] = "{$p}.command: obbligatorio (testo)";
            }

            if (! array_key_exists('dangerous', $c) || ! is_bool($c['dangerous'])) {
                $errors[] = "{$p}.dangerous: deve essere booleano";
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $commands
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    public static function validateTargets(array $commands, array $compose): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $errors = [];

        foreach (array_values($commands) as $i => $c) {
            $target = is_array($c) ? ($c['target'] ?? null) : null;

            if (is_string($target) && str_starts_with($target, 'service:')) {
                $service = substr($target, 8);
                if (! array_key_exists($service, $services)) {
                    $errors[] = "commands.{$i}.target: il servizio \"{$service}\" non esiste in compose";
                }
            }
        }

        return $errors;
    }
}
