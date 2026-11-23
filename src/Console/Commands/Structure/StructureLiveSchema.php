<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use Throwable;

/**
 * Compares the tables the migrations' source builds (StructureTables) with the schema the database
 * actually holds (structure.md, "Tables"): a migration not run yet, a table or a column the source
 * declares and the database lacks or the other way round, another type, length or nullability,
 * and a foreign key, its delete behaviour or an index only one side has.
 *
 * The type a column should have is the one the connection's own schema grammar writes for the
 * Blueprint method the source names, so each engine is compared in its own words. Defaults and
 * comments are left out, because each engine spells them its own way. Without a database
 * connection nothing is compared, and unreadable() says why, so the source is never shown as if
 * the database had confirmed it.
 *
 * @phpstan-import-type Table from StructureTables
 */
final class StructureLiveSchema
{
    private const string SCRATCH = 'kit_live_schema';

    /**
     * A delete behaviour as the schema builder reads it, by the stem of its `{stem}OnDelete()`.
     */
    private const array ON_DELETE = ['cascade' => 'cascade', 'restrict' => 'restrict', 'set null' => 'null', 'no action' => 'noAction'];

    private ?Connection $resolved = null;

    private ?string $unreadable = null;

    private bool $tried = false;

    /**
     * @var list<string>|null
     */
    private ?array $ran = null;

    /**
     * @var array<string, list<string>>
     */
    private array $differences = [];

    /**
     * @param  (Closure(): Connection)|null  $connection  the connection to read, the default one when null
     */
    public function __construct(
        private readonly StructureTables $tables,
        private readonly ?Closure $connection = null,
    ) {}

    /**
     * Why the database's schema could not be read, or null once it is read.
     */
    public function unreadable(): ?string
    {
        $this->connected();

        return $this->unreadable;
    }

    /**
     * Where the database holds a table another way than its migrations build it, as messages; an
     * empty list when the two agree or the migrations do not build it, and null when the schema
     * could not be read.
     *
     * @return list<string>|null
     */
    public function differences(string $table): ?array
    {
        $connection = $this->connected();

        if ($connection === null) {
            return null;
        }

        if (isset($this->differences[$table])) {
            return $this->differences[$table];
        }

        $built = $this->tables->schema()[$table] ?? null;

        if ($built === null) {
            return $this->differences[$table] = [];
        }

        try {
            $differences = $this->compare($connection, $table, $built);
        } catch (Throwable $exception) {
            $differences = [sprintf('the schema of %s could not be read: %s', $table, self::firstLine($exception))];
        }

        return $this->differences[$table] = $differences;
    }

    private function connected(): ?Connection
    {
        if ($this->tried) {
            return $this->resolved;
        }

        $this->tried = true;

        try {
            $connection = $this->connection === null ? app('db')->connection() : ($this->connection)();
            $connection->getPdo();

            return $this->resolved = $connection;
        } catch (Throwable $exception) {
            $this->unreadable = self::firstLine($exception);

            return null;
        }
    }

    /**
     * @param  Table  $built
     * @return list<string>
     */
    private function compare(Connection $connection, string $table, array $built): array
    {
        $schema = $connection->getSchemaBuilder();
        $pending = array_values(array_diff($this->tables->migrationsOf($table), $this->ran($connection, $schema)));

        if ($pending !== []) {
            return [sprintf(
                'the migration%s %s %s not run yet — run `php artisan migrate`',
                count($pending) === 1 ? '' : 's',
                implode(', ', $pending),
                count($pending) === 1 ? 'has' : 'have',
            )];
        }

        if (! $schema->hasTable($table)) {
            return [sprintf('the database has no table %s, which the migrations build', $table)];
        }

        $handWritten = [];

        foreach ($this->tables->handWritten($table) as $pair) {
            $handWritten = [...$handWritten, ...explode('/', $pair)];
        }

        $live = [];

        foreach ($schema->getColumns($table) as $column) {
            $live[$column['name']] = $column;
        }

        return [
            ...$this->columnDifferences($connection, $built, $live, $handWritten),
            ...$this->foreignKeyDifferences($connection, $built, $schema->getForeignKeys($table), $handWritten),
            ...$this->indexDifferences($connection, $built, $schema->getIndexes($table), $handWritten),
        ];
    }

