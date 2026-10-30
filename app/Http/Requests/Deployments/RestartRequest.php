<?php

namespace App\Http\Requests\Deployments;

use App\Deployments\StartableServices;
use App\Jobs\RestartJob;
use App\Models\DeploymentHost;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Start/stop/restart on a deployment host (web UI and API): Operator or Admin only. */
class RestartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('operate', $this->deploymentHost()->deployment);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(RestartJob::ACTIONS)],
            // Limits the action to one installation of the host; the whole host when omitted.
            'installation_slug' => [
                'nullable', 'required_with:service', 'string', 'max:255', 'regex:'.RestartJob::SLUG_REGEX.'D',
                Rule::exists('installations', 'slug')->where('deployment_host_id', $this->deploymentHost()->id),
            ],
            // Limits the action to one service of the installation (init services excluded).
            'service' => ['nullable', 'string', 'max:255', 'regex:'.RestartJob::SERVICE_REGEX.'D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'installation_slug.required_with' => 'The service requires an installation.',
            'installation_slug.exists' => 'Installation not found on this host.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $service = $this->input('service');
                if ($validator->errors()->isNotEmpty() || $service === null) {
                    return;
                }

                $installation = $this->deploymentHost()->installations()->where('slug', $this->input('installation_slug'))->first();
                if (! in_array($service, StartableServices::of($installation->compose ?? []), true)) {
                    $validator->errors()->add('service', 'Service not found in this installation.');
                }
            },
        ];
    }

    /** @return array{action: string, installation_slug: string|null, service: string|null} */
    public function options(): array
    {
        return [
            'action' => $this->validated('action'),
            'installation_slug' => $this->validated('installation_slug'),
            'service' => $this->validated('service'),
        ];
    }

    private function deploymentHost(): DeploymentHost
    {
        /** @var DeploymentHost */
        return $this->route('deploymentHost');
    }
}
