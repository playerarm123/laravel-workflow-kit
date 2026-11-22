<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionEnum;
use ReflectionNamedType;

/**
 * The mapping of an aggregate's repository (repositories.md), read from its context manifest: each
 * property of the root's `state` to the column of the root's `table` its snake_case name leads to,
 * and each child the same way to its own table, written through `syncChildren()`.
 *
 * A property maps on its own when its type fits the column plainly: a builtin, the enum the model
 * casts the column to (or a backed enum by its value), a `DateTimeImmutable` in a date-time column,
 * or one of the shared kernel's values stored as numbers.md and dates.md say (`Money` by its
 * `amount`, `CalendarDate` in a date column). A value object of the context maps only once the model
 * casts its column to it. Anything else is a problem the step waits on, and a person writes that
 * mapping by hand.
 *
 * @phpstan-type Child array{name: string, entity: string, model: string, relation: string, property: string, variable: string, foreignKey: string, parentId: string|null, toModel: list<string>, toEntity: list<string>}
 * @phpstan-type Mapping array{problems: list<string>, repository: string, model: string, entity: string, toModel: list<string>, toEntity: list<string>, children: list<Child>, imports: list<string>}
 * @phpstan-type Column array{type: string, nullable: bool, enum: string|null, required: bool}
 */
final class StructureRepositoryMapping
{
    /** The comment `make:eloquent-repository` leaves in `toModel()` until the mapping is written. */
    public const string TO_MODEL_TODO = '// map entity attributes to model columns here';

    /** The comment `make:eloquent-repository` leaves in `toEntity()` until the mapping is written. */
    public const string TO_ENTITY_TODO = '// map model columns to entity attributes here';

    /**
     * The shared kernel's values a column holds as a number or a date, with what writes the column
     * and what reads it back (numbers.md, dates.md).
     */
    private const array SHARED_VALUES = [
        'App\Domain\Shared\ValueObjects\Money' => ['integer', '->amount', 'from'],
        'App\Domain\Shared\ValueObjects\Percent' => ['integer', '->basisPoints', 'fromBasisPoints'],
        'App\Domain\Shared\ValueObjects\Duration' => ['integer', '->seconds', 'fromSeconds'],
        'App\Domain\Shared\ValueObjects\TimeOfDay' => ['integer', '->minutes', 'fromMinutes'],
        'App\Domain\Shared\ValueObjects\CalendarDate' => ['date', '', 'fromString'],
    ];

    private const array STRINGS = ['string', 'char', 'uuid', 'ulid', 'foreignUuid', 'decimal', 'unsignedDecimal', 'text', 'tinyText', 'mediumText', 'longText'];

    /**
     * The repository file of an aggregate.
     */
    public static function repositoryPath(string $root, string $aggregate): string
    {
        return "{$root}/app/Infra/Persistence/Eloquent/Repositories/Eloquent{$aggregate}Repository.php";
    }

    /**
     * Whether the repository's mapping is written: `make:eloquent-repository` leaves a todo in each
     * of `toModel()` and `toEntity()`, and the mapping, by this step or by hand, replaces both.
     */
    public static function mapped(string $code): bool
    {
        return ! str_contains($code, self::TO_MODEL_TODO) && ! str_contains($code, self::TO_ENTITY_TODO);
    }

