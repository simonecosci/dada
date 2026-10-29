<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'is_admin', 'is_maintainer']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Form', ['user' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'is_admin' => ['boolean'],
            'is_maintainer' => ['boolean'],
        ]);

        User::forceCreate([
            ...$data,
            'is_admin' => (bool) ($data['is_admin'] ?? false),
            'is_maintainer' => (bool) ($data['is_maintainer'] ?? false),
            'email_verified_at' => now(),
        ]);

        return to_route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Form', [
            'user' => $user->only(['id', 'name', 'email', 'is_admin', 'is_maintainer']),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
            'is_admin' => ['boolean'],
            'is_maintainer' => ['boolean'],
        ]);

        $isAdmin = (bool) ($data['is_admin'] ?? false);

        if ($user->is($request->user()) && ! $isAdmin) {
            throw ValidationException::withMessages([
                'is_admin' => 'Non puoi rimuovere il ruolo admin a te stesso.',
            ]);
        }

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_admin' => $isAdmin,
            'is_maintainer' => (bool) ($data['is_maintainer'] ?? false),
        ]);

        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }

        $user->save();

        return to_route('admin.users.index');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'Non puoi cancellare te stesso.',
            ]);
        }

        $user->delete();

        return to_route('admin.users.index');
    }
}
