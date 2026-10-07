<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\PlansStructure;

/**
 * Prints what `kit:apply` would run to build the structure manifest (structure.md), and writes
 * nothing: the steps that are ready, the steps that wait for code a person fills in, and where the
 * code still differs from the manifest in ways no generator writes.
 */
#[Signature('kit:plan {--context=* : Only these contexts, by name} {--resource=* : Only these HTTP resources, by name}')]
#[Description('Show the steps that build the structure manifest, as structure.md describes, without running them')]
class KitPlanCommand extends Command
{
    use PlansStructure;

    public function handle(): int
    {
        if (! $this->filtersAreKnown()) {
            return self::FAILURE;
        }

        $this->printPlan($this->planSteps());

        return self::SUCCESS;
    }
}
