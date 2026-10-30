<?php

namespace App\Catalog;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ComposeFragment
{
    private const TOP_LEVEL = ['services', 'volumes', 'networks', 'configs'];

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidComposeFragment
     */
    public static function parse(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new InvalidComposeFragment(['Invalid YAML: '.$e->getMessage()]);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidComposeFragment(['(root): must be a map']);
        }

        $errors = [];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::TOP_LEVEL, true)) {
                $errors[] = "{$key}: key not allowed (allowed: ".implode(', ', self::TOP_LEVEL).')';
            }
        }

        $services = $data['services'] ?? null;

        if (! is_array($services) || $services === [] || array_is_list($services)) {
            $errors[] = 'services: must be a non-empty map of services';
        } else {
            foreach ($services as $name => $service) {
                $name = (string) $name;
                if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $name)) {
                    $errors[] = "services.{$name}: invalid name";
                }
                if (! is_array($service) || ($service !== [] && array_is_list($service))) {
                    $errors[] = "services.{$name}: must be a map";

                    continue;
                }
                if (! isset($service['image']) || ! is_string($service['image']) || trim($service['image']) === '') {
                    $errors[] = "services.{$name}: manca \"image\"";
                }
                if (array_key_exists('x-dada', $service)) {
                    $errors = array_merge($errors, self::validateXDada($name, $service['x-dada']));
                }
            }
        }

        foreach (['volumes', 'networks', 'configs'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null
                && (! is_array($data[$key]) || ($data[$key] !== [] && array_is_list($data[$key])))) {
                $errors[] = "{$key}: must be a map";
            }
        }

        if ($errors !== []) {
            throw new InvalidComposeFragment($errors);
        }

        return $data;
    }

    /** @param array<string, mixed> $compose */
    public static function dump(array $compose): string
    {
        return Yaml::dump($compose, 10, 2);
    }

    /** @return list<string> */
    private static function validateXDada(string $name, mixed $xDada): array
    {
        if (! is_array($xDada) || ($xDada !== [] && array_is_list($xDada))) {
            return ["services.{$name}.x-dada: must be a map"];
        }

        $errors = [];
        foreach (array_keys($xDada) as $key) {
            if ($key !== 'init') {
                $errors[] = "services.{$name}.x-dada.{$key}: key not allowed (allowed: init)";
            }
        }
        if (array_key_exists('init', $xDada) && ! is_bool($xDada['init'])) {
            $errors[] = "services.{$name}.x-dada.init: must be a boolean";
        }

        return $errors;
    }
}