    /**
     * Whether the context designs a table for the aggregate's root, which is what makes its
     * repository's mapping a step.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function designed(array $manifest, string $aggregate): bool
    {
        return is_array($manifest['entities'][$aggregate]['table'] ?? null)
            && ($manifest['aggregates'][$aggregate]['repository'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $manifest  the context's manifest, valid
     * @return Mapping
     */
    public static function of(string $root, string $context, string $aggregate, array $manifest, StructureReader $reader): array
    {
        /** @var array<string, array{aggregate: string, state: array<string, string>, table?: array<string, mixed>}> $entities */
        $entities = $manifest['entities'];
        /** @var list<string> $children */
        $children = $manifest['aggregates'][$aggregate]['children'] ?? [];
        $table = $entities[$aggregate]['table'] ?? [];
        $model = is_string($table['model'] ?? null) ? $table['model'] : $aggregate;
        $tableName = is_string($table['name'] ?? null) ? $table['name'] : '';
        $repository = self::repositoryPath($root, $aggregate);
        $models = $reader->tables()->models();
        $problems = [];
        $imports = [];

        if (! is_file($repository)) {
            $problems[] = "Eloquent{$aggregate}Repository is not built yet";
        }

        $childProperties = [];

        foreach ($children as $child) {
            $childProperties[Str::camel(Str::pluralStudly($child))] = $child;
        }

        $mapped = self::entity($root, $context, $aggregate, $aggregate, $entities[$aggregate]['state'] ?? [], $table, $models[$model]['casts'] ?? [], $reader, array_keys($childProperties), null, $imports);
        $problems = [...$problems, ...$mapped['problems']];

        if (! isset($models[$model])) {
            $problems[] = "{$model} is not built yet";
        }

        $written = [];

        foreach ($childProperties as $property => $child) {
            $childTable = $entities[$child]['table'] ?? null;

            if (! is_array($childTable)) {
                $problems[] = "the child {$child} has no table: design one, or write its mapping by hand";

                continue;
            }

            if (($entities[$aggregate]['state'][$property] ?? null) !== 'array') {
                $problems[] = "{$aggregate}Entity holds no {$property}: array for its {$child} children";
            }

            $childModel = is_string($childTable['model'] ?? null) ? $childTable['model'] : $child;
            $foreignKey = null;

            foreach ($childTable['foreignKeys'] ?? [] as $column => $key) {
                if (is_array($key) && ($key['table'] ?? null) === $tableName) {
                    $foreignKey ??= (string) $column;
                }
            }

            $childName = is_string($childTable['name'] ?? null) ? $childTable['name'] : $child;

            if ($foreignKey === null) {
                $problems[] = "the table {$childName} has no foreign key to {$tableName}";

                continue;
            }

            if (($childTable['softDeletes'] ?? false) === true) {
                $problems[] = "the table {$childName} soft deletes, which syncChildren() refuses (repositories.md)";
            }

            $relation = Str::camel(Str::pluralStudly($childModel));

            if (! isset($models[$childModel])) {
                $problems[] = "{$childModel} is not built yet";
            } elseif (isset($models[$model]) && ! method_exists($models[$model]['class'], $relation)) {
                $problems[] = "{$model} has no {$relation}() relation";
            }

            $variable = Str::camel($child);
            $childMapped = self::entity($root, $context, $aggregate, $child, $entities[$child]['state'] ?? [], $childTable, $models[$childModel]['casts'] ?? [], $reader, [], $foreignKey, $imports, $variable);
            $problems = [...$problems, ...$childMapped['problems']];
            $parentId = $childMapped['setsParent'] ? null : Str::camel($aggregate).'Id';
            $imports[] = "App\\Domain\\{$context}\\{$aggregate}\\Entities\\{$child}Entity";
            $imports[] = "App\\Models\\{$childModel}";

            $written[] = [
                'name' => $child,
                'entity' => "{$child}Entity",
                'model' => $childModel,
                'relation' => $relation,
                'property' => $property,
                'variable' => $variable,
                'foreignKey' => $foreignKey,
                'parentId' => $parentId,
                'toModel' => [...($parentId === null ? [] : ["\$model->{$foreignKey} = \${$parentId};"]), ...$childMapped['toModel']],
                'toEntity' => $childMapped['toEntity'],
            ];
        }

        $toEntity = $mapped['toEntity'];

        foreach ($written as $child) {
            $toEntity[] = sprintf(
                '%s: $model->%s->map(fn (%s $%s): %s => $this->to%sEntity($%s))->values()->all(),',
                $child['property'],
                $child['relation'],
                $child['model'],
                $child['variable'],
                $child['entity'],
                $child['name'],
                $child['variable'],
            );
        }

        $order = array_keys($entities[$aggregate]['state'] ?? []);
        usort($toEntity, fn (string $a, string $b): int => array_search(strstr($a, ':', true), $order, true) <=> array_search(strstr($b, ':', true), $order, true));

        return [
            'problems' => array_values(array_unique($problems)),
            'repository' => $repository,
            'model' => $model,
            'entity' => "{$aggregate}Entity",
            'toModel' => $mapped['toModel'],
            'toEntity' => $toEntity,
            'children' => $written,
            'imports' => array_values(array_unique($imports)),
        ];
    }

