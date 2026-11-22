<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Illuminate\Support\Str;
use ReflectionEnum;

/**
 * Writes the persistence a manifest's table designs (structure.md, "Tables"): the migration that
 * builds it, or the one that adds what a built table lacks, and the model with its factory, in the
 * shape migrations.md and models.md give them. StructureTables reads a migration written here back
 * as the design it was written from.
 *
 * A migration only ever adds. A column, foreign key or index the design describes another way than
 * the migrations built it, or no longer lists, is a decision about the rows already stored, so it
 * is left to a person, and the check names it.
 *
 * @phpstan-type Design array{columns: array<string, array<string, mixed>>, foreignKeys: array<string, array<string, mixed>>, indexes: list<array<string, mixed>>, timestamps: bool, softDeletes: bool}
 * @phpstan-type Related array{enums: array<string, class-string>, parents: array<string, string|null>, children: list<array{model: string, column: string}>, missing: list<string>}
 */
final class StructureTableWriter
{
    public const array INTEGERS = [
        'integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'mediumInteger',
        'unsignedInteger', 'unsignedBigInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'unsignedMediumInteger',
    ];

    public const array DATETIMES = ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'];

    public const array TEXTS = ['text', 'tinyText', 'mediumText', 'longText'];

    /**
     * What a design adds to the table the migrations built, or the whole design when they build no
     * such table: the columns, foreign keys and indexes they lack, and timestamps or soft deletes
     * the design turns on.
     *
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>|null  $built
     * @return Design
     */
    public static function additions(array $design, ?array $built): array
    {
        /** @var array<string, array<string, mixed>> $columns */
        $columns = $design['columns'] ?? [];
        /** @var array<string, array<string, mixed>> $foreignKeys */
        $foreignKeys = $design['foreignKeys'] ?? [];
        /** @var list<array<string, mixed>> $indexes */
        $indexes = $design['indexes'] ?? [];
        $timestamps = ($design['timestamps'] ?? false) === true;
        $softDeletes = ($design['softDeletes'] ?? false) === true;

        if ($built === null) {
            return ['columns' => $columns, 'foreignKeys' => $foreignKeys, 'indexes' => $indexes, 'timestamps' => $timestamps, 'softDeletes' => $softDeletes];
        }

        /** @var array<string, mixed> $builtColumns */
        $builtColumns = [...(is_array($built['columns'] ?? null) ? $built['columns'] : []), ...(is_array($built['foreignKeys'] ?? null) ? $built['foreignKeys'] : [])];
        /** @var list<array<string, mixed>> $builtIndexes */
        $builtIndexes = is_array($built['indexes'] ?? null) ? $built['indexes'] : [];
        $builtKeys = array_map(self::indexKey(...), $builtIndexes);

        return [
            'columns' => array_diff_key($columns, $builtColumns),
            'foreignKeys' => array_diff_key($foreignKeys, $builtColumns),
            'indexes' => array_values(array_filter($indexes, fn (array $index): bool => ! in_array(self::indexKey($index), $builtKeys, true))),
            'timestamps' => $timestamps && ($built['timestamps'] ?? false) !== true,
            'softDeletes' => $softDeletes && ($built['softDeletes'] ?? false) !== true,
        ];
    }

    /**
     * @param  Design  $additions
     */
    public static function addsNothing(array $additions): bool
    {
        return $additions['columns'] === [] && $additions['foreignKeys'] === [] && $additions['indexes'] === []
            && ! $additions['timestamps'] && ! $additions['softDeletes'];
    }

    /**
     * The migration's file name, without its date: `create_{table}_table`, `add_{column}_to_{table}_table`
     * when it adds one column and nothing else, or `update_{table}_table`.
     *
     * @param  Design  $additions
     */
    public static function migrationName(string $table, array $additions, bool $create): string
    {
        if ($create) {
            return "create_{$table}_table";
        }

        $added = [...array_keys($additions['columns']), ...array_keys($additions['foreignKeys'])];

        return count($added) === 1 && $additions['indexes'] === [] && ! $additions['timestamps'] && ! $additions['softDeletes']
            ? "add_{$added[0]}_to_{$table}_table"
            : "update_{$table}_table";
    }

