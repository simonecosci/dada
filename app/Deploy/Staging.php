<?php

namespace App\Deploy;

use App\Generation\ScriptRenderer;
use App\Ssh\RemoteShell;
use InvalidArgumentException;
use RuntimeException;

/**
 * A temporary directory on the host, created and written as the login user (no escalation),
 * from which RemoteSteps::installCommand() copies files to their final place.
 */
final class Staging
{
    private function __construct(
        private readonly RemoteShell $shell,
        public readonly string $dir,
    ) {}

    public static function open(RemoteShell $shell): self
    {
        $dir = trim($shell->exec('mktemp -d')->stdout);

        if (preg_match('/\A\/[A-Za-z0-9._\/-]+\z/', $dir) !== 1) {
            throw new RuntimeException('unable to create a temporary directory on the host');
        }

        return new self($shell, $dir);
    }

    /** Uploads $contents as $name inside the staging directory and returns its remote path. */
    public function put(string $name, string $contents, int $mode): string
    {
        if (preg_match('/\A[A-Za-z0-9._-]+\z/', $name) !== 1) {
            throw new InvalidArgumentException("unsafe staging file name: {$name}");
        }

        $path = "{$this->dir}/{$name}";
        $this->shell->put($path, $contents, $mode);

        return $path;
    }

    /** Removes the directory; the result is ignored. */
    public function close(): void
    {
        $this->shell->exec('rm -rf '.ScriptRenderer::quote($this->dir));
    }
}
