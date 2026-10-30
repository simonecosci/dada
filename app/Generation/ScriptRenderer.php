<?php

namespace App\Generation;

use App\Enums\Orchestrator;
use App\Models\DeploymentHost;
use App\Models\Installation;
use Illuminate\Support\Collection;

/**
 * Generates `<slug>.sh` and `restart.sh`. These scripts run as root: every
 * fixed value goes through quote(), every value that must expand ${VAR} goes through interpolated().
 */
final class ScriptRenderer
{
    /** Keys of a swarm init translated into `docker run` options; the others produce a warning. */
    public const SWARM_INIT_KEYS = ['image', 'command', 'environment', 'env_file', 'networks', 'volumes'];

    /** Swarm modes without a replica count to scale. */
    private const UNSCALABLE_MODES = ['global', 'replicated-job', 'global-job'];

    /**
     * `$$`, or ${NAME}, ${NAME-default}, ${NAME:-default} with a whitelisted default
     * (no quotes, $, `, ", \, } or newline: inside "${X:-...}" bash reinterprets them),
     * or any other `$` (rejected), or a character to protect.
     */
    private const INTERPOLATION = '/\$\$|\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?-)([A-Za-z0-9_.,:\/@%+=~ -]*))?\}|\$|[\\\\"`]/';

    public static function installation(DeploymentHost $dh, Installation $i, BuiltCompose $b): string
    {
        $slug = $i->slug;
        $names = $b->servicesBySlug[$slug] ?? [];

        if ($dh->orchestrator === Orchestrator::Swarm) {
            $services = [];
            foreach (array_values($names) as $index => $name) {
                $def = $b->compose['services'][$name] ?? [];
                $mode = self::mode($slug, $name, $def);
                $services[] = [
                    'fn' => 'svc_'.$index,
                    'name' => self::quote($name),
                    'full' => self::quote($dh->stack_name.'_'.$name),
                    'replicas' => $mode === null ? self::replicas($slug, $name, $def) : null,
                    'mode' => $mode === null ? null : self::quote($mode),
                ];
            }

            return self::render('scripts.swarm.installation', [
                'slug' => self::quote($slug),
                'services' => $services,
                'allFns' => implode(' ', array_column($services, 'fn')),
            ]);
        }

        $services = [];
        $inits = [];
        foreach ($names as $name) {
            $profiles = (array) ($b->compose['services'][$name]['profiles'] ?? []);
            if (in_array('init', $profiles, true)) {
                $inits[] = self::quote($name);
            } else {
                $services[] = self::quote($name);
            }
        }

        return self::render('scripts.compose.installation', [
            'slug' => self::quote($slug),
            'services' => $services,
            'allServices' => implode(' ', $services),
            'inits' => $inits,
        ]);
    }

    /** @param Collection<int, Installation> $installations */
    public static function restart(DeploymentHost $dh, Collection $installations, BuiltCompose $b): string
    {
        $ordered = $installations->sortBy([['order', 'asc'], ['id', 'asc']])->values();

        if ($dh->orchestrator === Orchestrator::Swarm) {
            $runs = [];
            $updates = [];
            foreach ($ordered as $installation) {
                $inits = $b->initServices[$installation->slug] ?? [];
                foreach ($inits as $name => $def) {
                    $runs[] = self::dockerRun($dh, $b, (string) $name, $def);
                }
                if ($inits !== []) {
                    foreach ($b->servicesBySlug[$installation->slug] ?? [] as $service) {
                        $updates[] = self::quote($dh->stack_name.'_'.$service);
                    }
                }
            }

            return self::render('scripts.swarm.restart', [
                'stack' => self::quote($dh->stack_name),
                'runs' => $runs,
                'updates' => $updates,
            ]);
        }

        $scripts = $ordered->map(fn (Installation $i) => self::quote('./'.$i->slug.'.sh'))->all();

        return self::render('scripts.compose.restart', [
            'scripts' => $scripts,
            'reversed' => array_reverse($scripts),
        ]);
    }

