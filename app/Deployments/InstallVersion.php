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
            throw new DomainException('Only published versions can be installed.');
        }

        $slug ??= $v->project->slug;

        if ($slug === 'restart') {
            throw ValidationException::withMessages(['slug' => 'the slug "restart" is reserved']);
        }

        return DB::transaction(function () use ($dh, $v, $slug): InstallResult {
            // Serializes concurrent installations on the same host (slug and order).
            DeploymentHost::whereKey($dh->id)->lockForUpdate()->first();

            if ($dh->installations()->reorder()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => "An installation with slug {$slug} already exists on this host."]);
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
