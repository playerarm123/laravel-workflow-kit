<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use BackedEnum;
use Illuminate\Support\Str;
use ReflectionEnum;
use ReflectionNamedType;
use UnitEnum;

/**
 * The rules a table the structure screen designs keeps before anything is written, read from
 * migrations.md and models.md: the column types the kit writes, an enum column long enough for
 * every case, a reason for each nullable column, and a foreign key that names its parent table, the
 * delete behaviour chosen and an index it leads. A built table keeps what it is built with.
 *
 * It also proposes an entity's table from its `state` (structure.md, "Tables"): each property as
 * the column its snake_case name leads to, typed the way migrations.md stores it, a reference to
 * another aggregate's id as a foreign key whose delete behaviour is still to choose, and a child's
 * foreign key to its root, which cascades.
 *
 * @phpstan-type Errors array<string, list<string>>
 * @phpstan-type Proposal array{table: array<string, mixed>, unmapped: array<string, string>}
 */
final class StructureTableDesign
{
    /** The Blueprint methods a designed column may be built with. */
    public const array COLUMN_TYPES = [
        'string', 'char', 'text', 'tinyText', 'mediumText', 'longText',
        'integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'mediumInteger',
        'unsignedInteger', 'unsignedBigInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'unsignedMediumInteger',
        'boolean', 'decimal', 'date', 'time', 'timeTz', 'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz', 'year',
        'jsonb', 'uuid', 'ulid', 'binary', 'ipAddress', 'macAddress',
    ];

    /** Why a Blueprint method the kit refuses is refused (migrations.md). */
    private const array REFUSED_TYPES = [
        'float' => 'A float cannot hold money or a rate exactly: store it as an integer in minor units, or a scaled integer.',
        'double' => 'A double cannot hold money or a rate exactly: store it as an integer in minor units, or a scaled integer.',
        'enum' => 'Store a backed enum\'s value in a string with a length that fits every case, and name the enum it casts to.',
        'set' => 'Store a backed enum\'s value in a string with a length that fits every case, and name the enum it casts to.',
        'json' => 'Store json in jsonb, which Laravel writes as json on mysql and mariadb.',
        'id' => 'Every table is keyed on its uuid id already.',
        'foreignId' => 'A foreign key goes under the foreign keys, as a foreignUuid.',
        'foreignUuid' => 'A foreign key goes under the foreign keys.',
        'foreignUlid' => 'A foreign key goes under the foreign keys, as a foreignUuid.',
    ];

    /** The shared kernel's values, with the column migrations.md stores each in and the unit it names. */
    private const array SHARED_COLUMNS = [
        'Shared/Money' => ['type' => 'unsignedBigInteger', 'comment' => 'in minor units'],
        'Shared/Percent' => ['type' => 'unsignedInteger', 'comment' => 'in basis points'],
        'Shared/Duration' => ['type' => 'unsignedInteger', 'comment' => 'in seconds'],
        'Shared/TimeOfDay' => ['type' => 'unsignedSmallInteger', 'comment' => 'in minutes after midnight'],
        'Shared/CalendarDate' => ['type' => 'date'],
    ];

    private const string SNAKE = '/^[a-z][a-z0-9_]*$/';

    /** A comment the screen proposes for a nullable column, which the editor refuses until it says what null means. */
    public const string NULL_TODO = 'null means …';

    public function __construct(
        private readonly StructureFiles $files,
        private readonly StructureReader $reader,
    ) {}