    /**
     * @param  Table  $built
     * @param  array<string, array{name: string, type: string, type_name: string, nullable: bool}>  $live
     * @param  list<string>  $handWritten
     * @return list<string>
     */
    private function columnDifferences(Connection $connection, array $built, array $live, array $handWritten): array
    {
        $differences = [];
        $declared = ['id' => true];

        $timestamps = $built['timestamps'] ? ['created_at', 'updated_at'] : [];
        $softDeletes = $built['softDeletes'] ? ['deleted_at'] : [];

        foreach ([...$timestamps, ...$softDeletes] as $column) {
            $declared[$column] = true;

            if (! isset($live[$column])) {
                $differences[] = sprintf('the database lacks the column %s, which the migrations declare', $column);
            }
        }

        $columns = $built['columns'];

        foreach ($built['foreignKeys'] as $column => $key) {
            $columns[$column] = ['type' => 'uuid', ...(isset($key['nullable']) ? ['nullable' => true] : [])];
        }

        foreach ($columns as $column => $definition) {
            $column = (string) $column;
            $declared[$column] = true;
            $actual = $live[$column] ?? null;

            if ($actual === null) {
                $differences[] = sprintf('the database lacks the column %s, which the migrations declare', $column);

                continue;
            }

            $expected = $this->expectedType($connection, $definition);
            $type = self::normalized($connection, $actual['type']);

            if ($expected !== null && $expected !== $type) {
                $differences[] = sprintf('the column %s is %s in the database, but the migrations declare %s', $column, $type, $expected);
            }

            if ($actual['nullable'] !== isset($definition['nullable'])) {
                $differences[] = $actual['nullable']
                    ? sprintf('the column %s is nullable in the database, but the migrations declare it not null', $column)
                    : sprintf('the column %s is not null in the database, but the migrations declare it nullable', $column);
            }
        }

        foreach (array_keys($live) as $column) {
            if (! isset($declared[$column]) && ! in_array($column, $handWritten, true)) {
                $differences[] = sprintf('the database has the column %s, which no migration declares', $column);
            }
        }

        return $differences;
    }

    /**
     * @param  Table  $built
     * @param  list<array{columns: list<string>, foreign_table: string, on_delete: string|null}>  $live
     * @param  list<string>  $handWritten
     * @return list<string>
     */
    private function foreignKeyDifferences(Connection $connection, array $built, array $live, array $handWritten): array
    {
        $differences = [];
        $byColumn = [];

        foreach ($live as $key) {
            $columns = $key['columns'];

            if (count($columns) === 1 && isset($built['foreignKeys'][$columns[0]])) {
                $byColumn[$columns[0]] = $key;
            } elseif (array_intersect($columns, $handWritten) === []) {
                $differences[] = sprintf('the database has a foreign key on %s to %s, which no migration declares', implode(', ', $columns), self::bareTable($key['foreign_table']));
            }
        }

        foreach ($built['foreignKeys'] as $column => $key) {
            $actual = $byColumn[$column] ?? null;

            if ($actual === null) {
                $differences[] = sprintf('the database has no foreign key on %s to %s, which the migrations declare', $column, $key['table']);

                continue;
            }

            $parent = self::bareTable($actual['foreign_table']);

            if ($parent !== $connection->getTablePrefix().$key['table'] && $parent !== $key['table']) {
                $differences[] = sprintf('the foreign key on %s points at %s in the database, but the migrations point it at %s', $column, $parent, $key['table']);
            }

            $read = strtolower($actual['on_delete'] ?? 'no action');
            $onDelete = self::ON_DELETE[$read] ?? $read;
            $expected = $key['onDelete'] ?? null;
            $agrees = $expected === null ? in_array($onDelete, ['noAction', 'restrict'], true) : $onDelete === $expected;

            if (! $agrees) {
                $differences[] = sprintf(
                    'the foreign key on %s %s in the database, but the migrations %s',
                    $column,
                    self::deletes($onDelete),
                    $expected === null ? 'choose no delete behaviour' : 'declare '.$expected.'OnDelete()',
                );
            }
        }

        return $differences;
    }

