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
        // Un segreto multi-riga (chiave PEM, ...) compare nell'output riga per riga: registra anche ogni riga.
        $expanded = [];
        foreach ($secrets as $secret) {
            $expanded[] = $secret;
            if (is_string($secret) && preg_match('/[\r\n]/', $secret) === 1) {
                foreach (preg_split('/\r\n|\r|\n/', $secret) as $line) {
                    $expanded[] = trim($line);
                }
            }
        }

        $secrets = array_filter($expanded, fn ($s) => is_string($s) && mb_strlen($s) >= 3);
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

    /** Lunghezza in byte del segreto più lungo (0 se non ce ne sono). */
    public function maxLength(): int
    {
        return $this->secrets === [] ? 0 : strlen($this->secrets[0]);
    }

    /** @param  list<string>  $more */
    public function with(array $more): self
    {
        return new self([...$this->secrets, ...$more]);
    }
}
