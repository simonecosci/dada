<?php

namespace App\Deploy;

use App\Generation\ScriptRenderer;
use App\Infrastructure\Privilege;
use App\Models\DeploymentHost;

class RemoteSteps
{
    /**
     * Runs $cmd in the deployment host directory, escalated per the host's privilege mode.
     * $cmd must already have every interpolated value quoted with ScriptRenderer::quote().
     */
    public static function wrap(DeploymentHost $dh, string $cmd): string
    {
        return Privilege::for($dh->host)->wrap('cd '.ScriptRenderer::quote($dh->path).' && '.$cmd);
    }

    /** What the wrapped command needs on stdin (the sudo password), or null. */
    public static function stdin(DeploymentHost $dh): ?string
    {
        return Privilege::for($dh->host)->stdin();
    }

    /**
     * Copies staged files into $targetDir atomically (copy to a temp name, chmod, mv).
     * Meant to be wrapped with the host's Privilege so the files end up owned by the escalated user.
     *
     * @param  array<string, int>  $modes  file name => final mode
     */
    public static function installCommand(string $stagingDir, string $targetDir, array $modes): string
    {
        $stagingDir = rtrim($stagingDir, '/');
        $targetDir = rtrim($targetDir, '/');
        $steps = ['umask 077'];

        foreach ($modes as $name => $mode) {
            $source = ScriptRenderer::quote("{$stagingDir}/{$name}");
            $tmp = ScriptRenderer::quote("{$targetDir}/{$name}.dada-tmp");
            $final = ScriptRenderer::quote("{$targetDir}/{$name}");
            $steps[] = "cp {$source} {$tmp} && chmod ".decoct($mode)." {$tmp} && mv -f {$tmp} {$final}";
        }

        return implode(' && ', $steps);
    }
}
