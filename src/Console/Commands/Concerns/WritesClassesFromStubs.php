<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * What a generator that writes collaborator classes beside its main one needs: write each
 * from a stub, never over an existing file, and report it by its path from the project root.
 *
 * The using command extends GeneratorCommand, which holds `$files` and resolves a class to
 * its path and namespace. Do not use it beside WritesGeneratedFiles: both declare
 * `relativePath()`.
 */
trait WritesClassesFromStubs
{
    /**
     * The placeholders the command's stubs share, beyond `{{ namespace }}` and `{{ class }}`.
     *
     * @return array<string, string>
     */
    abstract protected function stubReplacements(): array;

    /**
     * Write a class from the given stub, leaving an existing file untouched.
     */
    protected function createFromStub(string $stub, string $class, string $label): void
    {
        $path = $this->getPath($class);

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('%s [%s] already exists.', $label, $class));

            return;
        }

        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}', ...array_keys($this->stubReplacements())],
            [$this->getNamespace($class), class_basename($class), ...array_values($this->stubReplacements())],
            $this->files->get(WorkflowKit::stubPath($stub)),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $this->sortImports($contents));

        $this->components->info(sprintf('%s [%s] created successfully.', $label, $this->relativePath($path)));
    }

    protected function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