    /**
     * The lines that write one entity's state into its table's columns and read it back, with
     * what stops a property or a column from mapping on its own. `$skip` names the properties a
     * child table holds instead, and `$parentKey` the column that leads a child to its root.
     *
     * @param  array<string, string>  $state
     * @param  array<string, mixed>  $table
     * @param  array<string, string>  $casts
     * @param  list<string>  $skip
     * @param  list<string>  $imports
     * @return array{problems: list<string>, toModel: list<string>, toEntity: list<string>, setsParent: bool}
     */
    private static function entity(string $root, string $context, string $aggregate, string $entity, array $state, array $table, array $casts, StructureReader $reader, array $skip, ?string $parentKey, array &$imports, string $variable = 'entity'): array
    {
        $columns = self::columns($table);
        $tableName = is_string($table['name'] ?? null) ? $table['name'] : '';
        $model = is_string($table['model'] ?? null) ? $table['model'] : $entity;
        $problems = [];
        $toModel = [];
        $toEntity = [];
        $used = [];
        $file = "{$root}/app/Domain/{$context}/{$aggregate}/".($entity === $aggregate ? '' : 'Entities/')."{$entity}Entity.php";
        $code = is_file($file) ? StructureFiles::text($file) : null;
        $promoted = $code !== null && preg_match('/function __construct\((.*?)\)\s*\{/s', $code, $match) === 1 ? $match[1] : '';
        $unbuilt = [];

        if ($code === null) {
            $problems[] = "{$entity}Entity is not built yet";
        }

        foreach ($state as $property => $type) {
            $property = (string) $property;

            if ($code !== null && preg_match('/\$'.preg_quote($property, '/').'\b/', $promoted) !== 1) {
                $unbuilt[] = $property;
            }

            if (in_array($property, $skip, true)) {
                continue;
            }

            $column = Str::snake($property);

            if (! isset($columns[$column])) {
                $problems[] = "{$entity}.{$property} has no column {$column} in {$tableName}";

                continue;
            }

            $used[] = $column;
            $expressions = self::expressions($type, $columns[$column], $casts[$column] ?? null, $context, $aggregate, $reader, $imports, "cast {$column} to %s in {$model}");

            if (is_string($expressions)) {
                $problems[] = "{$entity}.{$property}: {$expressions}";

                continue;
            }

            $toModel[] = "\$model->{$column} = ".str_replace('{}', "\${$variable}->{$property}()", $expressions[0]).';';
            $toEntity[] = "{$property}: ".str_replace('{}', "\$model->{$column}", $expressions[1]).',';
        }

        if ($unbuilt !== []) {
            array_unshift($problems, "{$entity}Entity does not hold ".implode(', ', $unbuilt).' yet');
        }

        foreach ($columns as $column => $definition) {
            if ($definition['required'] && $column !== $parentKey && ! in_array($column, $used, true)) {
                $problems[] = "the column {$tableName}.{$column} maps to no state of {$entity}Entity";
            }
        }

        return ['problems' => $problems, 'toModel' => $toModel, 'toEntity' => $toEntity, 'setsParent' => $parentKey !== null && in_array($parentKey, $used, true)];
    }

    /**
     * Every column a table designs by name, the foreign keys and the timestamps among them. Only
     * the timestamps may go unmapped, because the repository's base writes them.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, Column>
     */
    private static function columns(array $table): array
    {
        $columns = [];

        foreach (is_array($table['columns'] ?? null) ? $table['columns'] : [] as $column => $definition) {
            $definition = is_array($definition) ? $definition : [];
            $columns[(string) $column] = [
                'type' => is_string($definition['type'] ?? null) ? $definition['type'] : 'string',
                'nullable' => ($definition['nullable'] ?? false) === true,
                'enum' => is_string($definition['enum'] ?? null) ? $definition['enum'] : null,
                'required' => true,
            ];
        }

        foreach (is_array($table['foreignKeys'] ?? null) ? $table['foreignKeys'] : [] as $column => $key) {
            $columns[(string) $column] = ['type' => 'foreignUuid', 'nullable' => is_array($key) && ($key['nullable'] ?? false) === true, 'enum' => null, 'required' => true];
        }

        foreach ([...(($table['timestamps'] ?? false) === true ? ['created_at', 'updated_at'] : []), ...(($table['softDeletes'] ?? false) === true ? ['deleted_at'] : [])] as $column) {
            $columns[$column] = ['type' => 'timestamp', 'nullable' => true, 'enum' => null, 'required' => false];
        }

        return $columns;
    }

