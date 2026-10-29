<?php

namespace App\Http\Controllers;

use App\Models\SecretReveal;
use App\Models\Variable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VariableRevealController extends Controller
{
    public function __invoke(Request $request, Variable $variable): JsonResponse
    {
        Gate::authorize('reveal', $variable);

        SecretReveal::create([
            'user_id' => $request->user()->id,
            'variable_id' => $variable->id,
            'key' => $variable->key,
        ]);

        return response()->json(['value' => $variable->effectiveValue()]);
    }
}
