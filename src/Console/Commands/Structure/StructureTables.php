<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Illuminate\Database\Eloquent\Model;
use PhpToken;
use ReflectionClass;
use stdClass;
use Throwable;

/**
 * Reads the tables a project's migrations build, from their source, with no database (structure.md,
 * "Tables"): every `Schema::create()`, `Schema::table()`, `Schema::rename()` and `Schema::drop*()`
 * of `database/migrations`, replayed in the order the files run, into the shape a manifest's table
 * takes. It also reads which model maps to which table, and the enum each model casts a column to.
 *
 * Only what migrations.md lets a migration say is read: columns, `foreignUuid()->constrained()`
 * with its delete behaviour, indexes, `timestamps()` and `softDeletes()`. The uuid key every table
 * opens with is implied, so it is left out. A polymorphic reference (a `{x}_id` uuid beside a
 * `{x}_type` string, with no foreign key) is written by hand, so its pair of columns, and every index
 * that names either of them, is left out and listed by handWritten().
 *
 * The migrations Laravel and its packages publish keep their own idioms, and the kit's own audit
 * log table is the kit's, so neither is read.
 *
 * @phpstan-type Column array{type: string, length?: int, precision?: int, scale?: int, enum?: string, nullable?: true, unique?: true, index?: true, default?: string|int|float|bool, comment?: string}
 * @phpstan-type ForeignKey array{table: string, onDelete?: string, nullable?: true, unique?: true, index?: true, comment?: string}
 * @phpstan-type Index array{columns: list<string>, unique: bool, comment?: string}
 * @phpstan-type Table array{columns: array<string, Column>, foreignKeys: array<string, ForeignKey>, indexes: list<Index>, timestamps: bool, softDeletes: bool}
 */
final class StructureTables
{
    public const string MIGRATIONS = 'database/migrations';

    /**
     * The migrations Laravel and its packages publish (migrations.md), which keep their own idioms.
     */
    public const array FRAMEWORK_MIGRATIONS = ['0001_01_01_*.php', '*_create_passkeys_table.php', '*_add_two_factor_columns_to_users_table.php'];

    /**
     * The kit's own tables (audit-log.md), which no manifest lists.
     */
    public const array KIT_TABLES = ['audit_entries'];

    /**
     * What a delete of the parent does, by the stem of its `{stem}OnDelete()` (migrations.md).
     */
    public const array ON_DELETE = ['cascade', 'restrict', 'null', 'noAction'];

    /**
     * The longhand `onDelete('…')` spellings, by the stem they stand for.
     */
    private const array ON_DELETE_LONGHAND = ['cascade' => 'cascade', 'restrict' => 'restrict', 'set null' => 'null', 'no action' => 'noAction'];

    /**
     * The `foreign*()` columns, which `constrained()` turns into a foreign key.
     */
    private const array FOREIGN_COLUMNS = ['foreignUuid', 'foreignId', 'foreignUlid'];

