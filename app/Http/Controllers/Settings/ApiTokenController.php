<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    /**
     * Show the user's API tokens.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => $request->user()->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Create a token: the plaintext value is flashed once and never shown again.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ability' => ['required', Rule::enum(TokenAbility::class)],
        ]);

        // A deploy token can also read.
        $abilities = TokenAbility::from($data['ability']) === TokenAbility::Deploy
            ? [TokenAbility::Read->value, TokenAbility::Deploy->value]
            : [TokenAbility::Read->value];

        $token = $request->user()->createToken($data['name'], $abilities);

        Inertia::flash('token', ['name' => $data['name'], 'plain_text' => $token->plainTextToken]);

        return to_route('api-tokens.edit');
    }

    /**
     * Revoke one of the user's tokens.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        // Scoped to the user's tokens: someone else's token is a 404.
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Token revoked.']);

        return to_route('api-tokens.edit');
    }
}
