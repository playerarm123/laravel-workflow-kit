<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * What a generator that writes a FormRequest from a filled-in Command needs: one field per
 * constructor argument, a first guess at its rules from the argument's type, and the named
 * argument `toCommand()` hands back. Every guess is one a human tightens.
 *
 * The using command also uses WritesGeneratedFiles, whose `$warnings` collect what the guess
 * could not settle.
 */
trait BuildsRequestFields
{
    /**
     * @var list<string> the classes the file being rendered imports
     */
    protected array $imports = [];

    /**
     * @return array{argument: string, key: string, kind: string, class: string|null, nullable: bool}
     */
    protected function fieldOf(ReflectionParameter $parameter, string $command): array
    {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

        $kind = match (true) {
            in_array($name, ['string', 'int', 'float', 'bool', 'array'], true) => $name,
            is_subclass_of($name, BackedEnum::class) => 'enum',
            $name === UploadedFile::class || is_subclass_of($name, UploadedFile::class) => 'file',
            default => 'other',
        };

        if ($kind === 'other') {
            $this->warnings[] = sprintf('%s::$%s is `%s` — the rule reads it as a string and toCommand() needs it converted by hand.', class_basename($command), $parameter->getName(), $name);
        }

        if ($kind === 'array') {
            $this->warnings[] = sprintf('%s::$%s is an array — add rules for its items and type it in the form values.', class_basename($command), $parameter->getName());
        }

        if ($kind === 'file') {
            $this->warnings[] = sprintf('%s::$%s is a file — tighten its rule with File::image() or File::types() and a size from config().', class_basename($command), $parameter->getName());
        }

        return [
            'argument' => $parameter->getName(),
            'key' => Str::snake($parameter->getName()),
            'kind' => $kind,
            'class' => in_array($kind, ['enum', 'file', 'other'], true) ? $name : null,
            'nullable' => $type?->allowsNull() ?? true,
        ];
    }

    /**
     * A first guess at the rules from the argument's type.
     *
     * @param  array{argument: string, key: string, kind: string, class: string|null, nullable: bool}  $field
     * @return list<string>
     */
    protected function rulesOf(array $field): array
    {
        $presence = $field['nullable'] ? "'nullable'" : "'required'";

        if ($field['kind'] === 'enum') {
            $this->imports[] = 'Illuminate\Validation\Rule';
            $this->imports[] = (string) $field['class'];

            return [$presence, sprintf('Rule::enum(%s::class)', class_basename((string) $field['class']))];
        }

        return [$presence, ...match ($field['kind']) {
            'string', 'other' => ["'string'", "'max:255'"],
            'int' => ["'integer'"],
            'float' => ["'numeric'"],
            'bool' => ["'boolean'"],
            'array' => ["'array'"],
            default => ["'file'"],
        }];
    }

    /**
     * The named argument toCommand() hands the Command, read from the validated input.
     *
     * @param  array{argument: string, key: string, kind: string, class: string|null, nullable: bool}  $field
     */
    protected function argumentOf(array $field): string
    {
        $key = $field['key'];
        $enum = class_basename((string) $field['class']);

        if ($field['kind'] === 'enum') {
            $this->imports[] = (string) $field['class'];
        }

        $value = match ($field['kind']) {
            'int' => "\$this->integer('{$key}')",
            'float' => "\$this->float('{$key}')",
            'bool' => "\$this->boolean('{$key}')",
            'array' => "\$this->array('{$key}')",
            'enum' => $field['nullable'] ? "\$this->enum('{$key}', {$enum}::class)" : "{$enum}::from(\$this->string('{$key}')->toString())",
            'file' => "\$this->uploadedFile('{$key}')",
            default => "\$this->string('{$key}')->toString()",
        };

        if ($field['nullable'] && $field['kind'] !== 'enum' && $field['kind'] !== 'bool') {
            $guard = $field['kind'] === 'file' ? "\$this->hasFile('{$key}')" : "\$this->filled('{$key}')";
            $value = "{$guard} ? {$value} : null";
        }

        return sprintf('            %s: %s,', $field['argument'], $value);
    }
}
