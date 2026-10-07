<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureSwapper;

/**
 * Removes what a replacement took over (structure.md): an adapter once its port is bound to the
 * new one, a use case once the HTTP layer calls the new one, a domain service once nothing under
 * app/ names its folder.
 *
 * A replacement is retired only when nothing outside the old piece's own files names it any more,
 * and only once the Architecture, Unit and Feature suites pass with both pieces in place. Then the
 * old class and its tests go, and the manifest forgets what it replaced.
 *
 * A list use case takes more with it: its query adapter and the adapter's test, the line binding
 * its query port, its sort enum when no other list sorts by it, and the TypeScript twins of its
 * Row and Criteria, once no page or component reads them.
 *
 * @phpstan-type Replacement array{context: string, old: string, new: string, classes: list<string>, files: list<string>, bindings: array<string, string>, sort: array{0: string, 1: string}|null, types: array{file: string, names: list<string>}|null, forget: callable(array<string, mixed>): array<string, mixed>}
 */
#[Signature('kit:retire {--context=* : Only the replacements in these contexts}')]
#[Description('Remove what a finished replacement took over, once the tests pass, as structure.md describes')]
class KitRetireCommand extends Command
{
    private const array SEARCHED = ['app', 'routes', 'database', 'tests'];

    public function handle(): int
    {
        $files = new StructureFiles(base_path());
        /** @var list<string> $contexts */
        $contexts = $this->option('context');
        $swapped = $this->swappedSteps();
        $swapper = new StructureSwapper(base_path());
        $retiring = [];

        foreach ($contexts === [] ? $files->contexts() : $contexts as $context) {
            $manifest = $files->read($context);

            if (! is_array($manifest) || $files->problems($context, $manifest) !== []) {
                continue;
            }

            foreach ($this->replacements($context, $manifest, $swapped) as $replacement) {
                $users = [
                    ...$swapper->references($replacement['classes'], self::SEARCHED, $replacement['files'], $replacement['bindings']),
                    ...($replacement['types'] === null ? [] : $swapper->nameReferences($replacement['types']['names'], ['resources/js'], [$replacement['types']['file']])),
                ];

                if ($users !== []) {
                    $this->components->warn(sprintf('%s is still named in %s. Point those at %s first.', $replacement['old'], implode(', ', $users), $replacement['new']));

                    continue;
                }

                foreach ($replacement['sort'] === null ? [] : [$replacement['sort']] as [$class, $file]) {
                    if (is_file(base_path($file)) && $swapper->references([$class], self::SEARCHED, [...$replacement['files'], $file]) === []) {
                        $replacement['files'][] = $file;
                    }
                }

                $retiring[] = $replacement;
            }
        }

        if ($retiring === []) {
            $this->components->info('Nothing to retire.');

            return self::SUCCESS;
        }

        $this->components->info('Running the Architecture, Unit and Feature suites before anything is removed.');
        $run = Process::path(base_path())
            ->env($this->withoutLocalEnvironment())
            ->timeout(1800)
            ->run(['php', 'artisan', 'test', '--compact', '--parallel', '--testsuite=Architecture,Unit,Feature']);

        if (! $run->successful()) {
            $this->components->error('The tests do not pass, so nothing was removed.');
            $this->line(implode("\n", array_slice(explode("\n", trim($run->output()."\n".$run->errorOutput())), -40)));

            return self::FAILURE;
        }

        foreach ($retiring as $replacement) {
            foreach ($replacement['files'] as $path) {
                is_dir(base_path($path)) ? File::deleteDirectory(base_path($path)) : File::delete(base_path($path));
            }

            $providers = [];

            foreach ($replacement['bindings'] as $port => $adapter) {
                $providers = [...$providers, ...$swapper->unbind($port, $adapter, ['app'])];
            }

            if ($providers !== [] && is_file(base_path('vendor/bin/pint'))) {
                Process::path(base_path())->run(['vendor/bin/pint', ...$providers]);
            }

            if ($replacement['types'] !== null) {
                $swapper->removeTypes($replacement['types']['file'], $replacement['types']['names']);
            }

            $manifest = $files->read($replacement['context']);

            if (is_array($manifest)) {
                $files->write(($replacement['forget'])($manifest));
            }

            $this->components->info(sprintf('Retired %s, replaced by %s.', $replacement['old'], $replacement['new']));
        }

        return self::SUCCESS;
    }

    /**
     * Every variable .env sets, removed from the test run's environment. This command runs with
     * them loaded, and the tests' own <env> values in phpunit.xml do not override what is already
     * set, so without this the tests would run against the local environment and its database.
     *
     * @return array<string, false>
     */
    private function withoutLocalEnvironment(): array
    {
        $file = base_path('.env');
        $variables = is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];