    /**
     * The migration that creates the table, or, for a table already built, the one that adds what
     * it lacks, with a `down()` that undoes exactly that (migrations.md). The foreign keys come
     * first, then the columns, each in the order the design lists them.
     *
     * @param  Design  $additions
     */
    public static function migration(string $table, array $additions, bool $create): string
    {
        $lines = $create ? ["\$table->uuid('id')->primary();"] : [];

        foreach ($additions['foreignKeys'] as $column => $key) {
            $lines = [...$lines, ...self::commented($key), self::foreignKeyLine($column, $key)];
        }

        foreach ($additions['columns'] as $column => $definition) {
            $lines = [...$lines, ...self::commented($definition), self::columnLine($column, $definition)];
        }

        if ($additions['timestamps']) {
            $lines[] = '$table->timestamps();';
        }

        if ($additions['softDeletes']) {
            $lines[] = '$table->softDeletes();';
        }

        if ($additions['indexes'] !== []) {
            $lines[] = '';

            foreach ($additions['indexes'] as $index) {
                $lines = [...$lines, ...self::commented($index), self::indexLine($index)];
            }
        }

        $up = self::schemaCall($create ? 'create' : 'table', $table, $lines);
        $down = $create ? "        Schema::dropIfExists('{$table}');" : self::schemaCall('table', $table, self::dropLines($additions));

        return implode("\n", [
            '<?php',
            '',
            'use Illuminate\\Database\\Migrations\\Migration;',
            'use Illuminate\\Database\\Schema\\Blueprint;',
            'use Illuminate\\Support\\Facades\\Schema;',
            '',
            'return new class extends Migration',
            '{',
            '    /**',
            '     * Run the migrations.',
            '     */',
            '    public function up(): void',
            '    {',
            $up,
            '    }',
            '',
            '    /**',
            '     * Reverse the migrations.',
            '     */',
            '    public function down(): void',
            '    {',
            $down,
            '    }',
            '};',
            '',
        ]);
    }

    /**
     * What the model a design names holds, for the structure screen's table page: its fillable
     * columns, each cast `make:table-model` writes (an enum by its name, built or not), and its relations, each as `{method}(): {Kind}
     * {Model}`.
     *
     * @param  Design  $design
     * @param  Related  $related
     * @return array{fillable: list<string>, casts: array<string, string>, relations: list<string>}
     */
    public static function summary(string $model, array $design, array $related): array
    {
        $casts = [];

        foreach ($design['columns'] as $column => $definition) {
            $enum = $related['enums'][$column] ?? (is_string($definition['enum'] ?? null) ? str_replace('/', '\\', $definition['enum']) : null);
            $cast = $enum === null ? self::castOf($definition)[1] : class_basename($enum);

            if ($cast !== null) {
                $casts[(string) $column] = trim($cast, "'");
            }
        }

        if ($design['softDeletes']) {
            $casts['deleted_at'] = 'datetime';
        }

        return [
            'fillable' => array_map(strval(...), [...array_keys($design['foreignKeys']), ...array_keys($design['columns'])]),
            'casts' => $casts,
            'relations' => array_map(fn (array $relation): string => "{$relation['method']}(): {$relation['kind']} {$relation['model']}", self::relations($model, $related)),
        ];
    }

    /**
     * The model of a table (models.md): `KeyedByUuid` and `HasFactory`, `#[Fillable]` with every
     * column a repository fills, one `@property` per column typed as its cast returns, `casts()`,
     * and a relation per foreign key whose parent has a model and per table whose foreign key
     * points here. `#[Table]` names a table the model's name does not lead to.
     *
     * @param  Design  $design
     * @param  Related  $related
     */
    public static function model(string $model, string $table, array $design, array $related): string
    {
        $imports = [
            'App\\Models\\Concerns\\KeyedByUuid',
            "Database\\Factories\\{$model}Factory",
            'Illuminate\\Database\\Eloquent\\Attributes\\Fillable',
            'Illuminate\\Database\\Eloquent\\Factories\\HasFactory',
            'Illuminate\\Database\\Eloquent\\Model',
        ];
        $attributes = ['#[Fillable(['.implode(', ', array_map(fn (string $column): string => "'{$column}'", [...array_keys($design['foreignKeys']), ...array_keys($design['columns'])])).'])]'];
        $properties = ['string $id'];
        $casts = [];
        $traits = ['HasFactory', 'KeyedByUuid'];

        if ($table !== Str::snake(Str::pluralStudly($model))) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Attributes\\Table';
            $attributes[] = "#[Table('{$table}')]";
        }

