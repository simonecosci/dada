<?php

namespace App\Deployments;

use App\Enums\VariableScope;
use App\Models\DeploymentHost;
use App\Models\ProjectVersion;
use App\Models\Variable;
use Illuminate\Support\Collection;

class HostGlobalSync
{
    /**
     * Porta sull'host le variabili host-global dichiarate da una versione.
     *
     * @param  Collection<int, Variable>  $versionVars
     * @return list<string> warnings
     */
    public static function absorb(DeploymentHost $dh, Collection $versionVars): array
    {
        $existing = $dh->variables()->reorder()->get()->keyBy('key');
        $position = $existing->isEmpty() ? -1 : (int) $existing->max('position');
        $warnings = [];

        foreach ($versionVars as $var) {
            $current = $existing->get($var->key);

            if ($current === null) {
                $position++;
                $created = $dh->variables()->create([
                    'key' => $var->key,
                    'description' => $var->description,
                    'required' => $var->required,
                    'secret' => $var->secret,
                    'scope' => VariableScope::HostGlobal,
                    'default' => $var->default,
                    'use_default' => true,
                    'position' => $position,
                ]);
                $existing->put($var->key, $created);

                continue;
            }

            $current->required = $current->required || $var->required;
            $current->secret = $current->secret || $var->secret;
            $current->save();

            if ($var->default !== null && $var->default !== $current->default) {
                $warnings[] = $current->secret
                    ? "{$var->key}: default dell'host mantenuto"
                    : "{$var->key}: default dell'host mantenuto (versione: {$var->default})";
            }
        }

        return $warnings;
    }

    /**
     * Chiavi host-global dell'host che nessuna versione d'origine delle installazioni dichiara.
     *
     * @return list<string>
     */
    public static function unused(DeploymentHost $dh): array
    {
        $versionIds = $dh->installations()->reorder()->pluck('project_version_id')->unique()->all();

        $declared = Variable::query()
            ->where('owner_type', (new ProjectVersion)->getMorphClass())
            ->whereIn('owner_id', $versionIds)
            ->where('scope', VariableScope::HostGlobal->value)
            ->pluck('key')
            ->all();

        return $dh->variables()
            ->where('scope', VariableScope::HostGlobal->value)
            ->pluck('key')
            ->reject(fn (string $key) => in_array($key, $declared, true))
            ->values()
            ->all();
    }
}