    /** POSIX single quote, like escapeshellarg() on Linux (on Windows escapeshellarg uses double quotes). */
    public static function quote(string $value): string
    {
        self::rejectNul($value);

        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /**
     * Double-quoted string in which only ${NAME} stays expandable (emitted as
     * ${NAME-}, so `set -u` does not abort: in compose a missing variable is empty),
     * ${NAME-default} and ${NAME:-default} with a whitelisted default. `$$` becomes a literal
     * `$`; " \ ` are protected by backslash; any other `$` ($VAR, $(...), ${X:?},
     * ${X:+y}, ${A:-${B}}, default with non-whitelisted characters) is GenerationFailed.
     */
    public static function interpolated(string $value, string $context = 'value'): string
    {
        self::rejectNul($value);

        $escaped = preg_replace_callback(
            self::INTERPOLATION,
            fn (array $m) => match (true) {
                $m[0] === '$$' => '\\$',
                $m[0] === '$' => throw new GenerationFailed([
                    "{$context}: unsupported expression with \$ (use \${VAR}, \${VAR:-default} or \$\$)",
                ]),
                isset($m[1]) && $m[1] !== '' => '${'.$m[1].(($m[2] ?? '') === '' ? '-' : $m[2].$m[3]).'}',
                default => '\\'.$m[0],
            },
            $value,
        );

        return '"'.$escaped.'"';
    }

    /** @param array<string, mixed> $def */
    private static function dockerRun(DeploymentHost $dh, BuiltCompose $b, string $name, array $def): string
    {
        $parts = ['docker', 'run', '--rm'];

        $networks = self::names($def['networks'] ?? null);
        foreach ($networks === [] ? ['default'] : $networks as $network) {
            $parts[] = '--network';
            $parts[] = self::quote(self::resourceName($dh, $b, 'networks', $network));
        }

        foreach (self::envFiles($name, $def['env_file'] ?? null) as $file) {
            $parts[] = '--env-file';
            $parts[] = self::quote($file);
        }

        foreach (self::environment($name, $def['environment'] ?? null) as $option) {
            $parts[] = '-e';
            $parts[] = $option;
        }

        foreach ((array) ($def['volumes'] ?? []) as $volume) {
            $parts[] = '-v';
            $parts[] = self::volume($dh, $b, $name, $volume);
        }

        if (! isset($def['image']) || ! is_string($def['image']) || $def['image'] === '') {
            throw new GenerationFailed(["init {$name}: image missing"]);
        }
        $parts[] = self::interpolated($def['image'], "init {$name}: image");

        foreach (self::command($name, $def['command'] ?? null) as $arg) {
            $parts[] = self::interpolated($arg, "init {$name}: command");
        }

        return implode(' ', $parts);
    }

    /** @return list<string> */
    private static function names(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_map('strval', array_is_list($value) ? $value : array_keys($value));
    }

    /** Real name of a network or volume: explicit `name:`, bare name if external, otherwise `<stack>_<name>`. */
    private static function resourceName(DeploymentHost $dh, BuiltCompose $b, string $section, string $name): string
    {
        $def = $b->compose[$section][$name] ?? null;
        if (is_array($def) && isset($def['name']) && is_string($def['name']) && $def['name'] !== '') {
            return $def['name'];
        }
        if (is_array($def) && ! empty($def['external'])) {
            return $name;
        }

        return $dh->stack_name.'_'.$name;
    }

    /** @return list<string> */
    private static function envFiles(string $name, mixed $value): array
    {
        $files = [];
        foreach ($value === null ? [] : (is_array($value) && array_is_list($value) ? $value : [$value]) as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            if (! is_string($path) || $path === '') {
                throw new GenerationFailed(["init {$name}: invalid env_file"]);
            }
            $files[] = $path;
        }

        return $files;
    }

    /** @return list<string> already-quoted arguments for `-e` */
    private static function environment(string $name, mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw new GenerationFailed(["init {$name}: invalid environment"]);
        }

        $pairs = [];
        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (! is_string($item)) {
                    throw new GenerationFailed(["init {$name}: invalid environment"]);
                }
                $pairs[] = str_contains($item, '=') ? explode('=', $item, 2) : [$item, null];
            }
        } else {
            foreach ($value as $key => $v) {
                $pairs[] = [(string) $key, $v === null ? null : self::scalar($name, 'environment', $v)];
            }
        }

        return array_map(
            fn (array $p) => $p[1] === null
                ? self::quote($p[0])
                : self::quote($p[0].'=').self::interpolated($p[1], "init {$name}: environment {$p[0]}"),
            $pairs,
        );
    }

    private static function volume(DeploymentHost $dh, BuiltCompose $b, string $name, mixed $volume): string
    {
        if (is_string($volume)) {
            if (! str_contains($volume, ':')) {
                return self::interpolated($volume, "init {$name}: volumes");
            }
            [$source, $rest] = explode(':', $volume, 2);
            if (self::isNamedVolume($source)) {
                return self::quote(self::resourceName($dh, $b, 'volumes', $source).':'.$rest);
            }

            return self::interpolated($volume, "init {$name}: volumes");
        }

        if (is_array($volume) && isset($volume['source'], $volume['target'])
            && is_string($volume['source']) && is_string($volume['target'])
            && in_array($volume['type'] ?? null, ['volume', 'bind'], true)) {
            $suffix = ! empty($volume['read_only']) ? ':ro' : '';
            if ($volume['type'] === 'volume' && self::isNamedVolume($volume['source'])) {
                return self::quote(self::resourceName($dh, $b, 'volumes', $volume['source']).':'.$volume['target'].$suffix);
            }

            return self::interpolated($volume['source'].':'.$volume['target'].$suffix, "init {$name}: volumes");
        }

        throw new GenerationFailed(["init {$name}: unsupported volume"]);
    }

    private static function isNamedVolume(string $source): bool
    {
        return $source !== '' && ! str_contains($source, '$') && ! str_contains($source, '/')
            && ! in_array($source[0], ['.', '~'], true);
    }

    /** @return list<string> */
    private static function command(string $name, mixed $command): array
    {
        if ($command === null) {
            return [];
        }
        if (is_string($command)) {
            return self::shellWords($command) ?? throw new GenerationFailed(["init {$name}: command cannot be parsed"]);
        }
        if (is_array($command) && array_is_list($command)) {
            return array_map(fn ($arg) => self::scalar($name, 'command', $arg, 'command cannot be parsed'), $command);
        }

        throw new GenerationFailed(["init {$name}: command cannot be parsed"]);
    }

    /**
     * Splits a string as a shell would, without expansions: single quotes, double
     * (with \" \\ \$ \` ) and backslash. Null if the quotes are not balanced.
     *
     * @return list<string>|null
     */
    private static function shellWords(string $s): ?array
    {
        $words = [];
        $current = '';
        $inWord = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];

            if ($c === "'") {
                $end = strpos($s, "'", $i + 1);
                if ($end === false) {
                    return null;
                }
                $current .= substr($s, $i + 1, $end - $i - 1);
                $i = $end;
                $inWord = true;
            } elseif ($c === '"') {
                $inWord = true;
                for ($i++; ; $i++) {
                    if ($i >= $len) {
                        return null;
                    }
                    if ($s[$i] === '"') {
                        break;
                    }
                    if ($s[$i] === '\\' && $i + 1 < $len && in_array($s[$i + 1], ['"', '\\', '$', '`'], true)) {
                        $i++;
                    }
                    $current .= $s[$i];
                }
            } elseif ($c === '\\') {
                if ($i + 1 >= $len) {
                    return null;
                }
                $current .= $s[++$i];
                $inWord = true;
            } elseif (ctype_space($c)) {
                if ($inWord) {
                    $words[] = $current;
                    $current = '';
                    $inWord = false;
                }
            } else {
                $current .= $c;
                $inWord = true;
            }
        }

        if ($inWord) {
            $words[] = $current;
        }

        return $words;
    }

    private static function scalar(string $name, string $key, mixed $value, ?string $message = null): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => throw new GenerationFailed(["init {$name}: ".($message ?? "{$key} is invalid")]),
        };
    }

    /**
     * Null for replicated (scalable) services, otherwise the mode.
     *
     * @param  array<string, mixed>  $def
     */
    private static function mode(string $slug, string $name, array $def): ?string
    {
        $mode = is_array($def['deploy'] ?? null) ? ($def['deploy']['mode'] ?? 'replicated') : 'replicated';
        if ($mode === 'replicated') {
            return null;
        }
        if (! is_string($mode) || ! in_array($mode, self::UNSCALABLE_MODES, true)) {
            throw new GenerationFailed(["{$slug}: deploy.mode of service \"{$name}\" is invalid"]);
        }

        return $mode;
    }

    /** @param array<string, mixed> $def */
    private static function replicas(string $slug, string $name, array $def): int
    {
        $replicas = is_array($def['deploy'] ?? null) ? ($def['deploy']['replicas'] ?? 1) : 1;

        if (is_string($replicas) && preg_match('/\A[0-9]+\z/', $replicas)) {
            $replicas = (int) $replicas;
        }
        if (! is_int($replicas) || $replicas < 0) {
            throw new GenerationFailed(["{$slug}: deploy.replicas of service \"{$name}\" must be a non-negative integer"]);
        }

        return $replicas;
    }

    private static function rejectNul(string $value): void
    {
        if (str_contains($value, "\0")) {
            throw new GenerationFailed(['value with NUL character not supported in scripts']);
        }
    }

    /**
     * The shebang is added here because PHP discards a `#!` line at the start of the compiled view.
     *
     * @param  array<string, mixed>  $data
     */
    private static function render(string $view, array $data): string
    {
        return "#!/usr/bin/env bash\n".ltrim(str_replace("\r", '', view($view, $data)->render()), "\n");
    }
}