        if (! $design['timestamps']) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Attributes\\WithoutTimestamps';
            $attributes[] = '#[WithoutTimestamps]';
        }

        foreach ($design['foreignKeys'] as $column => $key) {
            $properties[] = 'string'.self::orNull($key)." \${$column}";
        }

        foreach ($design['columns'] as $column => $definition) {
            $enum = $related['enums'][$column] ?? null;
            [$type, $cast] = $enum === null ? self::castOf($definition) : [class_basename($enum), class_basename($enum).'::class'];

            if ($enum !== null) {
                $imports[] = $enum;
            }

            if ($type === 'Carbon') {
                $imports[] = 'Illuminate\\Support\\Carbon';
            }

            $properties[] = $type.self::orNull($definition)." \${$column}";

            if ($cast !== null) {
                $casts[] = "            '{$column}' => {$cast},";
            }
        }

        if ($design['timestamps']) {
            $imports[] = 'Illuminate\\Support\\Carbon';
            $properties[] = 'Carbon|null $created_at';
            $properties[] = 'Carbon|null $updated_at';
        }

        if ($design['softDeletes']) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\SoftDeletes';
            $imports[] = 'Illuminate\\Support\\Carbon';
            $properties[] = 'Carbon|null $deleted_at';
            $traits[] = 'SoftDeletes';
        }

        $lines = [
            '<?php',
            '',
            'namespace App\\Models;',
            '',
            ...self::importLines([...$imports, ...array_map(fn (array $relation): string => 'Illuminate\\Database\\Eloquent\\Relations\\'.$relation['kind'], self::relations($model, $related))], 'App\\Models'),
            '',
            '/**',
            ...array_map(fn (string $property): string => " * @property {$property}", $properties),
            ' */',
            ...$attributes,
            "class {$model} extends Model",
            '{',
            "    /** @use HasFactory<{$model}Factory> */",
            '    use '.implode(', ', $traits).';',
            '',
            '    /**',
            '     * @return array<string, string>',
            '     */',
            '    protected function casts(): array',
            '    {',
            ...($casts === [] ? ['        return [];'] : ['        return [', ...$casts, '        ];']),
            '    }',
        ];

        foreach (self::relations($model, $related) as $relation) {
            $lines = [
                ...$lines,
                '',
                '    /**',
                "     * @return {$relation['kind']}<{$relation['model']}, \$this>",
                '     */',
                "    public function {$relation['method']}(): {$relation['kind']}",
                '    {',
                "        return \$this->{$relation['call']}({$relation['model']}::class, '{$relation['column']}');",
                '    }',
            ];
        }

        return implode("\n", [...$lines, '}', '']);
    }

    /**
     * The model's factory (models.md): `'id' => fake()->uuid()`, a value that fits each column,
     * null for a nullable one, the first case of an enum, and the parent's factory for a foreign
     * key. A foreign key whose parent has no model is left for a person to fill.
     *
     * @param  Design  $design
     * @param  Related  $related
     */
    public static function factory(string $model, array $design, array $related): string
    {
        $imports = ["App\\Models\\{$model}", 'Illuminate\\Database\\Eloquent\\Factories\\Factory'];
        $values = ["            'id' => fake()->uuid(),"];

        foreach ($design['foreignKeys'] as $column => $key) {
            $parent = $related['parents'][$column] ?? null;

            if (($key['nullable'] ?? false) === true || $parent === $model) {
                $values[] = "            '{$column}' => null,";
            } elseif ($parent === null) {
                $values[] = "            '{$column}' => null, // @todo no model maps {$key['table']}, so give it a row by hand";
            } else {
                $imports[] = "App\\Models\\{$parent}";
                $values[] = "            '{$column}' => {$parent}::factory(),";
            }
        }

        foreach ($design['columns'] as $column => $definition) {
            $enum = $related['enums'][$column] ?? null;

            if ($enum !== null && ($definition['nullable'] ?? false) !== true) {
                $imports[] = $enum;
            }

            $values[] = "            '{$column}' => ".self::fakeValue($definition, $enum).',';
        }

        return implode("\n", [
            '<?php',
            '',
            'namespace Database\\Factories;',
            '',
            ...self::importLines($imports, 'Database\\Factories'),
            '',
            '/**',
            " * @extends Factory<{$model}>",
            ' */',
            "class {$model}Factory extends Factory",
            '{',
            '    /**',
            "     * Define the model's default state.",
            '     *',
            '     * @return array<string, mixed>',
            '     */',
            '    public function definition(): array',
            '    {',
            '        return [',
            ...$values,
            '        ];',
            '    }',
            '}',
            '',
        ]);
    }

    /**
     * What a table's model and factory name besides its columns: the enum class of each column
     * that names one, the model of each foreign key's parent table, and each table with a model
     * whose foreign key points here, from the designs first and the code after. `missing` lists the
     * enums no class is built for yet.
     *
     * @param  array<string, array{context: string, entity: string|null, aggregate: string|null, table: array<string, mixed>}>  $designed  StructureFiles::designedTables()
     * @return Related
     */
    public static function related(string $table, array $designed, StructureReader $reader): array
    {
        $design = $designed[$table];
        $related = ['enums' => [], 'parents' => [], 'children' => [], 'missing' => []];
        $modelOf = fn (string $name): ?string => is_string($designed[$name]['table']['model'] ?? null) ? $designed[$name]['table']['model'] : $reader->tables()->modelOf($name);

        foreach ($design['table']['columns'] ?? [] as $column => $definition) {
            $label = is_array($definition) && is_string($definition['enum'] ?? null) ? $definition['enum'] : null;

            if ($label === null) {
                continue;
            }

            $class = $reader->vocabularyClass($label, $design['context'], $design['aggregate']);

            if ($class !== null && enum_exists($class)) {
                $related['enums'][(string) $column] = $class;
            } else {
                $related['missing'][] = $label;
            }
        }

        foreach ($design['table']['foreignKeys'] ?? [] as $column => $key) {
            $parent = is_array($key) && is_string($key['table'] ?? null) ? $key['table'] : null;
            $related['parents'][(string) $column] = $parent === null ? null : $modelOf($parent);
        }

        foreach ($designed as $name => $other) {
            $model = $other['table']['model'] ?? null;

            foreach (is_string($model) ? $other['table']['foreignKeys'] ?? [] : [] as $column => $key) {
                if (is_array($key) && ($key['table'] ?? null) === $table && $name !== $table) {
                    $related['children'][] = ['model' => $model, 'column' => (string) $column];
                }
            }
        }

        return $related;
    }

    /**
     * One index the same way whichever side lists it.
     *
     * @param  array<string, mixed>  $index
     */
    private static function indexKey(array $index): string
    {
        return json_encode([$index['columns'] ?? [], ($index['unique'] ?? false) === true], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function schemaCall(string $method, string $table, array $lines): string
    {
        return implode("\n", [
            "        Schema::{$method}('{$table}', function (Blueprint \$table) {",
            ...array_map(fn (string $line): string => $line === '' ? '' : "            {$line}", $lines),
            '        });',
        ]);
    }

    /**
     * What `down()` drops of a table it only added to: the indexes first, then the foreign keys
     * with their columns, then the columns.
     *
     * @param  Design  $additions
     * @return list<string>
     */
    private static function dropLines(array $additions): array
    {
        $lines = [];

        foreach (array_reverse($additions['indexes']) as $index) {
            $lines[] = '$table->'.(($index['unique'] ?? false) === true ? 'dropUnique' : 'dropIndex').'('.self::export($index['columns'] ?? []).');';
        }

        foreach ([...$additions['foreignKeys'], ...$additions['columns']] as $column => $definition) {
            if (($definition['unique'] ?? false) === true) {
                $lines[] = "\$table->dropUnique(['{$column}']);";
            } elseif (($definition['index'] ?? false) === true) {
                $lines[] = "\$table->dropIndex(['{$column}']);";
            }
        }

        foreach (array_reverse(array_keys($additions['foreignKeys'])) as $column) {
            $lines[] = "\$table->dropConstrainedForeignId('{$column}');";
        }

        if ($additions['columns'] !== []) {
            $lines[] = '$table->dropColumn('.self::export(array_keys($additions['columns'])).');';
        }

        if ($additions['softDeletes']) {
            $lines[] = '$table->dropSoftDeletes();';
        }

        if ($additions['timestamps']) {
            $lines[] = '$table->dropTimestamps();';
        }

        return $lines;
    }

    /**
     * The comment a design gives a piece, as the doc comment above its line.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function commented(array $definition): array
    {
        $comment = $definition['comment'] ?? null;

        return is_string($comment) && $comment !== '' ? ['/** '.str_replace('*/', '* /', $comment).' */'] : [];
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private static function foreignKeyLine(string $column, array $key): string
    {
        $onDelete = is_string($key['onDelete'] ?? null) ? "->{$key['onDelete']}OnDelete()" : '';

        return "\$table->foreignUuid('{$column}')".self::flags($key).'->constrained('.self::export($key['table'] ?? '').")$onDelete;";
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function columnLine(string $column, array $definition): string
    {
        $type = is_string($definition['type'] ?? null) ? $definition['type'] : 'string';
        $arguments = [self::export($column)];

        if (in_array($type, ['string', 'char'], true) && is_int($definition['length'] ?? null)) {
            $arguments[] = (string) $definition['length'];
        }

        if (in_array($type, ['decimal', 'unsignedDecimal'], true) && is_int($definition['precision'] ?? null)) {
            $arguments[] = (string) $definition['precision'];

            if (is_int($definition['scale'] ?? null)) {
                $arguments[] = (string) $definition['scale'];
            }
        }

        $default = array_key_exists('default', $definition) ? '->default('.self::export($definition['default']).')' : '';

        return "\$table->{$type}(".implode(', ', $arguments).')'.self::flags($definition)."{$default};";
    }

    /**
     * @param  array<string, mixed>  $index
     */
    private static function indexLine(array $index): string
    {
        return '$table->'.(($index['unique'] ?? false) === true ? 'unique' : 'index').'('.self::export($index['columns'] ?? []).');';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function flags(array $definition): string
    {
        return (($definition['nullable'] ?? false) === true ? '->nullable()' : '')
            .match (true) {
                ($definition['unique'] ?? false) === true => '->unique()',
                ($definition['index'] ?? false) === true => '->index()',
                default => '',
            };
    }

    /**
     * A value as PHP source, a list on one line with short brackets.
     */
    private static function export(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(', ', array_map(self::export(...), $value)).']';
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_string($value) => "'".addcslashes($value, "'\\")."'",
            is_int($value), is_float($value) => (string) $value,
            default => 'null',
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function orNull(array $definition): string
    {
        return ($definition['nullable'] ?? false) === true ? '|null' : '';
    }

    /**
     * The type a column's `@property` shows and the cast `casts()` declares for it, if any
     * (models.md).
     *
     * @param  array<string, mixed>  $definition
     * @return array{0: string, 1: string|null}
     */
    private static function castOf(array $definition): array
    {
        $type = $definition['type'] ?? null;

        return match (true) {
            in_array($type, self::INTEGERS, true) => ['int', "'integer'"],
            $type === 'boolean' => ['bool', "'boolean'"],
            in_array($type, ['decimal', 'unsignedDecimal'], true) => ['string', "'decimal:".(is_int($definition['scale'] ?? null) ? $definition['scale'] : 2)."'"],
            in_array($type, ['float', 'double'], true) => ['float', "'float'"],
            $type === 'date' => ['Carbon', "'date'"],
            in_array($type, self::DATETIMES, true) => ['Carbon', "'datetime'"],
            in_array($type, ['json', 'jsonb'], true) => ['array', "'array'"],
            default => ['string', null],
        };
    }

    /**
     * A value that fits a column, for a factory.
     *
     * @param  array<string, mixed>  $definition
     */
    private static function fakeValue(array $definition, ?string $enum): string
    {
        if (($definition['nullable'] ?? false) === true) {
            return 'null';
        }

        if ($enum !== null) {
            $first = enum_exists($enum) ? ((new ReflectionEnum($enum))->getCases()[0] ?? null)?->getName() : null;

            return $first === null ? class_basename($enum).'::cases()[0]' : class_basename($enum)."::{$first}";
        }

        $type = $definition['type'] ?? null;
        $unique = ($definition['unique'] ?? false) === true ? 'unique()->' : '';
        $length = is_int($definition['length'] ?? null) ? $definition['length'] : 255;

        return match (true) {
            in_array($type, ['string', 'char'], true) && $length >= 30 => "fake()->{$unique}words(3, true)",
            in_array($type, ['string', 'char'], true) => "fake()->{$unique}lexify('".str_repeat('?', min($length, 10))."')",
            in_array($type, self::TEXTS, true) => 'fake()->sentence()',
            in_array($type, self::INTEGERS, true) => $unique === '' ? 'fake()->numberBetween(0, 100)' : 'fake()->unique()->numberBetween(1, 100000)',
            $type === 'boolean' => 'fake()->boolean()',
            in_array($type, ['decimal', 'unsignedDecimal', 'float', 'double'], true) => 'fake()->randomFloat('.(is_int($definition['scale'] ?? null) ? $definition['scale'] : 2).', 0, 100)',
            $type === 'date' => 'fake()->date()',
            in_array($type, self::DATETIMES, true) => 'fake()->dateTime()',
            in_array($type, ['time', 'timeTz'], true) => 'fake()->time()',
            $type === 'year' => '(int) fake()->year()',
            in_array($type, ['uuid', 'ulid'], true) => "fake()->{$unique}uuid()",
            in_array($type, ['json', 'jsonb'], true) => '[]',
            default => "fake()->{$unique}word()",
        };
    }

    /**
     * A relation per foreign key whose parent has a model (`belongsTo`, named after the column
     * without `_id`), and per table with a model whose foreign key points here (`hasMany`, named
     * after that model in the plural). A name taken once is not taken again.
     *
     * @param  Related  $related
     * @return list<array{kind: string, call: string, method: string, model: string, column: string}>
     */
    private static function relations(string $model, array $related): array
    {
        $relations = [];

        foreach ($related['parents'] as $column => $parent) {
            if ($parent !== null) {
                $method = Str::camel((string) preg_replace('/_id$/', '', $column));
                $relations[$method] ??= ['kind' => 'BelongsTo', 'call' => 'belongsTo', 'method' => $method, 'model' => $parent, 'column' => $column];
            }
        }

        foreach ($related['children'] as $child) {
            $method = Str::camel(Str::pluralStudly($child['model']));
            $relations[$method] ??= ['kind' => 'HasMany', 'call' => 'hasMany', 'method' => $method, 'model' => $child['model'], 'column' => $child['column']];
        }

        return array_values($relations);
    }

    /**
     * The `use` lines of a file, sorted the way Pint sorts them, leaving out a class the file's own
     * namespace holds.
     *
     * @param  list<string>  $classes
     * @return list<string>
     */
    private static function importLines(array $classes, string $namespace): array
    {
        $classes = array_values(array_unique(array_filter(
            $classes,
            fn (string $class): bool => preg_match('/^'.preg_quote($namespace, '/').'\\\\\w+$/', $class) !== 1,
        )));
        usort($classes, fn (string $a, string $b): int => strcasecmp($a, $b));

        return array_map(fn (string $class): string => "use {$class};", $classes);
    }
}
