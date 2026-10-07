<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * Writes the structure manifest of the code as it stands (structure.md): one
 * `.kit/structure/{Context}.json` per context and one `.kit/structure/http/{Resource}.json` per
 * HTTP resource. A manifest that exists is kept, because it may hold design that is not built
 * yet, unless `--force` asks to read it back from the code.
 */
#[Signature('kit:import {--context=* : Only these contexts, by name} {--resource=* : Only these HTTP resources, by name} {--force : Overwrite a manifest that already exists}')]
#[Description('Write the structure manifest of each context and HTTP resource from the code, as structure.md describes')]
class KitImportCommand extends Command
{
    public function handle(): int
    {
        $reader = new StructureReader(base_path());
        $files = new StructureFiles(base_path());

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
            $this->writeOne($files->exists($context), $files->relativePath($context), fn () => $files->write($reader->read($context)));
        }

        foreach ($everything ? $reader->resources() : $resources as $resource) {
            $this->writeOne($files->resourceExists($resource), $files->relativeResourcePath($resource), fn () => $files->writeResource($reader->readResource($resource)));
        }

        return self::SUCCESS;
    }

    private function writeOne(bool $exists, string $path, callable $write): void
    {
        if ($exists && ! $this->option('force')) {
            $this->components->warn(sprintf('Manifest [%s] already exists, kept as it is. Pass --force to read it back from the code.', $path));

            return;
        }

        $write();
        $this->components->info(sprintf('Manifest [%s] written.', $path));
    }
}
