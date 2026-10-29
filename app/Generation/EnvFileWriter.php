<?php

namespace App\Generation;

class EnvFileWriter
{
    /** @param array<string, ?string> $vars */
    public static function render(array $vars, string $context): string
    {
        $out = '';
        foreach ($vars as $key => $value) {
            $out .= $key.'='.self::quote((string) $key, $value ?? '', $context)."\n";
        }

        return $out;
    }

    private static function quote(string $key, string $value, string $context): string
    {
        $hasSingle = str_contains($value, "'");

        if (str_contains($value, "\n") || str_contains($value, "\r") || ($hasSingle && str_contains($value, '$'))) {
            throw new GenerationFailed(["{$context}: {$key} contiene caratteri non supportati (newline, oppure ' e \$ insieme)"]);
        }

        if (! $hasSingle) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
