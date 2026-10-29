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
            throw new InvalidComposeFragment(['YAML non valido: '.$e->getMessage()]);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidComposeFragment(['(radice): deve essere una mappa']);
        }

        $errors = [];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::TOP_LEVEL, true)) {
                $errors[] = "{$key}: chiave non consentita (ammesse: ".implode(', ', self::TOP_LEVEL).')';
            }
        }

        $services = $data['services'] ?? null;

        if (! is_array($services) || $services === [] || array_is_list($services)) {
            $errors[] = 'services: deve essere una mappa non vuota di servizi';
        } else {
            foreach ($services as $name => $service) {
                $name = (string) $name;
                if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                    $errors[] = "services.{$name}: nome non valido";
                }
                if (! is_array($service) || ($service !== [] && array_is_list($service))) {
                    $errors[] = "services.{$name}: deve essere una mappa";

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
            if (array_key_exists($key, $data) && $data[$key] !== null && ! is_array($data[$key])) {
                $errors[] = "{$key}: deve essere una mappa";
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
            return ["services.{$name}.x-dada: deve essere una mappa"];
        }

        $errors = [];
        foreach (array_keys($xDada) as $key) {
            if ($key !== 'init') {
                $errors[] = "services.{$name}.x-dada.{$key}: chiave non consentita (ammessa: init)";
            }
        }
        if (array_key_exists('init', $xDada) && ! is_bool($xDada['init'])) {
            $errors[] = "services.{$name}.x-dada.init: deve essere booleano";
        }

        return $errors;
    }
}
