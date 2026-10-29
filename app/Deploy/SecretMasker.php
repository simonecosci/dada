<?php

namespace App\Deploy;

class SecretMasker
{
    public const MASK = '******';

    /** @var list<string> */
    private array $secrets;

    /** @param  list<string>  $secrets */
    public function __construct(array $secrets)
    {
        $secrets = array_filter($secrets, fn ($s) => is_string($s) && mb_strlen($s) >= 3);
        $secrets = array_values(array_unique($secrets));
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $this->secrets = $secrets;
    }

    public function mask(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        return $text;
    }

    /** @param  list<string>  $more */
    public function with(array $more): self
    {
        return new self([...$this->secrets, ...$more]);
    }
}