        return array_fill_keys([...array_keys($variables), 'APP_ENV'], false);
    }

    private function planner(): StructurePlanner
    {
        $markers = app()->bound(StructureMarkers::class) ? app(StructureMarkers::class) : new StructureMarkers(base_path());

        return new StructurePlanner(new StructureReader(base_path()), new StructureFiles(base_path()), $markers);
    }

    /**
     * The keys of the swap steps the code has finished.
     *
     * @return list<string>
     */
    private function swappedSteps(): array
    {
        return array_values(array_map(
            fn (array $step): string => $step['key'],
            array_filter($this->planner()->steps(), fn (array $step): bool => $step['swap'] !== null && $step['state'] === StructurePlanner::DONE),
        ));
    }

    /**
     * Each replacement in a context whose swap is done: the old piece's classes, its files and the
     * tests that mirror them, and how the manifest forgets it. A list adds its port's binding, its
     * sort enum, which goes only when nothing else names it, and its TypeScript twins.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $swapped
     * @return list<Replacement>
     */
    private function replacements(string $context, array $manifest, array $swapped): array
    {
        $replacements = [];

        /** @var array<string, array{adapter: string|null, replaces?: string|null}> $ports */
        $ports = $manifest['ports'];

        foreach ($ports as $port => $entry) {
            $old = $entry['replaces'] ?? null;

            if (! is_string($old) || ! in_array("swap binding {$port}", $swapped, true)) {
                continue;
            }

            $replacements[] = [
                'context' => $context,
                'old' => 'App\\'.str_replace('/', '\\', $old),
                'new' => 'App\\'.str_replace('/', '\\', (string) $entry['adapter']),
                'classes' => ['App\\'.str_replace('/', '\\', $old)],
                'files' => ["app/{$old}.php", "tests/Feature/{$old}Test.php"],
                'bindings' => [],
                'sort' => null,
                'types' => null,
                'forget' => function (array $manifest) use ($port): array {
                    $manifest['ports'][$port]['replaces'] = null;

                    return $manifest;
                },
            ];
        }

        /** @var array<string, array{query: bool, replaces?: string|null}> $useCases */
        $useCases = $manifest['useCases'];
        $swapper = new StructureSwapper(base_path());

        foreach ($useCases as $useCase => $entry) {
            $old = $entry['replaces'] ?? null;

            if (! is_string($old) || ! in_array("swap {$context}/{$old}", $swapped, true)) {
                continue;
            }

            $folder = "app/Application/{$context}/UseCases/{$old}";
            $inFolder = is_dir(base_path($folder));
            $list = $entry['query'];
            $names = StructurePlanner::listNames($old);
            $adapter = "App\\Infra\\Persistence\\Eloquent\\Queries\\Eloquent{$old}Query";
            $typesFile = $list ? $swapper->declaringFile($names['row'], StructurePlanner::TYPES) : null;

            $replacements[] = [
                'context' => $context,
                'old' => "{$context}/{$old}",
                'new' => "{$context}/{$useCase}",
                'classes' => [
                    $inFolder ? "App\\Application\\{$context}\\UseCases\\{$old}\\" : "App\\Application\\{$context}\\UseCases\\{$old}Handler",
                    ...($list ? [$adapter] : []),
                ],
                'files' => [
                    ...($inFolder
                        ? [$folder, "tests/Feature/Application/{$context}/UseCases/{$old}"]
                        : ["{$folder}Handler.php", "tests/Feature/Application/{$context}/UseCases/{$old}HandlerTest.php"]),
                    ...($list ? ["app/Infra/Persistence/Eloquent/Queries/Eloquent{$old}Query.php", "tests/Feature/Infra/Persistence/Eloquent/Queries/Eloquent{$old}QueryTest.php"] : []),
                ],
                'bindings' => $list ? ["App\\Application\\{$context}\\UseCases\\{$old}\\{$old}Query" => $adapter] : [],
                'sort' => $list ? ["App\\Application\\{$context}\\{$names['sort']}", "app/Application/{$context}/{$names['sort']}.php"] : null,
                'types' => $typesFile === null ? null : ['file' => $typesFile, 'names' => [$names['row'], $names['filters'], $names['query']]],
                'forget' => function (array $manifest) use ($old, $useCase): array {
                    unset($manifest['useCases'][$old]);
                    $manifest['useCases'][$useCase]['replaces'] = null;

                    return $manifest;
                },
            ];
        }

        /** @var array<string, array{replaces?: string|null}> $services */
        $services = $manifest['services'];

        foreach ($services as $service => $entry) {
            $old = $entry['replaces'] ?? null;

            if (! is_string($old) || ! in_array("swap service {$context}/{$old}", $swapped, true)) {
                continue;
            }

            $replacements[] = [
                'context' => $context,
                'old' => "{$context}/{$old}",
                'new' => "{$context}/{$service}",
                'classes' => ["App\\Domain\\{$context}\\Services\\{$old}\\"],
                'files' => ["app/Domain/{$context}/Services/{$old}", "tests/Unit/Domain/{$context}/Services/{$old}", "tests/Feature/Domain/{$context}/Services/{$old}"],
                'bindings' => [],
                'sort' => null,
                'types' => null,
                'forget' => function (array $manifest) use ($old, $service): array {
                    unset($manifest['services'][$old]);
                    $manifest['services'][$service]['replaces'] = null;

                    return $manifest;
                },
            ];
        }

        return $replacements;
    }
}
