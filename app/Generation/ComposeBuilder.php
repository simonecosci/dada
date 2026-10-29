<?php

namespace App\Generation;

use App\Enums\Orchestrator;
use App\Models\DeploymentHost;
use App\Models\Installation;
use Illuminate\Support\Collection;

class ComposeBuilder
{
    private const SECTIONS = ['volumes', 'networks', 'configs'];

    /** @param Collection<int, Installation> $installations */
    public static function build(DeploymentHost $dh, Collection $installations, EffectiveVariables $vars): BuiltCompose
    {
        $swarm = $dh->orchestrator === Orchestrator::Swarm;
        $errors = [];

        $template = is_array($dh->compose_template) ? $dh->compose_template : [];
        $sections = [
            'volumes' => self::normalizeSection($template['volumes'] ?? null),
            'networks' => self::normalizeSection($template['networks'] ?? null),
            'configs' => [],
        ];
        $origins = ['volumes' => [], 'networks' => [], 'configs' => []];
        foreach (['volumes', 'networks'] as $section) {
            foreach (array_keys($sections[$section]) as $name) {
                $origins[$section][$name] = "il template dell'host";
            }
        }

        $services = [];
        $serviceOwner = [];
        $initServices = [];
        $servicesBySlug = [];
        /** @var list<array{string, string, string, string}> $usages [slug, servizio, 'network'|'volume', nome] */
        $usages = [];

        foreach ($installations as $installation) {
            $slug = $installation->slug;
            $compose = is_array($installation->compose) ? $installation->compose : [];
            $servicesBySlug[$slug] = [];
            $hasContainerVars = $vars->containerEnv($installation) !== [];

            $rawServices = $compose['services'] ?? [];
            if (! is_array($rawServices)) {
                $errors[] = "{$slug}: la sezione services non è una mappa valida";
                $rawServices = [];
            }

            foreach ($rawServices as $name => $def) {
                $name = (string) $name;

                if (isset($serviceOwner[$name])) {
                    $errors[] = "servizio \"{$name}\" definito sia da {$serviceOwner[$name]} sia da {$slug}";

                    continue;
                }
                $serviceOwner[$name] = $slug;

                if (! is_array($def)) {
                    $errors[] = "{$slug}: il servizio \"{$name}\" non è una mappa valida";

                    continue;
                }

                $isInit = false;
                if (array_key_exists('x-dada', $def)) {
                    $xDada = $def['x-dada'];
                    if (is_array($xDada) && array_key_exists('init', $xDada)) {
                        if (! is_bool($xDada['init'])) {
                            $errors[] = "{$slug}: x-dada.init del servizio \"{$name}\" deve essere true o false";
                        }
                        $isInit = $xDada['init'] === true;
                    }
                    unset($def['x-dada']);
                }

                if ($hasContainerVars) {
                    $def['env_file'] = self::withEnvFile($def['env_file'] ?? null, ".env.{$slug}");
                }

                foreach (self::usedNetworks($def) as $network) {
                    $usages[] = [$slug, $name, 'network', $network];
                }
                foreach (self::usedVolumes($def) as $volume) {
                    $usages[] = [$slug, $name, 'volume', $volume];
                }

                if ($isInit && $swarm) {
                    $initServices[$slug][$name] = $def;

                    continue;
                }

                if ($isInit) {
                    $profiles = isset($def['profiles']) ? array_values((array) $def['profiles']) : [];
                    if (! in_array('init', $profiles, true)) {
                        $profiles[] = 'init';
                    }
                    $def['profiles'] = $profiles;
                }

                $services[$name] = $def;
                $servicesBySlug[$slug][] = $name;
            }

            foreach (self::SECTIONS as $section) {
                $declared = $compose[$section] ?? [];
                if (! is_array($declared)) {
                    $errors[] = "{$slug}: la sezione {$section} non è una mappa valida";

                    continue;
                }
                foreach ($declared as $name => $def) {
                    $name = (string) $name;
                    if ($section !== 'configs' && $def === []) {
                        $def = null;
                    }
                    if (array_key_exists($name, $sections[$section])) {
                        if (self::canonical($sections[$section][$name]) !== self::canonical($def)) {
                            $errors[] = "{$section} \"{$name}\" definito in modo diverso da {$origins[$section][$name]} e da {$slug}";
                        }

                        continue;
                    }
                    $sections[$section][$name] = $def;
                    $origins[$section][$name] = $slug;
                }
            }
        }

        foreach ($usages as [$slug, $service, $kind, $name]) {
            if ($kind === 'network' && $name !== 'default' && ! array_key_exists($name, $sections['networks'])) {
                $errors[] = "{$slug}: il servizio \"{$service}\" usa la network \"{$name}\" che non è dichiarata";
            }
            if ($kind === 'volume' && ! array_key_exists($name, $sections['volumes'])) {
                $errors[] = "{$slug}: il servizio \"{$service}\" usa il volume \"{$name}\" che non è dichiarato";
            }
        }

        if ($errors !== []) {
            throw new GenerationFailed(array_values(array_unique($errors)));
        }

        if ($swarm && $initServices !== [] && ! array_key_exists('default', $sections['networks'])) {
            $sections['networks']['default'] = ['driver' => 'overlay', 'attachable' => true];
        }

        $compose = $swarm ? ['services' => $services] : ['name' => $dh->stack_name, 'services' => $services];
        foreach ($sections as $section => $declared) {
            if ($declared !== []) {
                $compose[$section] = $declared;
            }
        }

        return new BuiltCompose($compose, $initServices, $servicesBySlug, []);
    }

    /** @return array<string, mixed> */
    private static function normalizeSection(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        return array_map(fn ($def) => $def === [] ? null : $def, $section);
    }

    /** @return list<mixed> */
    private static function withEnvFile(mixed $current, string $file): array
    {
        $list = match (true) {
            $current === null => [],
            is_array($current) => array_values($current),
            default => [$current],
        };
        if (! in_array($file, $list, true)) {
            $list[] = $file;
        }

        return $list;
    }

    /** @return list<string> */
    private static function usedNetworks(array $def): array
    {
        $networks = $def['networks'] ?? null;
        if (! is_array($networks)) {
            return [];
        }

        $names = array_is_list($networks) ? $networks : array_keys($networks);

        return array_values(array_filter(array_map('strval', $names), fn (string $n) => $n !== ''));
    }

    /** @return list<string> */
    private static function usedVolumes(array $def): array
    {
        $names = [];
        foreach ((array) ($def['volumes'] ?? []) as $volume) {
            if (is_string($volume)) {
                if (! str_contains($volume, ':')) {
                    continue; // volume anonimo
                }
                $source = explode(':', $volume, 2)[0];
                if ($source !== '' && ! str_contains($source, '$') && ! str_contains($source, '/') && ! in_array($source[0], ['.', '/', '~'], true)) {
                    $names[] = $source;
                }
            } elseif (is_array($volume) && ($volume['type'] ?? null) === 'volume'
                && isset($volume['source']) && is_string($volume['source']) && $volume['source'] !== ''
                && ! str_contains($volume['source'], '$')) {
                $names[] = $volume['source'];
            }
        }

        return $names;
    }

    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return json_encode($sort($value));
    }
}
