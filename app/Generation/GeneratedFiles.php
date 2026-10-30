<?php

namespace App\Generation;

final class GeneratedFiles
{
    /**
     * @param  array<string, string>  $files  name => content
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  list<string>  $missingRequired
     * @param  list<string>  $secretValues
     * @param  array<string, list<string>>  $servicesBySlug
     */
    public function __construct(
        public readonly array $files,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $missingRequired,
        public readonly array $secretValues,
        public readonly array $servicesBySlug,
    ) {}

    public function ok(): bool
    {
        return $this->errors === [] && $this->missingRequired === [];
    }

    /**
     * The files with every secret value replaced by `******`, including the escaped form
     * that EnvFileWriter produces between double quotes. Longer values go first.
     *
     * @return array<string, string>
     */
    public function masked(): array
    {
        $needles = [];
        foreach ($this->secretValues as $secret) {
            if (strlen($secret) < 3) {
                continue;
            }
            $needles[$secret] = true;
            $needles[str_replace(['\\', '"'], ['\\\\', '\\"'], $secret)] = true;
        }

        $needles = array_map('strval', array_keys($needles));
        usort($needles, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return array_map(
            fn (string $contents) => $needles === [] ? $contents : str_replace($needles, '******', $contents),
            $this->files,
        );
    }
}