    /**
     * What is wrong with a table design, by field: `name`, `model`, `columns.{name}`,
     * `foreignKeys.{name}`, `indexes`, `timestamps` and `softDeletes`. `$previous` names the design
     * it replaces, which a built table keeps.
     *
     * @param  array<string, mixed>  $manifest  the context's manifest
     * @param  array<string, mixed>  $table  the design, with its `name`
     * @return Errors
     */
    public function errors(string $context, array $manifest, ?string $entity, ?string $previous, array $table): array
    {
        $errors = [];
        $name = is_string($table['name'] ?? null) ? $table['name'] : '';
        $aggregate = $entity === null ? null : $this->holderOf($manifest, $entity);
        $designed = $this->files->designedTables();
        $tables = $this->reader->tables();
        $schema = $tables->schema();

        if (preg_match(self::SNAKE, $name) !== 1) {
            $errors['name'][] = 'A table name is snake_case.';
        } elseif ($name !== $previous && isset($designed[$name])) {
            $errors['name'][] = "A manifest already designs the table {$name}: a table belongs to one place.";
        } elseif ($name !== $previous && (isset($schema[$name]) || in_array($name, $tables->frameworkTables(), true))) {
            $errors['name'][] = "The migrations already build {$name}. Run `php artisan kit:import --context={$context} --sync` to read it into the manifest.";
        }

        $model = $table['model'] ?? null;

        if ($entity !== null && ! is_string($model)) {
            $errors['model'][] = "{$entity}'s table names the model its repository reads through.";
        } elseif ($model !== null && (! is_string($model) || preg_match('/^[A-Z][A-Za-z0-9]*$/', $model) !== 1)) {
            $errors['model'][] = 'A model is a StudlyCase class of app/Models.';
        }

        /** @var array<string, array<string, mixed>> $columns */
        $columns = is_array($table['columns'] ?? null) ? array_filter($table['columns'], is_array(...)) : [];
        /** @var array<string, array<string, mixed>> $foreignKeys */
        $foreignKeys = is_array($table['foreignKeys'] ?? null) ? array_filter($table['foreignKeys'], is_array(...)) : [];
        /** @var list<array<string, mixed>> $indexes */
        $indexes = is_array($table['indexes'] ?? null) ? array_values(array_filter($table['indexes'], is_array(...))) : [];

        foreach ($columns as $column => $definition) {
            foreach ($this->columnErrors($context, $manifest, $aggregate, $definition) as $error) {
                $errors["columns.{$column}"][] = $error;
            }
        }

        $leads = [];

        foreach ($indexes as $index) {
            $first = is_array($index['columns'] ?? null) ? ($index['columns'][0] ?? null) : null;

            if (is_string($first)) {
                $leads[] = $first;
            }
        }

        foreach ($foreignKeys as $column => $key) {
            $column = (string) $column;
            $parent = is_string($key['table'] ?? null) ? $key['table'] : '';

            if (! str_ends_with($column, '_id')) {
                $errors["foreignKeys.{$column}"][] = 'A foreign key column is named {x}_id.';
            }

            if ($parent !== $name && ! isset($designed[$parent]) && ! isset($schema[$parent]) && ! in_array($parent, $tables->frameworkTables(), true)) {
                $errors["foreignKeys.{$column}"][] = "No manifest designs the table {$parent}, and no migration builds it.";
            }

            if (! is_string($key['onDelete'] ?? null)) {
                $errors["foreignKeys.{$column}"][] = 'Choose what a delete of the parent does: cascade for rows it owns, restrict for a reference to another aggregate, null for an optional one.';
            } elseif ($key['onDelete'] === 'null' && ($key['nullable'] ?? false) !== true) {
                $errors["foreignKeys.{$column}"][] = 'A foreign key set to null on delete is nullable.';
            }

            if (($key['index'] ?? false) !== true && ($key['unique'] ?? false) !== true && ! in_array($column, $leads, true)) {
                $errors["foreignKeys.{$column}"][] = 'Give the foreign key an index it leads: Postgres never indexes one by itself.';
            }

            if (($key['nullable'] ?? false) === true && ! $this->says($key['comment'] ?? null)) {
                $errors["foreignKeys.{$column}"][] = 'Say what null means in its comment.';
            }
        }

        if ($previous !== null && isset($schema[$previous])) {
            $errors = $this->merge($errors, $this->lockErrors($context, $manifest, $entity, $previous, $table, $schema[$previous]));
        }

        return $errors;
    }

