<?php

namespace App\Deployments;

use App\Models\DeployRun;

final readonly class RunStartResult
{
    public const BUSY = 'A run is already in progress';

    public const DISPATCH_FAILED = 'Unable to queue the run: try again later.';

    /**
     * @param  list<array{deployment_host_id: int, slug: string, services: list<string>}>  $removals
     */
    private function __construct(
        public RunStartOutcome $outcome,
        public ?DeployRun $run = null,
        public array $removals = [],
    ) {}

    public static function started(DeployRun $run): self
    {
        return new self(RunStartOutcome::Started, $run);
    }

    public static function busy(): self
    {
        return new self(RunStartOutcome::Busy);
    }

    /** @param  list<array{deployment_host_id: int, slug: string, services: list<string>}>  $removals */
    public static function removalsPending(array $removals): self
    {
        return new self(RunStartOutcome::RemovalsPending, removals: $removals);
    }

    public static function dispatchFailed(DeployRun $run): self
    {
        return new self(RunStartOutcome::DispatchFailed, $run);
    }
}
