<?php

namespace App\Deploy;

use App\Generation\ScriptRenderer;
use App\Models\DeploymentHost;

class RemoteSteps
{
    /**
     * Runs $cmd in the deployment host directory, with the host's sudo prefix if configured.
     * $cmd must already have every interpolated value quoted with ScriptRenderer::quote().
     */
    public static function wrap(DeploymentHost $dh, string $cmd): string
    {
        return self::withSudo(self::sudo($dh), 'cd '.ScriptRenderer::quote($dh->path).' && '.$cmd);
    }

    /** $cmd as is without sudo, otherwise `<sudo> sh -c '<cmd>'`. */
    public static function withSudo(string $sudo, string $cmd): string
    {
        $sudo = trim($sudo);

        return $sudo === '' ? $cmd : $sudo.' sh -c '.ScriptRenderer::quote($cmd);
    }

    /** Sudo prefix configured by the administrator on the host (text emitted as is). */
    public static function sudo(DeploymentHost $dh): string
    {
        return trim((string) $dh->host->sudo);
    }
}
