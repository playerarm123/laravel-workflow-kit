<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * Turns a type written the way the structure manifest writes it (structure.md) into the code a
 * generated class declares it with: a builtin as PHP spells it, a class of the same aggregate by
 * its name, `Shared/Name` or `Context/Aggregate/Name`. `make:value-object` reads its fields and
 * `make:entity-method` its parameters through it.
 */
trait ResolvesManifestTypes
{
    /**
     * The code for one type and the classes it names, or why it cannot be written. Every class
     * outside `$namespace` is added to `$imports`, by the name the code uses.
     *
     * @param  array<string, string>  $imports
     * @return array{code: string, classes: list<string>}|string
     */
    protected function manifestTypeCode(string $type, string $context, ?string $aggregate, string $namespace, array &$imports, string $subject): array|string
    {
        $reader = new StructureReader(base_path());
        $nullable = str_starts_with($type, '?');
        $separator = str_contains($type, '&') ? '&' : '|';
        $code = [];
        $classes = [];

        foreach (explode($separator, ltrim($type, '?')) as $part) {
            if (in_array($part, StructureReader::BUILTIN_TYPES, true)) {
                $code[] = $part;

                continue;
            }

            $class = $reader->vocabularyClass($part, $context, $context === StructureFiles::SHARED ? null : $aggregate);

            if ($class === null) {
                return "The type {$part} of {$subject} names no class yet — build it first.";
            }

            $alias = class_basename($class);

            if (isset($imports[$alias]) && $imports[$alias] !== $class) {
                return ucfirst($subject)." names {$alias} from two places — write one of them by hand.";
            }

            if (Str::beforeLast($class, '\\') !== $namespace) {
                $imports[$alias] = $class;
            }

            $code[] = $alias;
            $classes[] = $class;
        }

        return ['code' => ($nullable ? '?' : '').implode($separator, $code), 'classes' => $classes];
    }
}
