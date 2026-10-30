<?php

namespace App\Http\Requests\Deployments;

use App\Models\Deployment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Deploy of a deployment (web UI and API): Operator or Admin only. */
class DeployRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('operate', $this->deployment());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Limits the deploy to one host of the deployment; all hosts when omitted.
            'deployment_host_id' => [
                'nullable', 'integer',
                Rule::exists('deployment_hosts', 'id')->where('deployment_id', $this->deployment()->id),
            ],
            // Pull the images before starting (default true).
            'pull' => ['sometimes', 'boolean'],
            // Restart the services after the update (default true).
            'restart' => ['sometimes', 'boolean'],
            // Confirms the removal of installations no longer configured (default false).
            'confirm_removals' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{deployment_host_id: int|null, pull: bool, restart: bool, confirm_removals: bool} */
    public function options(): array
    {
        $hostId = $this->validated('deployment_host_id');

        return [
            'deployment_host_id' => $hostId !== null ? (int) $hostId : null,
            'pull' => $this->boolean('pull', true),
            'restart' => $this->boolean('restart', true),
            'confirm_removals' => $this->boolean('confirm_removals'),
        ];
    }

    private function deployment(): Deployment
    {
        /** @var Deployment */
        return $this->route('deployment');
    }
}