    /**
     * What a design changes of a table the migrations already build: its name, its model, a
     * column, a foreign key or an index it is built with, and timestamps or soft deletes once
     * there. Each of those is a decision about the rows stored, which a person writes as a
     * migration. What the design adds, the migration step adds.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $table
     * @param  array<string, mixed>  $built
     * @return Errors
     */
    private function lockErrors(string $context, array $manifest, ?string $entity, string $previous, array $table, array $built): array
    {
        $errors = [];
        $before = $entity === null ? ($manifest[StructureFiles::TABLES][$previous] ?? []) : ($manifest['entities'][$entity]['table'] ?? []);
        $before = is_array($before) ? $before : [];

        if (($table['name'] ?? null) !== $previous) {
            $errors['name'][] = "The migrations build {$previous}, so it keeps its name.";
        }

        if (($table['model'] ?? null) !== ($before['model'] ?? null) && $this->reader->tables()->modelOf($previous) !== null) {
            $errors['model'][] = "A model maps to {$previous} already, so the table keeps it.";
        }

        foreach (['columns', 'foreignKeys'] as $section) {
            $builtEntries = is_array($built[$section] ?? null) ? $built[$section] : [];
            $entries = is_array($table[$section] ?? null) ? $table[$section] : [];
            $was = is_array($before[$section] ?? null) ? $before[$section] : [];

            foreach (array_keys($builtEntries) as $column) {
                if (($entries[$column] ?? null) !== ($was[$column] ?? null)) {
                    $errors["{$section}.{$column}"][] = "The migrations build {$previous}.{$column}, so the screen leaves it as it is. Change it with a migration of your own.";
                }
            }
        }

        $wasIndexes = is_array($before['indexes'] ?? null) ? $before['indexes'] : [];
        $indexes = is_array($table['indexes'] ?? null) ? $table['indexes'] : [];

        foreach (is_array($built['indexes'] ?? null) ? $built['indexes'] : [] as $index) {
            if (in_array($index, $wasIndexes, true) && ! in_array($index, $indexes, true)) {
                $errors['indexes'][] = sprintf('The migrations build the index on %s, so it stays.', implode(', ', is_array($index['columns'] ?? null) ? $index['columns'] : []));
            }
        }

        foreach (['timestamps', 'softDeletes'] as $flag) {
            if (($built[$flag] ?? false) === true && ($table[$flag] ?? false) !== true) {
                $errors[$flag][] = "The migrations build {$previous} with {$flag}, so it keeps them.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $column
     * @return list<string>
     */
    private function columnErrors(string $context, array $manifest, ?string $aggregate, array $column): array
    {
        $type = is_string($column['type'] ?? null) ? $column['type'] : '';
        $errors = [];

        if (isset(self::REFUSED_TYPES[$type])) {
            return [self::REFUSED_TYPES[$type]];
        }

        if (! in_array($type, self::COLUMN_TYPES, true)) {
            return ["{$type} is not a column type the kit writes."];
        }

        if ($type === 'decimal' && (! is_int($column['precision'] ?? null) || ! is_int($column['scale'] ?? null))) {
            $errors[] = 'A decimal names its precision and scale.';
        }

        if (($column['nullable'] ?? false) === true && ! $this->says($column['comment'] ?? null)) {
            $errors[] = 'Make a column nullable only when null means something, and say what in its comment.';
        }

        $enum = is_string($column['enum'] ?? null) ? $column['enum'] : null;

        if ($enum === null) {
            return $errors;
        }

        $cases = $this->enumCases($context, $manifest, $aggregate, $enum);
        $integer = in_array($type, StructureTableWriter::INTEGERS, true);

        if ($cases === null) {
            $errors[] = "{$enum} is no enum a manifest designs or the code has.";
        } elseif ($cases['backing'] === null) {
            $errors[] = "{$enum} is a pure enum, which has no value to store.";
        } elseif ($cases['backing'] === 'int' ? ! $integer : ! in_array($type, ['string', 'char'], true)) {
            $errors[] = "{$enum} is backed by {$cases['backing']}, so its column is ".($cases['backing'] === 'int' ? 'an integer.' : 'a string.');
        } elseif ($cases['backing'] === 'string') {
            $longest = max([0, ...array_map(fn (string|int $value): int => mb_strlen((string) $value), $cases['values'])]);
            $length = is_int($column['length'] ?? null) ? $column['length'] : 255;

            if ($length < $longest) {
                $errors[] = "A length of {$length} is too short for every case of {$enum}: the longest is {$longest}.";
            }
        }

        return $errors;
    }

    /**
     * The table an entity's state proposes, and each property it leaves out, with why.
     *
     * @param  array<string, mixed>  $manifest
     * @return Proposal
     */
    public function proposed(string $context, array $manifest, string $entity): array
    {
        $aggregate = (string) $this->holderOf($manifest, $entity);
        /** @var array<string, string> $state */
        $state = $manifest['entities'][$entity]['state'] ?? [];
        /** @var list<string> $children */
        $children = $manifest['aggregates'][$aggregate]['children'] ?? [];
        $childLists = array_map(fn (string $child): string => Str::camel(Str::pluralStudly($child)), $children);
        $columns = [];
        $foreignKeys = [];
        $unmapped = [];

        if ($entity !== $aggregate) {
            $foreignKeys[Str::snake($aggregate).'_id'] = ['table' => $this->tableOf($context, $manifest, $aggregate), 'onDelete' => 'cascade', 'index' => true];
        }

        foreach ($state as $property => $type) {
            $property = (string) $property;
            $column = Str::snake($property);
            $nullable = str_starts_with($type, '?');
            $base = ltrim($type, '?');
            $flags = $nullable ? ['nullable' => true, 'comment' => self::NULL_TODO] : [];

            if ($entity === $aggregate && $base === 'array' && in_array($property, $childLists, true)) {
                continue;
            }

            $reference = preg_match('/^([a-z][A-Za-z0-9]*)Id$/', $property, $match) === 1 && $base === 'string' ? $this->aggregateTable($context, $manifest, Str::studly($match[1])) : null;

            if ($reference !== null) {
                $foreignKeys[$column] = ['table' => $reference, 'index' => true, ...$flags];

                continue;
            }

            $proposed = $this->proposedColumn($context, $manifest, $aggregate, $base);

            if (is_string($proposed)) {
                $unmapped[$property] = $proposed;

                continue;
            }

            $columns[$column] = [...$proposed, ...$flags, ...(isset($proposed['comment']) && $nullable ? ['comment' => self::NULL_TODO.', '.$proposed['comment']] : [])];
        }

        return [
            'table' => [
                'name' => $this->tableOf($context, $manifest, $entity),
                'model' => $entity,
                'columns' => $columns,
                'foreignKeys' => $foreignKeys,
                'indexes' => [],
                'timestamps' => true,
                'softDeletes' => false,
            ],
            'unmapped' => $unmapped,
        ];
    }

    /**
     * The column a property's type is stored in, or why none plainly is.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|string
     */
    private function proposedColumn(string $context, array $manifest, string $aggregate, string $type): array|string
    {
        if (preg_match('/[|&]/', $type) === 1) {
            return 'a union is stored by hand';
        }

        $builtin = match ($type) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'jsonb'],
            'DateTimeImmutable', 'DateTimeInterface' => ['type' => 'timestamp'],
            default => null,
        };

        if ($builtin !== null) {
            return $builtin;
        }

        if ($type === 'float') {
            return 'a float cannot be stored exactly: hold it as Money, a Percent or a scaled integer';
        }

        if (isset(self::SHARED_COLUMNS[$type])) {
            return self::SHARED_COLUMNS[$type];
        }

        $cases = $this->enumCases($context, $manifest, $aggregate, $type);

        if ($cases !== null) {
            if ($cases['backing'] === null) {
                return 'a pure enum has no value to store';
            }

            if ($cases['backing'] === 'int') {
                return ['type' => 'unsignedSmallInteger', 'enum' => $type];
            }

            $longest = max([1, ...array_map(fn (string|int $value): int => mb_strlen((string) $value), $cases['values'])]);

            return ['type' => 'string', 'length' => max(20, (int) ceil($longest / 10) * 10), 'enum' => $type];
        }

        $segments = explode('/', $type);
        $name = (string) array_pop($segments);
        $valueObject = count($segments) === 0 && is_array($manifest['valueObjects'][$name] ?? null);

        return $valueObject || ($this->reader->vocabularyClass($type, $context, $aggregate) !== null && ! str_ends_with($name, 'Entity'))
            ? ['type' => 'jsonb']
            : "{$type} has no column of its own";
    }

    /**
     * The values of an enum a column names, written as a state's type is, from the manifest that
     * designs it or else the code, or null when none is.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{backing: string|null, values: list<string|int>}|null
     */
    private function enumCases(string $context, array $manifest, ?string $aggregate, string $label): ?array
    {
        $segments = explode('/', $label);
        $name = (string) array_pop($segments);

        [$owner, $holder] = match (count($segments)) {
            0 => [$context, $aggregate],
            1 => $segments[0] === StructureFiles::SHARED ? [StructureFiles::SHARED, null] : [null, null],
            2 => [$segments[0], $segments[1]],
            default => [null, null],
        };

        $source = $owner === $context ? $manifest : ($owner === null || ! $this->files->exists($owner) ? null : $this->files->read($owner));
        $entry = is_array($source) ? ($source['enums'][$name] ?? null) : null;

        if (is_array($entry) && is_array($entry['cases'] ?? null) && (($entry['aggregate'] ?? null) === $holder || ($holder === null && $aggregate === null))) {
            $backing = is_string($entry['backing'] ?? null) ? $entry['backing'] : null;

            return ['backing' => $backing, 'values' => array_map(fn (mixed $value, string|int $case): string|int => is_int($value) || is_string($value) ? $value : (string) $case, $entry['cases'], array_keys($entry['cases']))];
        }

        $class = $owner === null ? null : $this->reader->vocabularyClass($label, $context, $aggregate);

        if ($class === null || ! enum_exists($class)) {
            return null;
        }

        $backing = (new ReflectionEnum($class))->getBackingType();

        return [
            'backing' => $backing instanceof ReflectionNamedType ? $backing->getName() : null,
            'values' => array_map(fn (UnitEnum $case): string|int => $case instanceof BackedEnum ? $case->value : $case->name, $class::cases()),
        ];
    }

    /**
     * The table an entity of this manifest is stored in: the one designed, or the one its name
     * leads to.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function tableOf(string $context, array $manifest, string $entity): string
    {
        $designed = $manifest['entities'][$entity]['table']['name'] ?? null;

        return is_string($designed) ? $designed : Str::snake(Str::pluralStudly($entity));
    }

    /**
     * The table of the aggregate an id names: one of this context first, else of the only other
     * context that has it, or null when none or several do.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function aggregateTable(string $context, array $manifest, string $aggregate): ?string
    {
        if (is_array($manifest['aggregates'][$aggregate] ?? null)) {
            return $this->tableOf($context, $manifest, $aggregate);
        }

        $found = [];

        foreach ($this->files->contexts() as $other) {
            $read = $other === $context ? null : $this->files->read($other);

            if (is_array($read) && is_array($read['aggregates'][$aggregate] ?? null)) {
                $found[] = $this->tableOf($other, $read, $aggregate);
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The aggregate whose root or child the entity is, or null.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function holderOf(array $manifest, string $entity): ?string
    {
        foreach (is_array($manifest['aggregates'] ?? null) ? $manifest['aggregates'] : [] as $aggregate => $entry) {
            if ($aggregate === $entity || (is_array($entry) && in_array($entity, $entry['children'] ?? [], true))) {
                return (string) $aggregate;
            }
        }

        return null;
    }

    /**
     * Whether a comment says something: written, and no longer the proposal's todo.
     */
    private function says(mixed $comment): bool
    {
        return is_string($comment) && trim($comment) !== '' && ! str_starts_with($comment, self::NULL_TODO);
    }

    /**
     * @param  Errors  $errors
     * @param  Errors  $more
     * @return Errors
     */
    private function merge(array $errors, array $more): array
    {
        foreach ($more as $field => $messages) {
            $errors[$field] = [...($errors[$field] ?? []), ...$messages];
        }

        return $errors;
    }
}
