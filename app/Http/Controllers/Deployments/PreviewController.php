<?php

namespace App\Http\Controllers\Deployments;

use App\Generation\HostFilesGenerator;
use App\Http\Controllers\Controller;
use App\Models\DeploymentHost;
use App\Models\SecretReveal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PreviewController extends Controller
{
    public function __invoke(Request $request, DeploymentHost $deploymentHost, HostFilesGenerator $generator): JsonResponse
    {
        Gate::authorize('view', $deploymentHost->deployment);

        $generated = $generator->generate($deploymentHost);
        $reveal = $request->boolean('reveal') && $request->user()->can('operate', $deploymentHost->deployment);

        if ($reveal) {
            SecretReveal::create([
                'user_id' => $request->user()->id,
                'variable_id' => null,
                'key' => 'preview:'.$deploymentHost->id,
            ]);
        }

        return response()->json([
            'files' => $reveal ? $generated->files : $generated->masked(),
            'errors' => $generated->errors,
            'warnings' => $generated->warnings,
            'missingRequired' => $generated->missingRequired,
        ]);
    }
}