    /**
     * The integer columns `->unsigned()` turns into their unsigned twin.
     */
    private const array SIGNED_INTEGERS = ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'mediumInteger'];

    /**
     * Blueprint methods that add a polymorphic pair of columns.
     */
    private const array MORPHS = ['morphs', 'nullableMorphs', 'uuidMorphs', 'nullableUuidMorphs', 'ulidMorphs', 'nullableUlidMorphs', 'numericMorphs', 'nullableNumericMorphs'];

    /**
     * Blueprint methods that are no column, so the first call of a statement that names one is
     * never read as a column of that type.
     */
    private const array COMMANDS = [
        'id', 'timestamps', 'timestampsTz', 'nullableTimestamps', 'softDeletes', 'softDeletesTz', 'rememberToken',
        'index', 'unique', 'primary', 'fullText', 'spatialIndex', 'foreign',
        'dropColumn', 'dropColumns', 'renameColumn', 'dropForeign', 'dropConstrainedForeignId', 'dropForeignIdFor', 'dropConstrainedForeignIdFor',
        'dropIndex', 'dropUnique', 'dropPrimary', 'dropFullText', 'renameIndex',
        'dropTimestamps', 'dropTimestampsTz', 'dropSoftDeletes', 'dropSoftDeletesTz', 'dropRememberToken', 'dropMorphs',
        'engine', 'charset', 'collation', 'comment', 'temporary',
    ];

    private const string MODEL = 'Illuminate\Database\Eloquent\Model';

    /**
     * @var array<string, Table>|null
     */
    private ?array $schema = null;

    /**
     * @var array<string, list<string>>
     */
    private array $handWritten = [];

    /**
     * @var array<string, array{class: class-string, table: string, casts: array<string, string>}>|null
     */
    private ?array $models = null;

    /**
     * @var list<string>|null
     */
    private ?array $frameworkTables = null;

    /**
     * @param  string|null  $migrations  the folder the migrations live in, `database/migrations` when null
     */
    public function __construct(
        private readonly string $root,
        private readonly ?string $migrations = null,
    ) {}

    /**
     * The folder the migrations are read from, when it is not `database/migrations`.
     */
    public function folder(): ?string
    {
        return $this->migrations;
    }

    /**
     * Every table the project's own migrations build, in the order they first build them.
     *
     * @return array<string, Table>
     */
    public function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $tables = [];
        $directory = $this->migrations ?? $this->root.'/'.self::MIGRATIONS;
        $files = glob($directory.'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            if ($this->isFrameworkMigration(basename($file))) {
                continue;
            }

            $this->replay($tables, StructureFiles::text($file));
        }

        foreach (self::KIT_TABLES as $table) {
            unset($tables[$table]);
        }

        foreach ($tables as $name => $table) {
            $tables[$name] = $this->settled($name, $table);
        }

        return $this->schema = $tables;
    }

    /**
     * The tables Laravel's own migrations and the ones its packages publish build (`users`,
     * `sessions`, `jobs`), which the manifest leaves out but a designed foreign key may point at.
     *
     * @return list<string>
     */
    public function frameworkTables(): array
    {
        if ($this->frameworkTables !== null) {
            return $this->frameworkTables;
        }

        $tables = [];
        $directory = $this->migrations ?? $this->root.'/'.self::MIGRATIONS;
        $files = glob($directory.'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            if ($this->isFrameworkMigration(basename($file))) {
                $this->replay($tables, StructureFiles::text($file));
            }
        }

        return $this->frameworkTables = array_map(strval(...), array_keys($tables));
    }

    /**
     * The columns of a table that are written by hand, because the manifest has no word for them:
     * each polymorphic pair (migrations.md), as `{x}_type/{x}_id`.
     *
     * @return list<string>
     */
    public function handWritten(string $table): array
    {
        $this->schema();

        return $this->handWritten[$table] ?? [];
    }

    /**
     * Whether a migration's file name is one Laravel or its packages publish.
     */
    public static function isFrameworkMigration(string $file): bool
    {
        foreach (self::FRAMEWORK_MIGRATIONS as $glob) {
            if (fnmatch($glob, $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every model under app/Models by its class name, with the table it maps to and the casts it
     * declares, read off a fresh instance, which needs no database.
     *
     * @return array<string, array{class: class-string, table: string, casts: array<string, string>}>
     */
    public function models(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }

        $models = [];
        $directory = $this->root.'/app/Models';
        $files = is_dir($directory) ? glob($directory.'/*.php') ?: [] : [];
        sort($files);

        foreach ($files as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, self::MODEL) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            try {
                /** @var Model $model */
                $model = new $class;
                $table = $model->getTable();
                $casts = array_filter($model->getCasts(), is_string(...));
            } catch (Throwable) {
                continue;
            }

            $models[basename($file, '.php')] = ['class' => $class, 'table' => $table, 'casts' => $casts];
        }

        return $this->models = $models;
    }

    /**
     * The model that maps to a table, or null.
     */
    public function modelOf(string $table): ?string
    {
        foreach ($this->models() as $name => $model) {
            if ($model['table'] === $table) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Replays one migration's `up()` on the tables built so far.
     *
     * @param  array<string, Table>  $tables
     */
    private function replay(array &$tables, string $code): void
    {
        $tokens = self::significant(PhpToken::tokenize($code));
        $up = $this->methodBody($tokens, 'up');

        if ($up === null) {
            return;
        }

        [$start, $end] = $up;

        for ($i = $start; $i < $end; $i++) {
            if (! $tokens[$i]->is('Schema') || ! ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON) || ! ($tokens[$i + 3] ?? null)?->is('(')) {
                continue;
            }

            $method = $tokens[$i + 2]->text;
            $close = $this->closing($tokens, $i + 3);
            $arguments = $this->arguments($tokens, $i + 4, $close);
            $name = $this->literal($tokens, $arguments[0] ?? null);

            if (! is_string($name)) {
                $i = $close;

                continue;
            }

            match ($method) {
                'create' => $tables[$name] = $this->blueprint($tokens, $arguments[1] ?? null, $name, $this->emptyTable()),
                'table' => isset($tables[$name]) ? $tables[$name] = $this->blueprint($tokens, $arguments[1] ?? null, $name, $tables[$name]) : null,
                'drop', 'dropIfExists' => $this->drop($tables, $name),
                'rename' => $this->rename($tables, $name, $this->literal($tokens, $arguments[1] ?? null)),
                default => null,
            };

            $i = $close;
        }
    }

    /**
     * @param  array<string, Table>  $tables
     */
    private function drop(array &$tables, string $name): void
    {
        unset($tables[$name]);
    }

    /**
     * @param  array<string, Table>  $tables
     */
    private function rename(array &$tables, string $from, mixed $to): void
    {
        if (is_string($to) && isset($tables[$from])) {
            $tables[$to] = $tables[$from];
            unset($tables[$from]);
        }
    }

    /**
     * @return Table
     */
    private function emptyTable(): array
    {
        return ['columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false];
    }

    /**
     * Runs the statements of a Schema closure on a table.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array{0: int, 1: int}|null  $argument
     * @param  Table  $table
     * @return Table
     */
    private function blueprint(array $tokens, ?array $argument, string $name, array $table): array
    {
        if ($argument === null) {
            return $table;
        }

        $open = null;

        for ($i = $argument[0]; $i < $argument[1]; $i++) {
            if ($tokens[$i]->is('{')) {
                $open = $i;

                break;
            }
        }

        if ($open === null) {
            return $table;
        }

        foreach ($this->statements($tokens, $open + 1, $this->closing($tokens, $open)) as [$comment, $calls]) {
            $table = $this->apply($table, $name, $calls, $comment);
        }

        return $table;
    }

    /**
     * One `$table->…;` statement applied to a table.
     *
     * @param  Table  $table
     * @param  list<array{0: string, 1: list<mixed>}>  $calls
     * @return Table
     */
    private function apply(array $table, string $name, array $calls, ?string $comment): array
    {
        [$method, $arguments] = $calls[0];
        $modifiers = array_slice($calls, 1);
        $first = $arguments[0] ?? null;

        switch ($method) {
            case 'id':
                return $table;
            case 'timestamps':
            case 'timestampsTz':
            case 'nullableTimestamps':
                $table['timestamps'] = true;

                return $table;
            case 'softDeletes':
            case 'softDeletesTz':
                $table['softDeletes'] = true;

                return $table;
            case 'dropTimestamps':
            case 'dropTimestampsTz':
                $table['timestamps'] = false;

                return $table;
            case 'dropSoftDeletes':
            case 'dropSoftDeletesTz':
                $table['softDeletes'] = false;

                return $table;
            case 'rememberToken':
                $table['columns']['remember_token'] = ['type' => 'string', 'length' => 100, 'nullable' => true];

                return $table;
            case 'index':
            case 'unique':
                $columns = is_string($first) ? [$first] : (is_array($first) ? array_values(array_filter($first, is_string(...))) : []);

                if ($columns !== []) {
                    $table['indexes'][] = ['columns' => $columns, 'unique' => $method === 'unique', ...($comment === null ? [] : ['comment' => $comment])];
                }

                return $table;
            case 'foreign':
                return is_string($first) ? $this->longhandForeign($table, $first, $modifiers) : $table;
            case 'dropColumn':
            case 'dropColumns':
            case 'dropConstrainedForeignId':
                foreach ($this->names($arguments) as $column) {
                    $table = $this->dropColumn($table, $column);
                }

                return $table;
            case 'renameColumn':
                return is_string($first) && is_string($arguments[1] ?? null) ? $this->renameColumn($table, $first, $arguments[1]) : $table;
            case 'dropForeign':
                $column = is_array($first) ? ($first[0] ?? null) : (is_string($first) ? $this->columnOfIndex($table, $name, $first, 'foreign') : null);

                return is_string($column) ? $this->dropForeign($table, $column) : $table;
            case 'dropIndex':
            case 'dropUnique':
                return $this->dropIndex($table, $name, $first, $method === 'dropUnique');
        }

        if (in_array($method, self::MORPHS, true)) {
            if (is_string($first)) {
                $this->handWritten[$name][] = "{$first}_type/{$first}_id";
            }

            return $table;
        }

        if (in_array($method, self::COMMANDS, true) || ! is_string($first)) {
            return $table;
        }

        return $this->column($table, $method, $first, $arguments, $modifiers, $comment);
    }

    /**
     * A column, or a foreign key when a `foreign*()` column is `constrained()`.
     *
     * @param  Table  $table
     * @param  list<mixed>  $arguments
     * @param  list<array{0: string, 1: list<mixed>}>  $modifiers
     * @return Table
     */
    private function column(array $table, string $method, string $name, array $arguments, array $modifiers, ?string $comment): array
    {
        $calls = [];

        foreach ($modifiers as [$modifier, $values]) {
            $calls[$modifier] = $values;
        }

        if ($name === 'id') {
            return $table;
        }

        $comment = is_string($calls['comment'][0] ?? null) ? $calls['comment'][0] : $comment;
        $flags = array_filter([
            'nullable' => array_key_exists('nullable', $calls) && ($calls['nullable'][0] ?? true) !== false,
            'unique' => array_key_exists('unique', $calls),
            'index' => array_key_exists('index', $calls),
        ]);

        if (in_array($method, self::FOREIGN_COLUMNS, true) && array_key_exists('constrained', $calls)) {
            $parent = $calls['constrained'][0] ?? null;
            unset($table['columns'][$name]);
            $table['foreignKeys'][$name] = [
                'table' => is_string($parent) ? $parent : (string) preg_replace('/_id$/', 's', $name),
                ...$this->onDelete($calls),
                ...$flags,
                ...($comment === null ? [] : ['comment' => $comment]),
            ];

            return $table;
        }

        $type = match (true) {
            in_array($method, self::FOREIGN_COLUMNS, true) => 'uuid',
            array_key_exists('unsigned', $calls) && in_array($method, self::SIGNED_INTEGERS, true) => 'unsigned'.ucfirst($method),
            default => $method,
        };

        $column = ['type' => $type];

        if (in_array($type, ['string', 'char'], true) && is_int($arguments[1] ?? null)) {
            $column['length'] = $arguments[1];
        }

        if (in_array($type, ['decimal', 'unsignedDecimal'], true)) {
            $column += array_filter(['precision' => $arguments[1] ?? null, 'scale' => $arguments[2] ?? null], is_int(...));
        }

        $column += $flags;

        if (array_key_exists('default', $calls) && is_scalar($calls['default'][0] ?? null)) {
            $column['default'] = $calls['default'][0];
        }

        if ($comment !== null) {
            $column['comment'] = $comment;
        }

        if (array_key_exists('change', $calls) && isset($table['foreignKeys'][$name])) {
            return $table;
        }

        $table['columns'][$name] = $column;

        return $table;
    }

    /**
     * `$table->foreign('x')->references('id')->on('t')`: the column it names becomes a foreign key.
     *
     * @param  Table  $table
     * @param  list<array{0: string, 1: list<mixed>}>  $modifiers
     * @return Table
     */
    private function longhandForeign(array $table, string $column, array $modifiers): array
    {
        $calls = [];

        foreach ($modifiers as [$modifier, $values]) {
            $calls[$modifier] = $values;
        }

        $parent = $calls['on'][0] ?? null;

        if (! is_string($parent)) {
            return $table;
        }

        $current = $table['columns'][$column] ?? [];
        unset($table['columns'][$column]);
        $table['foreignKeys'][$column] = [
            'table' => $parent,
            ...$this->onDelete($calls),
            ...array_intersect_key($current, ['nullable' => true, 'unique' => true, 'index' => true, 'comment' => true]),
        ];

        return $table;
    }

    /**
     * The delete behaviour a chain of modifiers names, if any.
     *
     * @param  array<string, list<mixed>>  $calls
     * @return array{onDelete?: string}
     */
    private function onDelete(array $calls): array
    {
        foreach (self::ON_DELETE as $stem) {
            if (array_key_exists($stem.'OnDelete', $calls)) {
                return ['onDelete' => $stem];
            }
        }

        $longhand = $calls['onDelete'][0] ?? null;

        return is_string($longhand) && isset(self::ON_DELETE_LONGHAND[strtolower($longhand)]) ? ['onDelete' => self::ON_DELETE_LONGHAND[strtolower($longhand)]] : [];
    }

    /**
     * @param  Table  $table
     * @return Table
     */
    private function dropColumn(array $table, string $column): array
    {
        unset($table['columns'][$column], $table['foreignKeys'][$column]);
        $table['indexes'] = array_values(array_filter($table['indexes'], fn (array $index): bool => ! in_array($column, $index['columns'], true)));

        return $table;
    }

    /**
     * @param  Table  $table
     * @return Table
     */
    private function renameColumn(array $table, string $from, string $to): array
    {
        foreach (['columns', 'foreignKeys'] as $part) {
            if (array_key_exists($from, $table[$part])) {
                $renamed = [];

                foreach ($table[$part] as $name => $definition) {
                    $renamed[$name === $from ? $to : $name] = $definition;
                }

                $table[$part] = $renamed;
            }
        }

        foreach ($table['indexes'] as $i => $index) {
            $table['indexes'][$i]['columns'] = array_map(fn (string $column): string => $column === $from ? $to : $column, $index['columns']);
        }

        return $table;
    }

    /**
     * @param  Table  $table
     * @return Table
     */
    private function dropForeign(array $table, string $column): array
    {
        $key = $table['foreignKeys'][$column] ?? null;

        if ($key === null) {
            return $table;
        }

        unset($table['foreignKeys'][$column]);
        $table['columns'][$column] = ['type' => 'uuid', ...array_intersect_key($key, ['nullable' => true, 'unique' => true, 'index' => true, 'comment' => true])];

        return $table;
    }

    /**
     * Drops an index named by its columns or by the name Laravel gives it.
     *
     * @param  Table  $table
     * @return Table
     */
    private function dropIndex(array $table, string $name, mixed $target, bool $unique): array
    {
        $columns = is_array($target) ? array_values(array_filter($target, is_string(...))) : null;

        if ($columns === null && is_string($target)) {
            foreach ([...array_keys($table['columns']), ...array_keys($table['foreignKeys'])] as $column) {
                if ($target === "{$name}_{$column}_".($unique ? 'unique' : 'index')) {
                    $columns = [$column];
                }
            }

            foreach ($table['indexes'] as $index) {
                if ($target === $name.'_'.implode('_', $index['columns']).'_'.($unique ? 'unique' : 'index')) {
                    $columns = $index['columns'];
                }
            }
        }

        if ($columns === null) {
            return $table;
        }

        $flag = $unique ? 'unique' : 'index';
        $column = count($columns) === 1 ? $columns[0] : null;

        if ($column !== null && isset($table['columns'][$column][$flag])) {
            unset($table['columns'][$column][$flag]);

            return $table;
        }

        if ($column !== null && isset($table['foreignKeys'][$column][$flag])) {
            unset($table['foreignKeys'][$column][$flag]);

            return $table;
        }

        foreach ($table['indexes'] as $i => $index) {
            if ($index['columns'] === $columns && $index['unique'] === $unique) {
                unset($table['indexes'][$i]);
                $table['indexes'] = array_values($table['indexes']);

                return $table;
            }
        }

        return $table;
    }

    /**
     * The column a conventional `{table}_{column}_{suffix}` name stands for.
     *
     * @param  Table  $table
     */
    private function columnOfIndex(array $table, string $name, string $index, string $suffix): ?string
    {
        foreach (array_keys($table['foreignKeys']) as $column) {
            if ($index === "{$name}_{$column}_{$suffix}") {
                return $column;
            }
        }

        return null;
    }

    /**
     * A table in the one form a manifest writes it in: a single-column index or unique index on a
     * column the table lists is a flag on that column, so `->index()` on the column and
     * `$table->index('x')` read the same; the polymorphic pairs are set aside.
     *
     * @param  Table  $table
     * @return Table
     */
    private function settled(string $name, array $table): array
    {
        $indexes = [];

        foreach ($table['indexes'] as $index) {
            $column = count($index['columns']) === 1 ? $index['columns'][0] : null;
            $flag = $index['unique'] ? 'unique' : 'index';

            if ($column !== null && isset($table['foreignKeys'][$column])) {
                $table['foreignKeys'][$column][$flag] = true;
            } elseif ($column !== null && isset($table['columns'][$column])) {
                $table['columns'][$column][$flag] = true;
            } else {
                $indexes[] = $index;
            }
        }

        $table['indexes'] = $indexes;

        foreach ($table['columns'] as $column => $definition) {
            $stem = preg_match('/^(.+)_type$/', $column, $match) === 1 ? $match[1] : null;
            $id = $stem === null ? null : ($table['columns']["{$stem}_id"] ?? null);

            if ($id !== null && $id['type'] === 'uuid' && in_array($definition['type'], ['string', 'char'], true)) {
                unset($table['columns'][$column], $table['columns']["{$stem}_id"]);
                $this->handWritten[$name][] = "{$stem}_type/{$stem}_id";
                $table['indexes'] = array_values(array_filter(
                    $table['indexes'],
                    fn (array $index): bool => array_intersect($index['columns'], [$column, "{$stem}_id"]) === [],
                ));
            }
        }

        foreach ($table['columns'] as $column => $definition) {
            $table['columns'][$column] = self::orderedColumn($definition);
        }

        foreach ($table['foreignKeys'] as $column => $definition) {
            $table['foreignKeys'][$column] = self::orderedForeignKey($definition);
        }

        return $table;
    }

    /**
     * A column's keys in canonical order (StructureFiles::COLUMN_KEYS). A unique index already
     * leads with its column, so a plain index beside it says nothing more.
     *
     * @param  Column  $column
     * @return Column
     */
    private static function orderedColumn(array $column): array
    {
        $ordered = ['type' => $column['type']];

        if (isset($column['length'])) {
            $ordered['length'] = $column['length'];
        }

        if (isset($column['precision'])) {
            $ordered['precision'] = $column['precision'];
        }

        if (isset($column['scale'])) {
            $ordered['scale'] = $column['scale'];
        }

        if (isset($column['enum'])) {
            $ordered['enum'] = $column['enum'];
        }

        if (isset($column['nullable'])) {
            $ordered['nullable'] = true;
        }

        if (isset($column['unique'])) {
            $ordered['unique'] = true;
        } elseif (isset($column['index'])) {
            $ordered['index'] = true;
        }

        if (isset($column['default'])) {
            $ordered['default'] = $column['default'];
        }

        if (isset($column['comment'])) {
            $ordered['comment'] = $column['comment'];
        }

        return $ordered;
    }

    /**
     * A foreign key's keys in canonical order (StructureFiles::FOREIGN_KEY_KEYS).
     *
     * @param  ForeignKey  $key
     * @return ForeignKey
     */
    private static function orderedForeignKey(array $key): array
    {
        $ordered = ['table' => $key['table']];

        if (isset($key['onDelete'])) {
            $ordered['onDelete'] = $key['onDelete'];
        }

        if (isset($key['nullable'])) {
            $ordered['nullable'] = true;
        }

        if (isset($key['unique'])) {
            $ordered['unique'] = true;
        } elseif (isset($key['index'])) {
            $ordered['index'] = true;
        }

        if (isset($key['comment'])) {
            $ordered['comment'] = $key['comment'];
        }

        return $ordered;
    }

    /**
     * The statements of a closure body, each with the comment written right above it and its chain
     * of calls on the Blueprint.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{0: string|null, 1: list<array{0: string, 1: list<mixed>}>}>
     */
    private function statements(array $tokens, int $start, int $end): array
    {
        $statements = [];
        $comment = null;
        $i = $start;

        while ($i < $end) {
            $token = $tokens[$i];

            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $text = self::commentText($token->text);
                $comment = $comment === null || str_starts_with($token->text, '/*') ? $text : $comment.' '.$text;
                $i++;

                continue;
            }

            $stop = $i;
            $depth = 0;

            while ($stop < $end && ! ($depth === 0 && $tokens[$stop]->is(';'))) {
                $depth += match (true) {
                    $tokens[$stop]->is(['(', '[', '{']) => 1,
                    $tokens[$stop]->is([')', ']', '}']) => -1,
                    default => 0,
                };
                $stop++;
            }

            $calls = $token->is(T_VARIABLE) ? $this->calls($tokens, $i + 1, $stop) : [];

            if ($calls !== []) {
                $statements[] = [$comment === '' ? null : $comment, $calls];
            }

            $comment = null;
            $i = $stop + 1;
        }

        return $statements;
    }

    /**
     * A chain of `->name(args)` calls, each with its arguments read as literals.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function calls(array $tokens, int $start, int $end): array
    {
        $calls = [];
        $i = $start;

        while ($i < $end) {
            if (! $tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) || ! ($tokens[$i + 2] ?? null)?->is('(')) {
                return $calls;
            }

            $close = $this->closing($tokens, $i + 2);
            $calls[] = [$tokens[$i + 1]->text, array_map(fn (array $argument): mixed => $this->literal($tokens, $argument), $this->arguments($tokens, $i + 3, $close))];
            $i = $close + 1;
        }

        return $calls;
    }

    /**
     * The token ranges of the arguments between an opening bracket and its closing one.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{0: int, 1: int}>
     */
    private function arguments(array $tokens, int $start, int $end): array
    {
        $arguments = [];
        $from = $start;
        $depth = 0;

        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]->is(['(', '[', '{'])) {
                $depth++;
            } elseif ($tokens[$i]->is([')', ']', '}'])) {
                $depth--;
            } elseif ($depth === 0 && $tokens[$i]->is(',')) {
                $arguments[] = [$from, $i];
                $from = $i + 1;
            }
        }

        if ($from < $end) {
            $arguments[] = [$from, $end];
        }

        return $arguments;
    }

    /**
     * The value of an argument that is a literal: a string, a number, a bool, null, or a list of
     * them. Anything else (a variable, a call, a constant) reads as a value no literal equals.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array{0: int, 1: int}|null  $range
     */
    private function literal(array $tokens, ?array $range): mixed
    {
        if ($range === null) {
            return null;
        }

        [$start, $end] = $range;
        $first = $tokens[$start] ?? null;

        if ($first === null || $start >= $end) {
            return null;
        }

        if ($first->is('[') && $tokens[$end - 1]->is(']')) {
            return array_map(fn (array $item): mixed => $this->literal($tokens, $item), $this->arguments($tokens, $start + 1, $end - 1));
        }

        if ($end - $start === 2 && $first->is('-') && $tokens[$start + 1]->is([T_LNUMBER, T_DNUMBER])) {
            return -$this->number($tokens[$start + 1]->text);
        }

        if ($end - $start !== 1) {
            return new stdClass;
        }

        return match (true) {
            $first->is(T_CONSTANT_ENCAPSED_STRING) => $first->text[0] === "'"
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($first->text, 1, -1))
                : stripcslashes(substr($first->text, 1, -1)),
            $first->is([T_LNUMBER, T_DNUMBER]) => $this->number($first->text),
            $first->is(T_STRING) && in_array(strtolower($first->text), ['true', 'false'], true) => strtolower($first->text) === 'true',
            $first->is(T_STRING) && strtolower($first->text) === 'null' => null,
            default => new stdClass,
        };
    }

    private function number(string $text): int|float
    {
        $text = str_replace('_', '', $text);

        return preg_match('/^\d+$/', $text) === 1 ? (int) $text : (float) $text;
    }

    /**
     * The names a `dropColumn()` takes: one, a list, or several arguments.
     *
     * @param  list<mixed>  $arguments
     * @return list<string>
     */
    private function names(array $arguments): array
    {
        $names = [];

        foreach ($arguments as $argument) {
            foreach (is_array($argument) ? $argument : [$argument] as $name) {
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * The token range of a method's body, between its braces.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{0: int, 1: int}|null
     */
    private function methodBody(array $tokens, string $method): ?array
    {
        foreach ($tokens as $i => $token) {
            if (! $token->is(T_FUNCTION) || ! ($tokens[$i + 1] ?? null)?->is($method)) {
                continue;
            }

            for ($j = $i + 2; $j < count($tokens); $j++) {
                if ($tokens[$j]->is('{')) {
                    return [$j + 1, $this->closing($tokens, $j)];
                }
            }
        }

        return null;
    }

    /**
     * The index of the bracket that closes the one at $open.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function closing(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open; $i < count($tokens); $i++) {
            if ($tokens[$i]->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($tokens[$i]->is([')', ']', '}'])) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return count($tokens);
    }

    /**
     * The tokens that mean something, comments kept, whitespace and the open tag dropped.
     *
     * @param  array<PhpToken>  $tokens
     * @return list<PhpToken>
     */
    private static function significant(array $tokens): array
    {
        return array_values(array_filter($tokens, fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_OPEN_TAG])));
    }

    /**
     * A comment's words on one line, its markers taken off.
     */
    private static function commentText(string $comment): string
    {
        $text = preg_replace(['#^/\*\*?#', '#\*/$#', '#^//#'], '', trim($comment));
        $lines = array_map(fn (string $line): string => trim((string) preg_replace('/^\s*\*/', '', $line)), explode("\n", (string) $text));

        return trim(implode(' ', array_filter($lines, fn (string $line): bool => $line !== '')));
    }
}
