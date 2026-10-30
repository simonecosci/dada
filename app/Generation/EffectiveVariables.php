<?php

namespace App\Generation;

use App\Enums\VariableScope;
use App\Models\DeploymentHost;
use App\Models\Installation;
use App\Models\Variable;

class EffectiveVariables
{
    /** @var array<string, ?string> */
    private array $dotEnv = [];

    /** @var array<int, array<string, ?string>> */
    private array $containerEnv = [];

    /** @var list<string> */
    private array $missingRequired = [];

    /** @var list<string> */
    private array $secretValues = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $errors = [];

    private function __construct() {}

    public static function for(DeploymentHost $dh): self
    {
        $dh->load(['variables', 'installations.variables']);

        $self = new self;
        $self->collect($dh);

        return $self;
    }

    /** @return array<string, ?string> */
    public function dotEnv(): array
    {
        return $this->dotEnv;
    }

    /** @return array<string, ?string> */
    public function containerEnv(Installation $i): array
    {
        return $this->containerEnv[$i->id] ?? $this->containerValues($i);
    }

    /** @return list<string> */
    public function missingRequired(): array
    {
        return $this->missingRequired;
    }

    /** @return list<string> */
    public function secretValues(): array
    {
        return $this->secretValues;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function collect(DeploymentHost $dh): void
    {
        $hostGlobal = [];
        foreach ($dh->variables as $variable) {
            if ($variable->scope !== VariableScope::HostGlobal) {
                continue;
            }
            $hostGlobal[$variable->key] = $variable->effectiveValue();
            $this->track($variable, 'host');
        }
        $this->dotEnv = $hostGlobal;

        /** @var array<string, string> $composeOwner chiave => slug della prima installazione che la definisce */
        $composeOwner = [];
        $conflicts = [];

        foreach ($dh->installations as $installation) {
            $this->containerEnv[$installation->id] = [];

            foreach ($installation->variables as $variable) {
                $this->track($variable, $installation->slug, ! ($variable->scope === VariableScope::Compose && array_key_exists($variable->key, $hostGlobal)));
                $value = $variable->effectiveValue();

                if ($variable->scope === VariableScope::Container) {
                    $this->containerEnv[$installation->id][$variable->key] = $value;
                } elseif ($variable->scope === VariableScope::Compose) {
                    $this->addCompose($variable->key, $value, $installation->slug, $hostGlobal, $composeOwner, $conflicts);
                }
            }
        }
    }

    /**
     * @param  array<string, ?string>  $hostGlobal
     * @param  array<string, string>  $composeOwner
     * @param  array<string, true>  $conflicts
     */
    private function addCompose(string $key, ?string $value, string $slug, array $hostGlobal, array &$composeOwner, array &$conflicts): void
    {
        if (array_key_exists($key, $hostGlobal)) {
            $this->warnings[] = "{$key}: definita sia tra le variabili dell'host sia in {$slug}, vale quella dell'host";

            return;
        }

        if (! array_key_exists($key, $composeOwner)) {
            $composeOwner[$key] = $slug;
            $this->dotEnv[$key] = $value;

            return;
        }

        if (($this->dotEnv[$key] ?? '') !== ($value ?? '') && ! isset($conflicts[$key.'|'.$slug])) {
            $conflicts[$key.'|'.$slug] = true;
            $this->errors[] = "{$key}: valori diversi tra {$composeOwner[$key]} e {$slug}, spostala tra le variabili dell'host";
        }
    }

    private function track(Variable $variable, string $owner, bool $checkRequired = true): void
    {
        $value = $variable->effectiveValue();

        if ($checkRequired && $variable->required && ($value === null || $value === '')) {
            $this->missingRequired[] = "{$owner}: {$variable->key}";
        }

        if ($variable->secret && $value !== null && $value !== '') {
            $this->secretValues[] = $value;
        }
    }

    /** @return array<string, ?string> */
    private function containerValues(Installation $i): array
    {
        $env = [];
        foreach ($i->variables as $variable) {
            if ($variable->scope === VariableScope::Container) {
                $env[$variable->key] = $variable->effectiveValue();
            }
        }

        return $env;
    }
}
