<?php

namespace App\Generation;

class EnvFileWriter
{
    /** @param array<string, ?string> $vars */
    public static function render(array $vars, string $context, EnvFormat $format = EnvFormat::Quoted): string
    {
        $out = '';
        foreach ($vars as $key => $value) {
            $value ??= '';
            $out .= $key.'='.match ($format) {
                EnvFormat::Quoted => self::quote((string) $key, $value, $context),
                EnvFormat::Raw => self::raw((string) $key, $value, $context),
            }."\n";
        }

        return $out;
    }

    private static function raw(string $key, string $value, string $context): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw self::unsupported($key, $context, 'contains a newline');
        }

        return $value;
    }

    private static function quote(string $key, string $value, string $context): string
    {
        $hasSingle = str_contains($value, "'");
        $endsWithBackslash = str_ends_with($value, '\\');

        if (str_contains($value, "\n") || str_contains($value, "\r")
            || (($hasSingle || $endsWithBackslash) && str_contains($value, '$'))) {
            throw self::unsupported($key, $context, "newline, or ' and \$ together, or trailing \\ and \$ together");
        }

        if (! $hasSingle && ! $endsWithBackslash) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private static function unsupported(string $key, string $context, string $detail): GenerationFailed
    {
        return new GenerationFailed(["{$context}: {$key} contains unsupported characters ({$detail})"]);
    }
}
