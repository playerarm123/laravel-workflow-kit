<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\PlansStructure;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureSwapper;

/**
 * Builds what the structure manifest lists and the code lacks (structure.md). It runs every step
 * that is ready, plans again, and repeats until no step is left to run, because one step makes the
 * next ready: an adapter makes its binding ready, a controller its route.
 *
 * A swap step points code at a replacement, then runs Pint on the PHP files it changed so their
 * imports sit in order again, and prettier on the TypeScript ones a list's swap renamed types in. A swap, or a method added to an entity, changes a class this process
 * has already loaded, so the run then leaves the comparison to `kit:plan`.
 *
 * A step that fails is not run again in the same call, so the steps that need it keep waiting.
 * A line with no marker to go above is printed for a person to place. The run ends with the plan
 * that is left, so running it again after filling in the waiting code picks up where it stopped.
 */
#[Signature('kit:apply {--context=* : Only these contexts, by name} {--resource=* : Only these HTTP resources, by name}')]
#[Description('Run the make:* generators and write the binding and route lines that build the structure manifest, as structure.md describes')]
class KitApplyCommand extends Command
{
    use PlansStructure;

    /**
     * More rounds than the longest chain of steps that wait on each other.
     */
    private const int MAX_ROUNDS = 10;

    public function handle(): int
    {
        if (! $this->filtersAreKnown()) {
            return self::FAILURE;
        }

        $markers = $this->markers();
        $tried = [];
        $failed = false;
        $routes = false;
        $reshaped = false;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $ready = array_filter(
                $this->planSteps(),
                fn (array $step): bool => $step['state'] === StructurePlanner::READY && ! isset($tried[$step['key']]),
            );

            if ($ready === []) {
                break;
            }

            foreach ($ready as $step) {
                $tried[$step['key']] = true;
                $this->components->twoColumnDetail($step['title'], StructurePlanner::describe($step));

                if ($step['swap'] !== null) {
                    $swapper = new StructureSwapper(base_path());
                    $php = $swapper->swap($step['swap']['classes'], $step['swap']['directories'], $step['swap']['except']);
                    $typescript = $step['swap']['names'] === [] ? [] : $swapper->swapNames($step['swap']['names'], $step['swap']['nameDirectories']);
                    $changed = [...$php, ...$typescript];
                    $this->tidy($php, $typescript);
                    $reshaped = $reshaped || $changed !== [];
                    $this->components->info(sprintf('Swapped in %d file(s): %s', count($changed), implode(', ', $changed)));

                    continue;
                }

                if ($step['command'] !== null) {
                    $this->forgetMissingClasses();
                    $failed = $this->call($step['command'], $step['arguments']) !== self::SUCCESS || $failed;
                    $reshaped = $reshaped || $step['command'] === 'make:entity-method';

                    continue;
                }

                $written = $markers->insert((string) $step['marker'], (string) $step['line']);

                if ($written === StructureMarkers::WRITTEN) {
                    $routes = $routes || $step['marker'] === StructureMarkers::ROUTES;
                    $this->components->info(sprintf('Wrote [%s] above %s in [%s].', $step['line'], $step['marker'], $markers->fileOf((string) $step['marker'])));
                } elseif ($written === StructureMarkers::WIDENED) {
                    $routes = true;
                    $this->components->info(sprintf('Widened the existing Route::resource() to register every method of [%s].', $step['line']));
                } elseif ($written === StructureMarkers::BY_HAND) {
                    $this->components->warn(sprintf('The existing Route::resource() holds a method back with except(), so make it register every method by hand: %s', $step['line']));
                } elseif ($written !== StructureMarkers::PRESENT) {
                    $this->components->warn(sprintf(
                        '%s %s, so place this line by hand: %s',
                        $written === StructureMarkers::NO_MARKER ? 'No file holds' : 'More than one file holds',
                        $step['marker'],
                        $step['line'],
                    ));
                }
            }
        }

        $this->printPlan($this->planSteps(), compare: ! $reshaped);

        if ($routes) {
            $this->components->info('Routes changed. Run `php artisan wayfinder:generate --with-form --no-interaction`.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $php  from the project root
     * @param  list<string>  $typescript  from the project root
     */
    private function tidy(array $php, array $typescript = []): void
    {
        if ($php !== [] && is_file(base_path('vendor/bin/pint'))) {
            Process::path(base_path())->run(['vendor/bin/pint', ...$php]);
        }

        if ($typescript !== [] && is_file(base_path('node_modules/.bin/prettier'))) {
            Process::path(base_path())->run(['node_modules/.bin/prettier', '--write', ...$typescript]);
        }
    }
}
