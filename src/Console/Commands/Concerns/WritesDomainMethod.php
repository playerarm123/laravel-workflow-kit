<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * What `make:entity-method` and `make:value-object-method` share: reading `--param` and `--throws`
 * the way the structure manifest writes them (structure.md), and giving the class's Unit test a
 * `describe()` for the new method.
 *
 * @property Filesystem $files
 */
trait WritesDomainMethod
{
    /**
     * Each `--param` as the code it declares, or what is wrong with one.
     *
     * @param  array<string, string>  $imports
     * @return list<string>|string
     */
    private function parameters(string $context, ?string $aggregate, string $namespace, array &$imports): array|string
    {
        $parameters = [];
        $names = [];

        foreach ((array) $this->option('param') as $param) {
            [$name, $type] = array_map(trim(...), explode(':', (string) $param, 2) + [1 => '']);

            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1 || $type === '') {
                return "The parameter {$param} is not name:Type with a camelCase name.";
            }

            if (in_array($name, $names, true)) {
                return "The parameter {$name} is given twice.";
            }

            $variadic = str_starts_with($type, '...');
            $resolved = $this->manifestTypeCode($variadic ? substr($type, 3) : $type, $context, $aggregate, $namespace, $imports, "the parameter {$name}");

            if (is_string($resolved)) {
                return $resolved;
            }

            $names[] = $name;
            $parameters[] = $resolved['code'].' '.($variadic ? '...' : '').'$'.$name;
        }

        return $parameters;
    }

    /**
     * Each `--throws` by the name the code uses, or what is wrong with one.
     *
     * @param  array<string, string>  $imports
     * @return array<string, string>|string
     */
    private function exceptions(string $context, ?string $aggregate, string $namespace, array &$imports): array|string
    {
        $reader = new StructureReader(base_path());
        $exceptions = [];

        foreach ((array) $this->option('throws') as $throws) {
            $class = $reader->exceptionClass((string) $throws, $context, $aggregate);

            if ($class === null) {
                return "The exception {$throws} is not built yet — build it first.";
            }

            $alias = class_basename($class);

            if (isset($imports[$alias]) && $imports[$alias] !== $class) {
                return "The method names {$alias} from two places — write one of them by hand.";
            }

            if (Str::beforeLast($class, '\\') !== $namespace) {
                $imports[$alias] = $class;
            }

            $exceptions[$alias] = $class;
        }

        ksort($exceptions);

        return $exceptions;
    }

    /**
     * Adds the method's `describe()` to the class's Unit test at `$path`, at the end of its outer
     * block: a todo for the case that passes and one per exception.
     *
     * @param  list<string>  $exceptions
     */
    private function addTest(string $path, string $method, array $exceptions): void
    {
        $relative = Str::after($path, base_path().DIRECTORY_SEPARATOR);

        if (! $this->files->exists($path)) {
            $this->components->warn("Test [{$relative}] does not exist — write the cases of {$method}() by hand.");

            return;
        }

        $code = rtrim($this->files->get($path), "\n");
        $close = strrpos($code, "\n});");

        if ($close === false) {
            $this->components->warn("Test [{$relative}] has no outer describe() to add {$method}() to — write its cases by hand.");

            return;
        }

        $cases = [
            "        it('succeeds when its rules hold')->todo();",
            ...array_map(fn (string $exception): string => "        it('throws {$exception}')->todo();", $exceptions),
        ];
        $describe = "\n\n    describe('{$method}()', function () {\n".implode("\n\n", $cases)."\n    });";

        $this->files->put($path, substr($code, 0, $close).$describe.substr($code, $close)."\n");

        $this->components->info("Test [{$relative}] gained the cases of {$method}().");
    }
}
