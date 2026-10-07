<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Closure;
use Composer\Autoload\ClassLoader;

/**
 * What `kit:plan` and `kit:apply` share (structure.md): the planner and comparer over the project,
 * the `--context` and `--resource` filters, and how a plan is printed.
 *
 * The markers are read from the container when a test binds them to a scratch root, so a run
 * never writes into the project's own provider and route files from a test.
 *
 * @phpstan-import-type Step from StructurePlanner
 */
trait PlansStructure
{
    protected function markers(): StructureMarkers
    {
        return app()->bound(StructureMarkers::class) ? app(StructureMarkers::class) : new StructureMarkers(base_path());
    }

    /**
     * Composer's loader remembers each class it once failed to find, so a class a generator has
     * just written stays missing for the rest of the run. Forgetting that lets the next plan, and
     * the next generator, find it.
     */
    protected function forgetMissingClasses(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            Closure::bind(fn () => $this->missingClasses = [], $loader, ClassLoader::class)();
        }
    }

    protected function planner(): StructurePlanner
    {
        $this->forgetMissingClasses();

        return new StructurePlanner(new StructureReader(base_path()), new StructureFiles(base_path()), $this->markers());
    }

    /**
     * @return list<string>
     */
    protected function contextFilter(): array
    {
        /** @var list<string> $contexts */
        $contexts = $this->option('context');

        return $contexts;
    }

    /**
     * @return list<string>
     */
    protected function resourceFilter(): array
    {
        /** @var list<string> $resources */
        $resources = $this->option('resource');

        return $resources;
    }

    /**
     * Whether every name the filters give has a manifest, after saying which do not.
     */
    protected function filtersAreKnown(): bool
    {
        $files = new StructureFiles(base_path());
        $unknown = [
            ...array_diff($this->contextFilter(), $files->contexts()),
            ...array_diff($this->resourceFilter(), $files->resources()),
        ];

        if ($unknown !== []) {
            $this->components->error(sprintf('No manifest names the context or HTTP resource %s.', implode(', ', $unknown)));

            return false;
        }

        return true;
    }

    /**
     * @return list<Step>
     */
    protected function planSteps(): array
    {
        return $this->planner()->steps($this->contextFilter(), $this->resourceFilter());
    }

    /**
     * Prints the steps still to run and what is left to do by hand.
     *
     * The comparer's `in-code` differences name the pieces the steps are about to build, so they
     * are printed only once no step is left. What is left then is what no generator writes.
     *
     * @param  list<Step>  $steps
     * @param  bool  $compare  false once a run has moved or changed classes, whose old shape this process still holds
     */
    protected function printPlan(array $steps, bool $compare = true): void
    {
        $ready = array_values(array_filter($steps, fn (array $step): bool => $step['state'] === StructurePlanner::READY));
        $waiting = array_values(array_filter($steps, fn (array $step): bool => $step['state'] === StructurePlanner::WAITING));
        $done = count($steps) - count($ready) - count($waiting);

        if ($ready !== []) {
            $this->components->info(sprintf('Ready to run (%d):', count($ready)));

            foreach ($ready as $step) {
                $this->components->twoColumnDetail($step['title'], StructurePlanner::describe($step));
            }
        }

        if ($waiting !== []) {
            $this->components->info(sprintf('Waiting (%d):', count($waiting)));

            foreach ($waiting as $step) {
                $this->components->twoColumnDetail($step['title'], (string) $step['reason']);
            }
        }

        $this->components->info(sprintf('Done: %d of %d steps.', $done, count($steps)));

        if (! $compare) {
            $this->components->info('Classes moved or changed during this run. Run `php artisan kit:plan` to compare the manifest with the code as it now stands.');

            return;
        }

        $differences = $this->differences();
        $left = $ready === [] && $waiting === []
            ? $differences
            : array_values(array_filter($differences, fn (array $difference): bool => $difference['check'] !== 'in-code'));

        if ($left !== []) {
            $this->components->warn(sprintf('By hand (%d), where the code still differs from the manifest:', count($left)));

            foreach ($left as $difference) {
                $this->line(sprintf('  [structure:%s] %s: %s', $difference['check'], $difference['subject'], $difference['message']));
            }
        }
    }

    /**
     * The comparer's differences, narrowed to the names the filters give.
     *
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function differences(): array
    {
        $differences = (new StructureComparer(
            new StructureReader(base_path()),
            new StructureFiles(base_path()),
            fn (string $name): bool => false,
        ))->differences();
        $names = [...$this->contextFilter(), ...$this->resourceFilter()];

        if ($names === []) {
            return $differences;
        }

        return array_values(array_filter($differences, function (array $difference) use ($names): bool {
            foreach ($names as $name) {
                if (preg_match('/(^|[\\\\\/.])'.preg_quote($name, '/').'([\\\\\/.]|Controller|$)/', $difference['subject']) === 1) {
                    return true;
                }
            }

            return false;
        }));
    }
}
