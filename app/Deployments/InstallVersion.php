<?php

namespace App\Deployments;

use App\Enums\VariableScope;
use App\Models\DeploymentHost;
use App\Models\ProjectVersion;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallVersion
{
    public function handle(DeploymentHost $dh, ProjectVersion $v, ?string $slug = null): InstallResult
    {
        if (! $v->isPublished()) {
            throw new DomainException('Si possono installare solo versioni pubblicate.');
        }

        $slug ??= $v->project->slug;

        return DB::transaction(function () use ($dh, $v, $slug): InstallResult {
            // Serializza le installazioni concorrenti sullo stesso host (slug e ordine).
            DeploymentHost::whereKey($dh->id)->lockForUpdate()->first();

            if ($dh->installations()->reorder()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => "Esiste già un'installazione con slug {$slug} su questo host."]);
            }

            $installation = $dh->installations()->create([
                'project_version_id' => $v->id,
                'slug' => $slug,
                'order' => (int) $dh->installations()->reorder()->max('order') + 1,
                'compose' => $v->compose,
                'commands' => $v->commands,
            ]);

            $vars = $v->variables()->get();

            foreach ($vars->filter(fn ($x) => $x->scope !== VariableScope::HostGlobal)->values() as $i => $var) {
                $installation->variables()->create([
                    'key' => $var->key,
                    'description' => $var->description,
                    'required' => $var->required,
                    'secret' => $var->secret,
                    'scope' => $var->scope,
                    'default' => $var->default,
                    'value' => null,
                    'use_default' => true,
                    'position' => $i,
                ]);
            }

            $warnings = HostGlobalSync::absorb($dh, $vars->filter(fn ($x) => $x->scope === VariableScope::HostGlobal)->values());

            return new InstallResult($installation, $warnings);
        });
    }
}
