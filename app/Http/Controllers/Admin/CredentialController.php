<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CredentialType;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CredentialController extends Controller
{
    private const FIELDS = ['id', 'name', 'type', 'username', 'registry_url'];

    public function index(): Response
    {
        Gate::authorize('viewAny', Credential::class);

        return Inertia::render('admin/credentials/Index', [
            'credentials' => Credential::orderBy('name')->get(self::FIELDS),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Credential::class);

        return Inertia::render('admin/credentials/Form', [
            'credential' => null,
            'types' => $this->types(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Credential::class);

        Credential::create($this->validated($request, null));

        return to_route('admin.credentials.index');
    }

    public function edit(Credential $credential): Response
    {
        Gate::authorize('update', $credential);

        return Inertia::render('admin/credentials/Form', [
            'credential' => $credential->only(self::FIELDS),
            'types' => $this->types(),
        ]);
    }

    public function update(Request $request, Credential $credential): RedirectResponse
    {
        Gate::authorize('update', $credential);

        $data = $this->validated($request, $credential);

        if (blank($data['secret'] ?? null)) {
            unset($data['secret']);
        }

        $credential->update($data);

        return to_route('admin.credentials.index');
    }

    public function destroy(Credential $credential): RedirectResponse
    {
        Gate::authorize('delete', $credential);

        if ($credential->hosts()->exists()) {
            throw ValidationException::withMessages([
                'credential' => 'The credential is used by one or more hosts.',
            ]);
        }

        if (DB::table('deployment_host_registry')->where('credential_id', $credential->id)->exists()) {
            throw ValidationException::withMessages([
                'credential' => 'The credential is used as a registry by one or more deployment hosts.',
            ]);
        }

        $credential->delete();

        return to_route('admin.credentials.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Credential $credential): array
    {
        $isRegistry = $request->input('type') === CredentialType::Registry->value;
        $secretChanged = $credential === null || $request->input('type') !== $credential->type->value;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'username' => [$isRegistry ? 'required' : 'nullable', 'string', 'max:255'],
            'secret' => [$secretChanged ? 'required' : 'nullable', 'string', 'max:20000'],
            'passphrase' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value)) {
                    $fail('keys with a passphrase are not supported in this version');
                }
            }],
            'registry_url' => [$isRegistry ? 'required' : 'nullable', 'string', 'max:255'],
        ], [
            'secret.required' => 'The secret is required.',
        ]);

        unset($data['passphrase']);

        if (! $isRegistry) {
            $data['registry_url'] = null;
        }

        return $data;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function types(): array
    {
        return array_map(
            fn (CredentialType $type): array => ['value' => $type->value, 'label' => $type->label()],
            CredentialType::cases(),
        );
    }
}
