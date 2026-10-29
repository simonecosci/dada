<?php

namespace App\Generation;

use App\Catalog\ComposeFragment;
use App\Enums\Orchestrator;
use App\Models\DeploymentHost;
use Illuminate\Contracts\Encryption\DecryptException;
use Throwable;

class HostFilesGenerator
{
    /** Non lancia mai: ogni errore finisce in `errors` e `files` resta vuoto. */
    public function generate(DeploymentHost $dh): GeneratedFiles
    {
        $errors = [];
        $warnings = [];
        $files = [];
        $servicesBySlug = [];
        $missingRequired = [];
        $secretValues = [];

        try {
            $vars = EffectiveVariables::for($dh);
            $missingRequired = $vars->missingRequired();
            $secretValues = $vars->secretValues();
            $errors = $vars->errors();
            $warnings = $vars->warnings();

            $installations = $dh->installations()->orderBy('order')->orderBy('id')->get();
            $format = $dh->orchestrator === Orchestrator::Swarm ? EnvFormat::Raw : EnvFormat::Quoted;
            $built = ComposeBuilder::build($dh, $installations, $vars);
            $servicesBySlug = $built->servicesBySlug;
            $warnings = array_merge($warnings, $built->warnings);

            $put = function (string $name, string $contents) use (&$files, &$errors): void {
                if (array_key_exists($name, $files)) {
                    $errors[] = "{$name}: il file verrebbe generato due volte (nome in conflitto)";

                    return;
                }
                $files[$name] = $contents;
            };

            $put('docker-compose.yml', ComposeFragment::dump($built->compose));
            $put('.env', EnvFileWriter::render($vars->dotEnv(), 'host', $format));
            foreach ($installations as $installation) {
                $env = $vars->containerEnv($installation);
                if ($env !== []) {
                    $put('.env.'.$installation->slug, EnvFileWriter::render($env, $installation->slug, $format));
                }
            }
            foreach ($installations as $installation) {
                $put($installation->slug.'.sh', ScriptRenderer::installation($dh, $installation, $built));
            }
            $put('restart.sh', ScriptRenderer::restart($dh, $installations, $built));
        } catch (GenerationFailed $e) {
            $errors = array_merge($errors, $e->errors);
        } catch (Throwable $e) {
            report($e);
            $errors[] = $e instanceof DecryptException
                ? 'impossibile decifrare una variabile segreta'
                : 'errore interno durante la generazione ('.$e::class.')';
        }

        $errors = array_values(array_unique($errors));
        if ($errors !== []) {
            $files = [];
        }

        return new GeneratedFiles(
            files: $files,
            errors: $errors,
            warnings: array_values(array_unique($warnings)),
            missingRequired: $missingRequired,
            secretValues: $secretValues,
            servicesBySlug: $servicesBySlug,
        );
    }
}