    /**
     * @param  Table  $built
     * @param  list<array{columns: list<string>, unique: bool, primary: bool}>  $live
     * @param  list<string>  $handWritten
     * @return list<string>
     */
    private function indexDifferences(Connection $connection, array $built, array $live, array $handWritten): array
    {
        $expected = [];

        foreach ([...$built['columns'], ...$built['foreignKeys']] as $column => $definition) {
            if (isset($definition['unique'])) {
                $expected[] = ['columns' => [(string) $column], 'unique' => true];
            } elseif (isset($definition['index'])) {
                $expected[] = ['columns' => [(string) $column], 'unique' => false];
            }
        }

        foreach ($built['indexes'] as $index) {
            $expected[] = ['columns' => $index['columns'], 'unique' => $index['unique']];
        }

        $actual = array_values(array_filter(
            $live,
            fn (array $index): bool => ! $index['primary'] && array_intersect($index['columns'], $handWritten) === [],
        ));

        $signature = fn (array $index): string => ($index['unique'] ? 'unique:' : 'index:').implode(',', $index['columns']);
        $expectedSignatures = array_map($signature, $expected);
        $actualSignatures = array_map($signature, $actual);
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $differences = [];

        foreach ($expected as $index) {
            if (! in_array($signature($index), $actualSignatures, true)) {
                $differences[] = sprintf('the database has no %s on (%s), which the migrations declare', $index['unique'] ? 'unique index' : 'index', implode(', ', $index['columns']));
            }
        }

        foreach ($actual as $index) {
            $covered = in_array($signature($index), $expectedSignatures, true)
                // A plain index beside a unique one on the same columns says nothing more.
                || (! $index['unique'] && in_array('unique:'.implode(',', $index['columns']), $expectedSignatures, true))
                // MySQL indexes a foreign key that leads no index by itself.
                || ($mysql && ! $index['unique'] && count($index['columns']) === 1 && isset($built['foreignKeys'][$index['columns'][0]]));

            if (! $covered) {
                $differences[] = sprintf('the database has a %s on (%s), which no migration declares', $index['unique'] ? 'unique index' : 'index', implode(', ', $index['columns']));
            }
        }

        return $differences;
    }

    /**
     * The names of the migrations the database has run.
     *
     * @return list<string>
     */
    private function ran(Connection $connection, Builder $schema): array
    {
        if ($this->ran !== null) {
            return $this->ran;
        }

        $config = config('database.migrations');
        $table = is_array($config) ? ($config['table'] ?? 'migrations') : ($config ?? 'migrations');
        $table = is_string($table) ? $table : 'migrations';

        return $this->ran = $schema->hasTable($table)
            ? array_values(array_map(strval(...), $connection->table($table)->pluck('migration')->all()))
            : [];
    }

    /**
     * The type the connection's grammar writes for a column of the source, in the words
     * normalized() reads the database's in, or null for a Blueprint method it cannot replay.
     *
     * @param  array{type: string, length?: int, precision?: int, scale?: int}  $definition
     */
    private function expectedType(Connection $connection, array $definition): ?string
    {
        $type = $definition['type'];

        if (! method_exists(Blueprint::class, $type)) {
            return null;
        }

        $arguments = match (true) {
            in_array($type, ['string', 'char'], true) => isset($definition['length']) ? [$definition['length']] : [],
            in_array($type, ['decimal', 'unsignedDecimal'], true) => array_values(array_filter([$definition['precision'] ?? null, $definition['scale'] ?? null], is_int(...))),
            default => [],
        };

        try {
            $column = (new Blueprint($connection, self::SCRATCH))->{$type}('column', ...$arguments);

            if (! $column instanceof ColumnDefinition) {
                return null;
            }

            $grammar = $connection->getSchemaGrammar();
            $written = Closure::bind(fn (Fluent $column): string => $this->getType($column), $grammar, Grammar::class)($column);
        } catch (Throwable) {
            return null;
        }

        if ($column->get('unsigned') === true && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $written .= ' unsigned';
        }

        return self::normalized($connection, $written);
    }

    /**
     * A column type in one spelling, whether a grammar wrote it or the database reads it back
     * (`character varying(120)` and `varchar(120)` alike).
     */
    private static function normalized(Connection $connection, string $type): string
    {
        $type = strtolower(trim($type));
        $type = (string) preg_replace('/\s*\(\s*/', '(', $type);
        $type = (string) preg_replace('/\s*,\s*/', ',', $type);
        $type = (string) preg_replace('/\s*\)/', ')', $type);
        $type = (string) preg_replace('/^character varying\b/', 'varchar', $type);
        $type = (string) preg_replace('/^character\b/', 'char', $type);
        $type = (string) preg_replace('/^decimal\b/', 'numeric', $type);

        if ($connection->getDriverName() === 'mariadb') {
            // MariaDB keeps json as longtext with a check.
            $type = (string) preg_replace('/^json$/', 'longtext', $type);
        }

        return $type;
    }

    private static function deletes(string $onDelete): string
    {
        return isset(array_flip(self::ON_DELETE)[$onDelete]) ? "is {$onDelete}OnDelete()" : "does {$onDelete} on delete";
    }

    /**
     * A table a foreign key points at, without the schema some engines put before it.
     */
    private static function bareTable(string $table): string
    {
        return str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;
    }

    private static function firstLine(Throwable $exception): string
    {
        $line = strtok(trim($exception->getMessage()), "\n");
        $line = $line === false ? $exception::class : $line;

        return mb_strlen($line) > 200 ? mb_substr($line, 0, 199).'…' : $line;
    }
}
