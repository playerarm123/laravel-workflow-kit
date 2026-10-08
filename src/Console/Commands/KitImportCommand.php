<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureSync;

/**
 * Writes the structure manifest of the code as it stands (structure.md): one
 * `.kit/structure/{Context}.json` per context and one `.kit/structure/http/{Resource}.json` per
 * HTTP resource. A manifest that exists is kept, because it may hold design that is not built
 * yet, unless `--force` asks to read it back from the code, or `--sync` merges the code into it:
 * every piece the code has takes the code's shape, and what only the manifest lists stays.
 */
#[Signature('kit:import
    {--context=* : Only these contexts, by name}
    {--resource=* : Only these HTTP resources, by name}
    {--force : Overwrite a manifest that already exists}
    {--sync : Merge the code into a manifest that already exists, keeping what is not built yet}
    {--prune : With --sync, also take out what the manifest lists and the code does not have}
    {--dry-run : With --sync, print the changes and write nothing}')]
#[Description('Write the structure manifest of each context and HTTP resource from the code, as structure.md describes')]
class KitImportCommand extends Command
{
    public function handle(): int
    {
        $reader = new StructureReader(base_path());
        $files = new StructureFiles(base_path());
        $sync = new StructureSync($files, $reader);

        $refusal = match (true) {
            $this->option('sync') && $this->option('force') => 'Pick one: --force reads the whole file back from the code, --sync merges the code into it.',
            ! $this->option('sync') && ($this->option('prune') || $this->option('dry-run')) => '--prune and --dry-run go with --sync.',
            default => null,
        };

        if ($refusal !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        /** @var list<string> $contexts */
        $contexts = $this->option('context');
        /** @var list<string> $resources */
        $resources = $this->option('resource');
        $everything = $contexts === [] && $resources === [];

        $unknown = [
            ...array_diff($contexts, $reader->contexts()),
            ...array_diff($resources, $reader->resources()),
        ];

        if ($unknown !== []) {
            $this->components->error(sprintf('No context or HTTP resource named %s.', implode(', ', $unknown)));

            return self::FAILURE;
        }

        foreach ($everything ? $reader->contexts() : $contexts as $context) {
            $this->writeOne(
                $files->exists($context),
                $files->relativePath($context),
                fn () => $files->write($reader->read($context)),
                function () use ($sync, $files, $context): array {
                    $synced = $sync->syncContext($context, (bool) $this->option('prune'));

                    return [...$synced, 'write' => fn () => $files->write($synced['manifest'])];
                },
            );
        }

        foreach ($everything ? $reader->resources() : $resources as $resource) {
            $this->writeOne(
                $files->resourceExists($resource),
                $files->relativeResourcePath($resource),
                fn () => $files->writeResource($reader->readResource($resource)),
                function () use ($sync, $files, $resource): array {
                    $synced = $sync->syncResource($resource, (bool) $this->option('prune'));

                    return [...$synced, 'write' => fn () => $files->writeResource($synced['manifest'])];
                },
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  callable(): void  $write
     * @param  callable(): array{changes: list<string>, kept: list<string>, write: callable(): void}  $merge
     */
    private function writeOne(bool $exists, string $path, callable $write, callable $merge): void
    {
        if ($exists && $this->option('sync')) {
            $this->syncOne($path, $merge());

            return;
        }

        if ($exists && ! $this->option('force')) {
            $this->components->warn(sprintf('Manifest [%s] already exists, kept as it is. Pass --sync to merge the code into it, or --force to read it back from the code.', $path));

            return;
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf('Manifest [%s] would be written.', $path));

            return;
        }

        $write();
        $this->components->info(sprintf('Manifest [%s] written.', $path));
    }

    /**
     * @param  array{changes: list<string>, kept: list<string>, write: callable(): void}  $merged
     */
    private function syncOne(string $path, array $merged): void
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($merged['changes'] === []) {
            $this->components->info(sprintf('Manifest [%s] unchanged.', $path));
        } else {
            if (! $dryRun) {
                ($merged['write'])();
            }

            $this->components->info(sprintf('Manifest [%s] %s: %d %s.', $path, $dryRun ? 'would be synced' : 'synced', count($merged['changes']), count($merged['changes']) === 1 ? 'change' : 'changes'));
            $this->components->bulletList($merged['changes']);
        }

        if ($merged['kept'] !== []) {
            $this->components->warn(sprintf(
                'Kept in [%s] but not in the code: designed and not built yet, or renamed in the code. Take them out with --prune, or on /kit/structure.',
                $path,
            ));
            $this->components->bulletList($merged['kept']);
        }
    }
}
