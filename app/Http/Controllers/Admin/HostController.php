<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CredentialType;
use App\Http\Controllers\Controller;
use App\Infrastructure\JumpChain;
use App\Jobs\HostCheckJob;
use App\Models\Credential;
use App\Models\Host;
use App\Ssh\HostKeyFingerprint;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class HostController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Host::class);

        return Inertia::render('admin/hosts/Index', [
            'hosts' => Host::with(['credential:id,name,type', 'jumpHost:id,name'])
                ->orderBy('name')
                ->get(['id', 'name', 'hostname', 'port', 'username', 'credential_id', 'jump_host_id', 'reachable']),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Host::class);

        return Inertia::render('admin/hosts/Form', [
            'host' => null,
            ...$this->options(null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Host::class);

        Host::create($this->validated($request, new Host));

        return to_route('admin.hosts.index');
    }

    public function show(Host $host): Response
    {
        Gate::authorize('view', $host);

        $host->load(['credential:id,name,type', 'jumpHost:id,name']);

        return Inertia::render('admin/hosts/Show', [
            'host' => [
                ...$host->only(['id', 'name', 'hostname', 'port', 'username', 'sudo', 'reachable', 'facts', 'checked_at']),
                'credential' => $host->credential,
                'jump_host' => $host->jumpHost,
                'has_host_key' => filled($host->host_key),
                'host_key_fingerprints' => $this->fingerprints($host),
            ],
        ]);
    }

    public function edit(Host $host): Response
    {
        Gate::authorize('update', $host);

        return Inertia::render('admin/hosts/Form', [
            'host' => $host->only(['id', 'name', 'hostname', 'port', 'username', 'credential_id', 'jump_host_id', 'sudo']),
            ...$this->options($host),
        ]);
    }

    public function update(Request $request, Host $host): RedirectResponse
    {
        Gate::authorize('update', $host);

        $host->update($this->validated($request, $host));

        return to_route('admin.hosts.index');
    }

    public function destroy(Host $host): RedirectResponse
    {
        Gate::authorize('delete', $host);

        if (Host::where('jump_host_id', $host->id)->exists()) {
            throw ValidationException::withMessages([
                'host' => 'L\'host è usato come jump host da altri host.',
            ]);
        }

        $host->delete();

        return to_route('admin.hosts.index');
    }

    public function check(Host $host): RedirectResponse
    {
        Gate::authorize('update', $host);

        HostCheckJob::dispatch($host->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Verifica dell\'host avviata.']);

        return back();
    }

    /**
     * @return list<string>
     */
    private function fingerprints(Host $host): array
    {
        $fingerprints = [];

        foreach (preg_split('/\R/', (string) $host->host_key, flags: PREG_SPLIT_NO_EMPTY) as $line) {
            try {
                $fingerprints[] = HostKeyFingerprint::sha256($line);
            } catch (InvalidArgumentException) {
                // Riga non valida: non mostrata.
            }
        }

        return $fingerprints;
    }

    public function resetKey(Host $host): RedirectResponse
    {
        Gate::authorize('update', $host);

        $host->forceFill(['host_key' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Chiave host azzerata: verrà registrata al prossimo collegamento.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Host $host): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            'credential_id' => ['required', 'integer', 'exists:credentials,id', function (string $attribute, mixed $value, Closure $fail): void {
                if (Credential::find($value)?->type === CredentialType::Registry) {
                    $fail('Una credenziale registry non è usabile per SSH.');
                }
            }],
            'jump_host_id' => ['nullable', 'integer', 'exists:hosts,id', function (string $attribute, mixed $value, Closure $fail) use ($host): void {
                if ($error = JumpChain::validate($host, (int) $value)) {
                    $fail($error);
                }
            }],
            'sudo' => ['nullable', 'string', 'max:255'],
        ]);

        $data['sudo'] ??= '';

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(?Host $host): array
    {
        return [
            'credentials' => Credential::where('type', '!=', CredentialType::Registry->value)
                ->orderBy('name')->get(['id', 'name', 'type']),
            'jumpHosts' => Host::when($host, fn ($q) => $q->whereKeyNot($host->id))
                ->orderBy('name')->get(['id', 'name']),
        ];
    }
}
