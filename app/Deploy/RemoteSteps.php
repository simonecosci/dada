<?php

namespace App\Deploy;

use App\Generation\ScriptRenderer;
use App\Models\DeploymentHost;

class RemoteSteps
{
    /**
     * Esegue $cmd nella cartella del deployment host, con il prefisso sudo dell'host se configurato.
     * $cmd deve avere già ogni valore interpolato quotato con ScriptRenderer::quote().
     */
    public static function wrap(DeploymentHost $dh, string $cmd): string
    {
        return self::withSudo(self::sudo($dh), 'cd '.ScriptRenderer::quote($dh->path).' && '.$cmd);
    }

    /** $cmd così com'è senza sudo, altrimenti `<sudo> sh -c '<cmd>'`. */
    public static function withSudo(string $sudo, string $cmd): string
    {
        $sudo = trim($sudo);

        return $sudo === '' ? $cmd : $sudo.' sh -c '.ScriptRenderer::quote($cmd);
    }

    /** Prefisso sudo configurato dall'amministratore sull'host (testo emesso così com'è). */
    public static function sudo(DeploymentHost $dh): string
    {
        return trim((string) $dh->host->sudo);
    }
}
