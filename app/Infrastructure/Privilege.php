<?php

namespace App\Infrastructure;

use App\Enums\PrivilegeMode;
use App\Generation\ScriptRenderer;
use App\Models\Host;
use RuntimeException;

/**
 * How commands are escalated on a managed host, and what sudo needs on stdin.
 */
final readonly class Privilege
{
    public function __construct(
        public PrivilegeMode $mode,
        private ?string $password = null,
    ) {}

    public static function for(Host $host): self
    {
        if ($host->privilege_mode !== PrivilegeMode::SudoPassword) {
            return new self($host->privilege_mode);
        }

        $secret = $host->sudoCredential?->secret;

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException("host {$host->name}: the sudo password credential is missing");
        }

        // sudo -S reads one line: a line break would split the password and leak the rest to the command.
        if (str_contains($secret, "\n") || str_contains($secret, "\r")) {
            throw new RuntimeException("host {$host->name}: the sudo password must not contain line breaks");
        }

        return new self(PrivilegeMode::SudoPassword, $secret);
    }

    public function escalates(): bool
    {
        return $this->mode !== PrivilegeMode::None;
    }

    /**
     * Escalated commands start with `exec </dev/null`: if sudo does not read the password
     * (cached timestamp, NOPASSWD), it must not reach the stdin of $cmd.
     */
    public function wrap(string $cmd): string
    {
        return match ($this->mode) {
            PrivilegeMode::None => $cmd,
            PrivilegeMode::Sudo => 'sudo -n -H sh -c '.ScriptRenderer::quote('exec </dev/null; '.$cmd),
            PrivilegeMode::SudoPassword => "sudo -S -p '' -H sh -c ".ScriptRenderer::quote('exec </dev/null; '.$cmd),
        };
    }

    public function stdin(): ?string
    {
        return $this->mode === PrivilegeMode::SudoPassword ? "{$this->password}\n" : null;
    }

    public function validateCommand(): ?string
    {
        return match ($this->mode) {
            PrivilegeMode::None => null,
            PrivilegeMode::Sudo => 'sudo -n -v',
            PrivilegeMode::SudoPassword => "sudo -S -p '' -v",
        };
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return $this->mode === PrivilegeMode::SudoPassword && $this->password !== null ? [$this->password] : [];
    }
}
