<?php

namespace App\Generation;

use App\Catalog\ComposeFragment;
use App\Enums\Orchestrator;
use App\Models\DeploymentHost;

class HostFilesGenerator
{
    /** Non lancia mai: gli errori di generazione finiscono in `errors` e `files` resta vuoto. */
    public function generate(DeploymentHost $dh): GeneratedFiles
    {
        $vars = EffectiveVariables::for($dh);
        $installations = $dh->installations()->orderBy('order')->orderBy('id')->get();

        $errors = $vars->errors();
        $warnings = $vars->warnings();
        $files = [];
        $servicesBySlug = [];

        try {
            $format = $dh->orchestrator === Orchestrator::Swarm ? EnvFormat::Raw : EnvFormat::Quoted;
            $built = ComposeBuilder::build($dh, $installations, $vars);
            $servicesBySlug = $built->servicesBySlug;
            $warnings = array_merge($warnings, $built->warnings);

            $files['docker-compose.yml'] = ComposeFragment::dump($built->compose);
            $files['.env'] = EnvFileWriter::render($vars->dotEnv(), 'host', $format);
            foreach ($installations as $installation) {
                $env = $vars->containerEnv($installation);
                if ($env !== []) {
                    $files['.env.'.$installation->slug] = EnvFileWriter::render($env, $installation->slug, $format);
                }
            }
            foreach ($installations as $installation) {
                $files[$installation->slug.'.sh'] = ScriptRenderer::installation($dh, $installation, $built);
            }
            $files['restart.sh'] = ScriptRenderer::restart($dh, $installations, $built);
        } catch (GenerationFailed $e) {
            $errors = array_merge($errors, $e->errors);
        }

        $errors = array_values(array_unique($errors));
        if ($errors !== []) {
            $files = [];
        }

        return new GeneratedFiles(
            files: $files,
            errors: $errors,
            warnings: array_values(array_unique($warnings)),
            missingRequired: $vars->missingRequired(),
            secretValues: $vars->secretValues(),
            servicesBySlug: $servicesBySlug,
        );
    }
}