    /**
     * What writes a property into its column and what reads it back, each with `{}` for the value,
     * or why the two do not map plainly. `$castIt` says how to make the model cast the column, with
     * `%s` for the class.
     *
     * @param  Column  $column
     * @param  list<string>  $imports
     * @return array{0: string, 1: string}|string
     */
    private static function expressions(string $type, array $column, ?string $cast, string $context, string $aggregate, StructureReader $reader, array &$imports, string $castIt): array|string
    {
        $nullable = str_starts_with($type, '?');
        $base = ltrim($type, '?');
        $kind = $column['type'];

        if ($nullable && ! $column['nullable']) {
            return "{$type} may be null, but the column is not nullable";
        }

        if (preg_match('/[|&]/', $base) === 1) {
            return "the union {$base} has no plain column";
        }

        if (in_array($base, StructureReader::BUILTIN_TYPES, true)) {
            $fits = match ($base) {
                'string' => in_array($kind, self::STRINGS, true),
                'int' => in_array($kind, StructureTableWriter::INTEGERS, true),
                'bool' => $kind === 'boolean',
                'float' => in_array($kind, ['float', 'double'], true),
                'array' => in_array($kind, ['json', 'jsonb'], true),
                default => false,
            };

            return $fits && $column['enum'] === null ? ['{}', '{}'] : "{$base} does not fit the {$kind} column".($column['enum'] === null ? '' : " cast to {$column['enum']}");
        }

        $class = $reader->vocabularyClass($base, $context, $aggregate);

        if ($class === null) {
            return "{$base} is not built yet";
        }

        $short = class_basename($class);
        $null = fn (string $read): string => $nullable ? "{} === null ? null : {$read}" : $read;
        $from = function (string $read) use ($class, &$imports, $null): string {
            $imports[] = $class;

            return $null($read);
        };

        if (enum_exists($class)) {
            $columnEnum = $column['enum'] === null ? null : $reader->vocabularyClass($column['enum'], $context, $aggregate);

            if ($columnEnum === $class || $cast === $class) {
                return ['{}', '{}'];
            }

            $backing = (new ReflectionEnum($class))->getBackingType();
            $backing = $backing instanceof ReflectionNamedType ? $backing->getName() : null;
            $fits = ($backing === 'string' && in_array($kind, self::STRINGS, true)) || ($backing === 'int' && in_array($kind, StructureTableWriter::INTEGERS, true));

            return $fits && $column['enum'] === null
                ? [$nullable ? '{}?->value' : '{}->value', $from("{$short}::from({})")]
                : "{$short} does not fit the {$kind} column";
        }

        // The model's @property is the Carbon its cast returns, so a moment or a day goes in as one.
        $carbon = function (string $write) use (&$imports): string {
            $imports[] = Carbon::class;

            return $write;
        };

        if (in_array($class, [DateTimeImmutable::class, DateTimeInterface::class], true)) {
            return in_array($kind, StructureTableWriter::DATETIMES, true)
                ? [$carbon($nullable ? 'Carbon::make({})' : 'Carbon::instance({})'), $nullable ? '{}?->toImmutable()' : '{}->toImmutable()']
                : "{$short} does not fit the {$kind} column";
        }

        if ($cast === $class) {
            return ['{}', '{}'];
        }

        if (isset(self::SHARED_VALUES[$class])) {
            [$stored, $write, $read] = self::SHARED_VALUES[$class];
            $fits = $stored === 'date' ? $kind === 'date' : in_array($kind, StructureTableWriter::INTEGERS, true);
            $value = $stored === 'date' ? '{}->toDateString()' : '{}';

            if ($fits && $stored === 'date') {
                return [$carbon($nullable ? 'Carbon::make({}?->toString())' : 'Carbon::parse({}->toString())'), $from("{$short}::{$read}({$value})")];
            }

            if ($fits) {
                return [($nullable ? '{}?' : '{}').$write, $from("{$short}::{$read}({$value})")];
            }
        }

        return "{$short} has no plain {$kind} column: ".sprintf($castIt, $short).', or write the mapping by hand';
    }
}
