<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Closure;

/**
 * The project's structure manifest on disk (structure.md): one `.kit/structure/{Context}.json` per
 * context and one `.kit/structure/http/{Resource}.json` per HTTP resource. It writes the canonical form, every map's keys sorted, four-space indents and a
 * final newline, so a manifest written by hand and one written by `kit:import` diff only where
 * they really differ. It also says what is wrong with a file that has the wrong shape.
 */
final class StructureFiles
{
    public const string DIRECTORY = '.kit/structure';

    public const string HTTP_DIRECTORY = '.kit/structure/http';

    /**
     * The keys of a resource manifest, in the order the canonical form writes them.
     */
    public const array RESOURCE_KEYS = ['resource', 'description', 'model', 'controller', 'actions', 'policy', 'pages', 'menu'];

    /** The key of a main menu group, as `$this->group('{key}', …)` declares it. */
    public const string MENU_GROUP = '/^[a-z][a-z0-9_-]*$/';

    public const array PAGE_KINDS = ['table', 'grid', 'form', 'dialog', 'page'];

    /**
     * Each section of a manifest and the keys and allowed values of one entry in it. A `map` keeps
     * the order it is written in, because the order of an enum's cases and of a value object's
     * constructor means something; `map` holds scalars or null, `map:string` type names.
     * `methods` holds an entity's methods by name, each with its `params` (a `map:string`, in
     * order) and the exceptions it `throws` (a list). `transitions` is null for an enum that is no
     * status, or each case with the cases it may become (states.md). `description` is what the
     * piece is for, in the team's own words: only the manifest holds it (INTENT_KEYS).
     *
     * @var array<string, array<string, list<string|null>|string>>
     */
    public const array SECTIONS = [
        'aggregates' => ['description' => 'string|null', 'children' => 'list', 'repository' => 'bool'],
        'services' => ['description' => 'string|null', 'shape' => ['creates', 'data', 'plain'], 'creates' => 'string|null', 'repositories' => 'list', 'exception' => 'bool', 'replaces' => 'string|null'],
        'applicationServices' => ['description' => 'string|null', 'shape' => ['data', 'plain'], 'repositories' => 'list', 'actor' => 'bool', 'exception' => 'bool', 'replaces' => 'string|null'],
        'ports' => ['description' => 'string|null', 'layer' => ['domain', 'application'], 'adapter' => 'string|null', 'replaces' => 'string|null'],
        'useCases' => ['description' => 'string|null', 'shape' => ['command-result', 'command', 'plain'], 'returns' => 'string', 'creates' => 'bool', 'query' => 'bool', 'repositories' => 'list', 'replaces' => 'string|null'],
        'enums' => ['description' => 'string|null', 'aggregate' => 'string|null', 'backing' => ['string', 'int', null], 'cases' => 'map', 'transitions' => 'transitions'],
        'valueObjects' => ['description' => 'string|null', 'aggregate' => 'string|null', 'fields' => 'map:string'],
        'exceptions' => ['description' => 'string|null', 'kind' => ['refusal', 'value', 'application'], 'aggregate' => 'string|null', 'useCase' => 'string|null'],
        'entities' => ['description' => 'string|null', 'aggregate' => 'string', 'state' => 'map:string', 'behaviours' => 'methods', 'assertions' => 'methods', 'table' => 'table'],
    ];

    /**
     * The section of a context's manifest that holds the tables no entity maps to (a join table, a
     * projection a list reads), keyed by table name. It sits after SECTIONS, and is not one of them,
     * because a table's name is snake_case and its entry is a table, not a piece.
     */
    public const string TABLES = 'tables';

    /**
     * The keys of an entity's `table`, in the order the canonical form writes them.
     */
    public const array TABLE_KEYS = ['name', 'model', 'columns', 'foreignKeys', 'indexes', 'timestamps', 'softDeletes'];

    /**
     * The keys of an entry of `tables`, in the order the canonical form writes them. `model` is null
     * for a table no model maps to.
     */
    public const array CONTEXT_TABLE_KEYS = ['description', 'model', 'columns', 'foreignKeys', 'indexes', 'timestamps', 'softDeletes'];

    /**
     * The keys of a column, in canonical order. `type` is the Blueprint method that builds it
     * (`string`, `unsignedBigInteger`, `jsonb`); `enum` names the enum the model casts it to,
     * written as a state's type is. The rest are left out while they are null or false.
     */
    public const array COLUMN_KEYS = ['type', 'length', 'precision', 'scale', 'enum', 'nullable', 'unique', 'index', 'default', 'comment'];

    /**
     * The keys of a foreign key, in canonical order: the `{x}_id` column of
     * `foreignUuid('{x}_id')->constrained('{table}')`, and the stem of its `{onDelete}OnDelete()`.
     */
    public const array FOREIGN_KEY_KEYS = ['table', 'onDelete', 'nullable', 'unique', 'index', 'comment'];

    /**
     * The keys of an index that spans more than one column, or a column the table does not list.
     */
    public const array INDEX_KEYS = ['columns', 'unique', 'comment'];

    /**
     * The columns a table has without listing them: the uuid key, timestamps() and softDeletes().
     */
    public const array IMPLIED_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** A table's or a column's name. */
    public const string SNAKE_NAME = '/^[a-z][a-z0-9_]*$/';

    /**
     * The shared kernel, whose manifest lists only the enums and value objects every context may
     * use. They sit at its root, so their `aggregate` is null; anywhere else it names one.
     */
    public const string SHARED = 'Shared';

    /**
     * What a manifest written before a section or a key existed holds when nothing else says: no
     * exceptions designed (exceptions.md), no domain service with an exception of its own, no
     * application service, and no state on an entity. The
     * canonical form writes them, so the next save or `kit:import` adds them to the file. `read()`
     * takes them from the code instead, so a project that already has exceptions stays green.
     */
    public const array DEFAULTS = ['exceptions' => [], 'services.exception' => false, 'applicationServices' => [], 'entities.state' => [], 'tables' => []];

    /**
     * Keys only a manifest holds: what the design intends while the code catches up, and what a
     * piece is for. The reader never reports them, the comparison skips them, and a file leaves
     * them out while they are null.
     */
    public const array INTENT_KEYS = ['replaces', 'description'];

    /**
     * The longest description a piece, a context or a resource takes: a few sentences for the
     * team, not documentation of its own.
     */
    public const int DESCRIPTION_LENGTH = 1000;

    /**
     * The property every entity has from `make:entity`, which a base class answers for, so an
     * entity's `state` never lists it.
     */
    public const string ENTITY_ID = 'id';

    /**
     * @param  string|null  $migrations  the folder the migrations live in, `database/migrations` when null
     */
    public function __construct(
        private readonly string $root,
        private readonly ?string $migrations = null,
    ) {}

    public function path(string $context): string
    {
        return $this->root.'/'.self::DIRECTORY.'/'.$context.'.json';
    }

    public function relativePath(string $context): string
    {
        return self::DIRECTORY.'/'.$context.'.json';
    }

    public function exists(string $context): bool
    {
        return is_file($this->path($context));
    }

    /**
     * The contexts that have a manifest, read from the file names.
     *
     * @return list<string>
     */
    public function contexts(): array
    {
        $contexts = array_map(
            fn (string $path): string => basename($path, '.json'),
            glob($this->root.'/'.self::DIRECTORY.'/*.json') ?: [],
        );
        sort($contexts);

        return $contexts;
    }

    /**
     * Every table a valid context manifest designs, by its name: the context, the entity whose
     * `table` it is (null for one under `tables`) with that entity's aggregate, and the design. A
     * table designed twice keeps the first, and the check names the second (StructureComparer).
     *
     * @return array<string, array{context: string, entity: string|null, aggregate: string|null, table: array<string, mixed>}>
     */
    public function designedTables(): array
    {
        $designed = [];

        foreach ($this->contexts() as $context) {
            $manifest = $this->read($context);

            if (! is_array($manifest) || $this->problems($context, $manifest) !== []) {
                continue;
            }

            foreach ($manifest['entities'] as $entity => $entry) {
                if (is_array($entry['table'] ?? null) && is_string($entry['table']['name'] ?? null)) {
                    $designed[$entry['table']['name']] ??= ['context' => $context, 'entity' => (string) $entity, 'aggregate' => $entry['aggregate'], 'table' => $entry['table']];
                }
            }

            foreach ($manifest[self::TABLES] as $table => $entry) {
                $designed[(string) $table] ??= ['context' => $context, 'entity' => null, 'aggregate' => null, 'table' => $entry];
            }
        }

        return $designed;
    }

    /**
     * The decoded manifest, or null when the file is not JSON.
     *
     * A file written before exceptions were designed reads what it predates from the code: the
     * exceptions the context holds, and whether each service has one. Before, the design said
     * nothing about them, so what is built is what was meant, and the comparison stays as it was.
     */
    public function read(string $context): mixed
    {
        $document = json_decode($this->contents($this->path($context)), true);

        if (! is_array($document)) {
            return $document;
        }

        return self::withDefaults($this->upgraded($context, $document));
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>
     */
    private function upgraded(string $context, array $document): array
    {
        $predates = ! array_key_exists('exceptions', $document) || ! array_key_exists(self::TABLES, $document);
        $services = is_array($document['services'] ?? null) ? $document['services'] : [];

        foreach ($services as $service) {
            $predates = $predates || (is_array($service) && ! array_key_exists('exception', $service));
        }

        if (! $predates) {
            return $document;
        }

        $built = (new StructureReader($this->root, $this->migrations))->read($context);

        if (! array_key_exists('exceptions', $document)) {
            $document['exceptions'] = $built['exceptions'];
        }

        // Before tables were designed, the manifest said nothing about them: what is built is what was meant.
        if (! array_key_exists(self::TABLES, $document)) {
            $document[self::TABLES] = $built[self::TABLES];

            foreach ($built['entities'] as $name => $entity) {
                if (($entity['table'] ?? null) === null || (is_array($document['entities'] ?? null) && is_array($document['entities'][$name] ?? null) && array_key_exists('table', $document['entities'][$name]))) {
                    continue;
                }

                if (! is_array($document['entities'] ?? null)) {
                    $document['entities'] = [];
                }

                $document['entities'][$name] = is_array($document['entities'][$name] ?? null)
                    ? [...$document['entities'][$name], 'table' => $entity['table']]
                    : ['aggregate' => $entity['aggregate'], 'state' => [], 'behaviours' => [], 'assertions' => [], 'table' => $entity['table']];
            }
        }

        foreach ($services as $name => $service) {
            if (is_array($service) && ! array_key_exists('exception', $service)) {
                $document['services'][$name]['exception'] = $built['services'][$name]['exception'] ?? false;
            }
        }

        return $document;
    }

    /**
     * A manifest with what DEFAULTS fills in where the file predates it.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>
     */
    public static function withDefaults(array $document): array
    {
        if (! array_key_exists('exceptions', $document)) {
            $document['exceptions'] = self::DEFAULTS['exceptions'];
        }

        if (! array_key_exists('applicationServices', $document)) {
            $document['applicationServices'] = self::DEFAULTS['applicationServices'];
        }

        if (! array_key_exists(self::TABLES, $document)) {
            $document[self::TABLES] = self::DEFAULTS['tables'];
        }

        if (is_array($document['services'] ?? null)) {
            foreach ($document['services'] as $name => $service) {
                if (is_array($service) && ! array_key_exists('exception', $service)) {
                    $document['services'][$name]['exception'] = self::DEFAULTS['services.exception'];
                }
            }
        }

        if (is_array($document['entities'] ?? null)) {
            foreach ($document['entities'] as $name => $entity) {
                if (is_array($entity) && ! array_key_exists('state', $entity)) {
                    $document['entities'][$name]['state'] = self::DEFAULTS['entities.state'];
                }
            }
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function write(array $manifest): void
    {
        $context = (string) $manifest['context'];
        $directory = dirname($this->path($context));

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->path($context), $this->encode($manifest));
    }

    /**
     * A fingerprint of the manifest as it is on disk, empty when there is none. A screen sends back
     * the one it loaded, so a save never overwrites a change made since.
     */
    public function version(string $context): string
    {
        return $this->exists($context) ? sha1($this->contents($this->path($context))) : '';
    }

    /**
     * The canonical text of a manifest: the sections in schema order, every section's keys sorted,
     * every list sorted, an enum's cases and a value object's fields in the order they were written,
     * and an empty map written `{}`, never `[]`.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function encode(array $manifest): string
    {
        $manifest = self::withDefaults($manifest);
        $document = ['context' => $manifest['context']];

        if (is_string($manifest['description'] ?? null)) {
            $document['description'] = $manifest['description'];
        }

        foreach (array_keys(self::SECTIONS) as $section) {
            /** @var array<string, array<string, mixed>> $entries */
            $entries = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];
            ksort($entries);

            $document[$section] = (object) array_map(function (array $entry) use ($section): object {
                $ordered = [];

                foreach (self::SECTIONS[$section] as $key => $kind) {
                    $value = $entry[$key] ?? null;

                    if ($value === null && (in_array($key, self::INTENT_KEYS, true) || $kind === 'table')) {
                        continue;
                    }

                    if (is_array($value) && $kind === 'table') {
                        $value = self::encodeTable(self::canonicalTable($value, self::TABLE_KEYS));
                    } elseif (is_array($value) && $kind === 'methods') {
                        $value = self::encodeMethods($value);
                    } elseif (is_array($value) && $kind === 'transitions') {
                        $value = (object) self::orderedTransitions($value, is_array($entry['cases'] ?? null) ? $entry['cases'] : []);
                    } elseif (is_array($value) && self::isMap($kind)) {
                        $value = (object) $value;
                    } elseif (is_array($value)) {
                        sort($value);
                    }

                    $ordered[$key] = $value;
                }

                return (object) $ordered;
            }, $entries);
        }

        /** @var array<string, array<string, mixed>> $tables */
        $tables = is_array($manifest[self::TABLES] ?? null) ? $manifest[self::TABLES] : [];
        ksort($tables);
        $document[self::TABLES] = (object) array_map(fn (array $table): object => self::encodeTable(self::canonicalTable($table, self::CONTEXT_TABLE_KEYS)), $tables);

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * A table in canonical form: its keys in the order given, each column's and foreign key's keys
     * in theirs with the ones that say nothing (null, false) left out, columns and foreign keys in
     * the order the migration builds them, and indexes in the order it adds them. A description is
     * left out while it is null. A key the shape does not know is kept at the end, for problems()
     * to name. Both the file and the comparison read a table through it, so `"nullable": false` and
     * no `nullable` at all are the same table.
     *
     * @param  array<array-key, mixed>  $table
     * @param  list<string>  $keys  TABLE_KEYS or CONTEXT_TABLE_KEYS
     * @return array<string, mixed>
     */
    public static function canonicalTable(array $table, array $keys): array
    {
        $canonical = [];

        foreach ($keys as $key) {
            $value = $table[$key] ?? null;

            if ($key === 'description' && $value === null) {
                continue;
            }

            $canonical[$key] = match ($key) {
                'columns' => is_array($value) ? array_map(fn (mixed $column): mixed => is_array($column) ? self::orderedKeys($column, self::COLUMN_KEYS) : $column, $value) : $value,
                'foreignKeys' => is_array($value) ? array_map(fn (mixed $key): mixed => is_array($key) ? self::orderedKeys($key, self::FOREIGN_KEY_KEYS) : $key, $value) : $value,
                'indexes' => is_array($value) ? array_map(fn (mixed $index): mixed => is_array($index)
                    ? ['columns' => $index['columns'] ?? null, 'unique' => $index['unique'] ?? false, ...self::orderedKeys(array_diff_key($index, ['columns' => true, 'unique' => true]), self::INDEX_KEYS)]
                    : $index, $value) : $value,
                default => $value,
            };
        }

        foreach (array_diff_key($table, array_flip($keys)) as $key => $value) {
            $canonical[(string) $key] = $value;
        }

        return $canonical;
    }

    /**
     * An entry's keys in the order given, the ones that are null or false left out, and any other
     * key after them. A column's `default` is a value, not a flag, so a default of false stays.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function orderedKeys(array $entry, array $keys): array
    {
        $ordered = [];

        foreach ([...$keys, ...array_diff(array_map(strval(...), array_keys($entry)), $keys)] as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] !== null && ($entry[$key] !== false || $key === 'default')) {
                $ordered[$key] = $entry[$key];
            }
        }

        return $ordered;
    }

    /**
     * A canonical table as JSON writes it, its maps as objects even when empty.
     *
     * @param  array<string, mixed>  $table
     */
    private static function encodeTable(array $table): object
    {
        foreach (['columns', 'foreignKeys'] as $key) {
            if (is_array($table[$key] ?? null)) {
                $table[$key] = (object) array_map(fn (mixed $entry): mixed => is_array($entry) ? (object) $entry : $entry, $table[$key]);
            }
        }

        if (is_array($table['indexes'] ?? null)) {
            $table['indexes'] = array_values(array_map(fn (mixed $index): mixed => is_array($index) ? (object) $index : $index, $table['indexes']));
        }

        return (object) $table;
    }

    /**
     * A status's transitions in the order of its cases, both the cases that move and the cases each
     * one may become, so the manifest does not depend on the order a `match` lists them in. A case
     * the enum does not have keeps its place after the others, for the shape check to name.
     *
     * @param  array<array-key, mixed>  $transitions
     * @param  array<array-key, mixed>  $cases
     * @return array<string, list<string>>
     */
    public static function orderedTransitions(array $transitions, array $cases): array
    {
        $rank = array_flip(array_map(strval(...), array_keys($cases)));
        $byRank = fn (string $a, string $b): int => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b);
        $ordered = [];

        foreach ($transitions as $case => $next) {
            $next = is_array($next) ? array_map(strval(...), array_values($next)) : [];
            usort($next, $byRank);
            $ordered[(string) $case] = $next;
        }

        uksort($ordered, $byRank);

        return $ordered;
    }

    /**
     * An entity's methods in canonical form: sorted by name, each with its parameters in the order
     * the method declares them and its exceptions sorted.
     *
     * @param  array<array-key, mixed>  $methods
     */
    private static function encodeMethods(array $methods): object
    {
        ksort($methods);

        return (object) array_map(function (mixed $method): object {
            $method = is_array($method) ? $method : [];
            $throws = is_array($method['throws'] ?? null) ? $method['throws'] : [];
            sort($throws);

            return (object) ['params' => (object) (is_array($method['params'] ?? null) ? $method['params'] : []), 'throws' => $throws];
        }, $methods);
    }

    /**
     * What is wrong with a manifest's shape, one line per fault. Empty when it is fine.
     *
     * @return list<string>
     */
    public function problems(string $context, mixed $document): array
    {
        if (! is_array($document)) {
            return ['is not a JSON object'];
        }

        $document = self::withDefaults($document);
        $problems = [];

        if (($document['context'] ?? null) !== $context) {
            $problems[] = sprintf('says "context": %s, but the file is named %s.json', json_encode($document['context'] ?? null), $context);
        }

        foreach (array_diff(array_keys($document), ['context', 'description', ...array_keys(self::SECTIONS), self::TABLES]) as $key) {
            $problems[] = sprintf('has an unknown key "%s"', $key);
        }

        if (! self::isDescription($document['description'] ?? null)) {
            $problems[] = sprintf('"description" must be text of at most %d characters, or left out', self::DESCRIPTION_LENGTH);
        }

        foreach (self::SECTIONS as $section => $keys) {
            $entries = $document[$section] ?? null;

            if (! is_array($entries)) {
                $problems[] = sprintf('has no "%s" object', $section);

                continue;
            }

            foreach ($entries as $name => $entry) {
                $faults = $this->entryProblems($section, (string) $name, $entry, $keys);
                $problems = [...$problems, ...$faults];

                if ($faults === [] && is_array($entry) && in_array($section, ['enums', 'valueObjects'], true)) {
                    $problems = [...$problems, ...$this->vocabularyProblems($context, $section, (string) $name, $entry)];
                }

                if ($faults === [] && is_array($entry) && $section === 'exceptions') {
                    $problems = [...$problems, ...$this->exceptionProblems($context, (string) $name, $entry)];
                }

                if ($faults === [] && is_array($entry) && $section === 'entities') {
                    $problems = [...$problems, ...$this->entityProblems($context, (string) $name, $entry, $document['aggregates'] ?? null)];
                }
            }
        }

        $tables = $document[self::TABLES];

        if (! is_array($tables)) {
            $problems[] = sprintf('has no "%s" object', self::TABLES);
        } elseif ($context === self::SHARED && $tables !== []) {
            $problems[] = sprintf('"%s" must be empty, because the shared kernel has no tables', self::TABLES);
        } else {
            foreach ($tables as $name => $table) {
                $problems = [...$problems, ...self::tableProblems(self::TABLES.'.'.$name, (string) $name, $table, self::CONTEXT_TABLE_KEYS)];
            }
        }

        return $problems;
    }

    /**
     * What is wrong with the shape of a table, one line per fault, each starting with where it
     * sits (`tables.{name}` or `entities.{Entity}.table`). Only the shape: which delete behaviour a
     * foreign key should have, or whether a nullable column says why, is a design rule the editor
     * holds (migrations.md), so a table read back from a migration that breaks one still reads.
     *
     * @param  list<string>  $keys  TABLE_KEYS or CONTEXT_TABLE_KEYS
     * @return list<string>
     */
    public static function tableProblems(string $at, ?string $name, mixed $table, array $keys): array
    {
        if (! is_array($table)) {
            return [sprintf('%s: is not an object', $at)];
        }

        $problems = [];
        $name ??= is_string($table['name'] ?? null) ? $table['name'] : null;

        foreach (array_diff(array_map(strval(...), array_keys($table)), $keys) as $key) {
            $problems[] = sprintf('%s: has an unknown key "%s"', $at, $key);
        }

        if ($name === null || preg_match(self::SNAKE_NAME, $name) !== 1) {
            $problems[] = sprintf('%s: %s must be a snake_case table name', $at, in_array('name', $keys, true) ? '"name"' : 'the key');
        }

        if (in_array('description', $keys, true) && ! self::isDescription($table['description'] ?? null)) {
            $problems[] = sprintf('%s: "description" must be text of at most %d characters, or null', $at, self::DESCRIPTION_LENGTH);
        }

        $model = $table['model'] ?? null;

        if (! (($model === null && ! in_array('name', $keys, true)) || (is_string($model) && preg_match('/^[A-Z][A-Za-z0-9]*$/', $model) === 1))) {
            $problems[] = sprintf('%s: "model" must name a class of app/Models%s', $at, in_array('name', $keys, true) ? '' : ', or be null');
        }

        foreach (['timestamps', 'softDeletes'] as $flag) {
            if (! is_bool($table[$flag] ?? null)) {
                $problems[] = sprintf('%s: "%s" must be true or false', $at, $flag);
            }
        }

        $columns = $table['columns'] ?? null;
        $foreignKeys = $table['foreignKeys'] ?? null;

        if (! is_array($columns) || ($columns !== [] && array_is_list($columns))) {
            $problems[] = sprintf('%s: "columns" must be an object of column names', $at);
            $columns = [];
        }

        if (! is_array($foreignKeys) || ($foreignKeys !== [] && array_is_list($foreignKeys))) {
            $problems[] = sprintf('%s: "foreignKeys" must be an object of column names', $at);
            $foreignKeys = [];
        }

        foreach ([...array_keys($columns), ...array_keys($foreignKeys)] as $column) {
            if (preg_match(self::SNAKE_NAME, (string) $column) !== 1) {
                $problems[] = sprintf('%s: %s is not a snake_case column name', $at, $column);
            } elseif (in_array($column, self::IMPLIED_COLUMNS, true)) {
                $problems[] = sprintf('%s: lists %s, which the uuid key, timestamps or softDeletes already add — leave it out', $at, $column);
            }
        }

        foreach (array_keys(array_intersect_key($columns, $foreignKeys)) as $column) {
            $problems[] = sprintf('%s: %s is both a column and a foreign key — a foreign key is its own column', $at, $column);
        }

        foreach ($columns as $column => $definition) {
            $problems = [...$problems, ...self::columnProblems("{$at}.columns.{$column}", $definition)];
        }

        foreach ($foreignKeys as $column => $definition) {
            $problems = [...$problems, ...self::foreignKeyProblems("{$at}.foreignKeys.{$column}", $definition)];
        }

        $indexes = $table['indexes'] ?? null;

        if (! is_array($indexes) || ! array_is_list($indexes)) {
            return [...$problems, sprintf('%s: "indexes" must be a list', $at)];
        }

        $known = [...array_map(strval(...), array_keys($columns)), ...array_map(strval(...), array_keys($foreignKeys)), 'id'];

        if (($table['timestamps'] ?? false) === true) {
            $known = [...$known, 'created_at', 'updated_at'];
        }

        if (($table['softDeletes'] ?? false) === true) {
            $known[] = 'deleted_at';
        }

        foreach ($indexes as $i => $index) {
            $listed = is_array($index) ? ($index['columns'] ?? null) : null;

            if (! is_array($index) || array_diff(array_map(strval(...), array_keys($index)), self::INDEX_KEYS) !== [] || ! is_bool($index['unique'] ?? null) || ! self::isNames($listed) || $listed === [] || ! self::isComment($index['comment'] ?? null)) {
                $problems[] = sprintf('%s.indexes[%d]: must be {"columns": [names], "unique": true or false}, with an optional "comment"', $at, $i);

                continue;
            }

            foreach (array_diff($listed, $known) as $column) {
                $problems[] = sprintf('%s.indexes[%d]: names %s, which is no column of the table', $at, $i, $column);
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function columnProblems(string $at, mixed $column): array
    {
        if (! is_array($column)) {
            return [sprintf('%s: is not an object', $at)];
        }

        $problems = [];

        foreach (array_diff(array_map(strval(...), array_keys($column)), self::COLUMN_KEYS) as $key) {
            $problems[] = sprintf('%s: has an unknown key "%s"', $at, $key);
        }

        if (! is_string($column['type'] ?? null) || preg_match('/^[a-z][A-Za-z]*$/', $column['type']) !== 1) {
            $problems[] = sprintf('%s: "type" must be the Blueprint method that builds it, such as string or unsignedBigInteger', $at);
        }

        foreach (['length', 'precision', 'scale'] as $size) {
            if (isset($column[$size]) && (! is_int($column[$size]) || $column[$size] < 0)) {
                $problems[] = sprintf('%s: "%s" must be a whole number', $at, $size);
            }
        }

        if (isset($column['enum']) && (! is_string($column['enum']) || $column['enum'] === '')) {
            $problems[] = sprintf('%s: "enum" must name the enum the model casts it to', $at);
        }

        foreach (['nullable', 'unique', 'index'] as $flag) {
            if (isset($column[$flag]) && ! is_bool($column[$flag])) {
                $problems[] = sprintf('%s: "%s" must be true or false', $at, $flag);
            }
        }

        if (isset($column['default']) && ! is_scalar($column['default'])) {
            $problems[] = sprintf('%s: "default" must be a string, a number or true or false', $at);
        }

        if (! self::isComment($column['comment'] ?? null)) {
            $problems[] = sprintf('%s: "comment" must be text', $at);
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function foreignKeyProblems(string $at, mixed $key): array
    {
        if (! is_array($key)) {
            return [sprintf('%s: is not an object', $at)];
        }

        $problems = [];

        foreach (array_diff(array_map(strval(...), array_keys($key)), self::FOREIGN_KEY_KEYS) as $unknown) {
            $problems[] = sprintf('%s: has an unknown key "%s"', $at, $unknown);
        }

        if (! is_string($key['table'] ?? null) || preg_match(self::SNAKE_NAME, $key['table']) !== 1) {
            $problems[] = sprintf('%s: "table" must name the parent table', $at);
        }

        if (isset($key['onDelete']) && ! in_array($key['onDelete'], StructureTables::ON_DELETE, true)) {
            $problems[] = sprintf('%s: "onDelete" must be one of %s', $at, implode(', ', StructureTables::ON_DELETE));
        }

        foreach (['nullable', 'unique', 'index'] as $flag) {
            if (isset($key[$flag]) && ! is_bool($key[$flag])) {
                $problems[] = sprintf('%s: "%s" must be true or false', $at, $flag);
            }
        }

        if (! self::isComment($key['comment'] ?? null)) {
            $problems[] = sprintf('%s: "comment" must be text', $at);
        }

        return $problems;
    }

    private static function isComment(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) !== '');
    }

    private static function isNames(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_string($item)) === [];
    }

    /**
     * Whether a key of a section holds a map, whose order the canonical form keeps.
     *
     * @param  list<string|null>|string  $kind
     */
    public static function isMap(array|string $kind): bool
    {
        return is_string($kind) && str_starts_with($kind, 'map');
    }

    /**
     * Whether a value fits a `description`: none, or text that is not blank and not too long.
     */
    public static function isDescription(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) !== '' && mb_strlen($value) <= self::DESCRIPTION_LENGTH);
    }

    /**
     * Whether an entity's entry says nothing at all, so the manifest leaves it out (structure.md): no
     * state, no method, no description and no table.
     *
     * @param  array<array-key, mixed>  $entry
     */
    public static function listsNothing(array $entry): bool
    {
        return ($entry['state'] ?? []) === [] && ($entry['behaviours'] ?? []) === [] && ($entry['assertions'] ?? []) === [] && ($entry['description'] ?? null) === null && ($entry['table'] ?? null) === null;
    }

    /**
     * What an enum or a value object says that its keys alone do not: where it lives, and cases that
     * match the enum's backing.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function vocabularyProblems(string $context, string $section, string $name, array $entry): array
    {
        $problems = [];

        if ($context === self::SHARED && $entry['aggregate'] !== null) {
            $problems[] = sprintf('%s.%s: "aggregate" must be null, because the shared kernel has no aggregates', $section, $name);
        } elseif ($context !== self::SHARED && preg_match('/^[A-Z][A-Za-z0-9]*$/', (string) $entry['aggregate']) !== 1) {
            $problems[] = sprintf('%s.%s: "aggregate" must name the aggregate whose folder holds it', $section, $name);
        }

        if ($section === 'enums') {
            /** @var array<string, mixed> $cases */
            $cases = $entry['cases'];

            foreach ($cases as $case => $value) {
                $fits = match ($entry['backing']) {
                    'string' => is_string($value) && $value !== '',
                    'int' => is_int($value),
                    default => $value === null,
                };

                if (! $fits) {
                    $problems[] = sprintf('enums.%s: "cases" holds %s, which must be %s', $name, $case, match ($entry['backing']) {
                        'string' => 'a string',
                        'int' => 'an int',
                        default => 'null, because the enum is not backed',
                    });
                }
            }

            if (is_array($entry['transitions'] ?? null)) {
                $problems = [...$problems, ...$this->transitionProblems($name, array_map(strval(...), array_keys($cases)), $entry['transitions'])];
            }
        }

        return $problems;
    }

    /**
     * What an exception says that its keys alone do not (exceptions.md): its name, and that its kind
     * and its home agree. A refusal or an invalid value lives in an aggregate, an application
     * refusal in the context or one of its use cases, and the shared kernel holds invalid values
     * only, at its root.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function exceptionProblems(string $context, string $name, array $entry): array
    {
        $problems = [];
        $studly = fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Z][A-Za-z0-9]*$/', $value) === 1;

        if (! str_ends_with($name, 'Exception') || $name === 'Exception') {
            $problems[] = sprintf('exceptions.%s: a name ends with Exception', $name);
        }

        if ($context === self::SHARED) {
            if ($entry['kind'] !== 'value') {
                $problems[] = sprintf('exceptions.%s: the shared kernel holds invalid values only, so "kind" must be value', $name);
            }

            if ($entry['aggregate'] !== null || $entry['useCase'] !== null) {
                $problems[] = sprintf('exceptions.%s: "aggregate" and "useCase" must be null, because the shared kernel has neither', $name);
            }

            return $problems;
        }

        if ($entry['kind'] === 'application') {
            if ($entry['aggregate'] !== null) {
                $problems[] = sprintf('exceptions.%s: "aggregate" must be null, because a use case\'s refusal lives in the application', $name);
            }

            if ($entry['useCase'] !== null && ! $studly($entry['useCase'])) {
                $problems[] = sprintf('exceptions.%s: "useCase" must be null or name a use case of %s', $name, $context);
            }

            return $problems;
        }

        if (! $studly($entry['aggregate'])) {
            $problems[] = sprintf('exceptions.%s: "aggregate" must name the aggregate whose Exceptions folder holds it', $name);
        }

        if ($entry['useCase'] !== null) {
            $problems[] = sprintf('exceptions.%s: "useCase" must be null, because only a use case\'s refusal belongs to one', $name);
        }

        return $problems;
    }

    /**
     * What a status's transitions say that their shape alone does not: every case is listed once,
     * and each goes only to other cases of the enum (states.md).
     *
     * @param  list<string>  $cases
     * @param  array<array-key, mixed>  $transitions
     * @return list<string>
     */
    private function transitionProblems(string $name, array $cases, array $transitions): array
    {
        $problems = [];
        $listed = array_map(strval(...), array_keys($transitions));

        foreach (array_diff($cases, $listed) as $case) {
            $problems[] = sprintf('enums.%s: "transitions" leaves out %s — list every case, a final one with []', $name, $case);
        }

        foreach ($transitions as $case => $next) {
            $case = (string) $case;

            if (! in_array($case, $cases, true)) {
                $problems[] = sprintf('enums.%s: "transitions" lists %s, which is not one of its cases', $name, $case);

                continue;
            }

            $next = is_array($next) ? array_map(strval(...), array_values($next)) : [];

            foreach (array_diff($next, $cases) as $target) {
                $problems[] = sprintf('enums.%s: "transitions" lets %s become %s, which is not one of its cases', $name, $case, $target);
            }

            if (in_array($case, $next, true)) {
                $problems[] = sprintf('enums.%s: "transitions" lets %s become itself — staying put is no change', $name, $case);
            }

            if (count($next) !== count(array_unique($next))) {
                $problems[] = sprintf('enums.%s: "transitions" names a case %s may become twice', $name, $case);
            }
        }

        return $problems;
    }

    /**
     * What an entity's entry says that its keys alone do not: that it is the root or a child of the
     * aggregate it names, that it lists something, that its state names camelCase properties other
     * than the `id` every entity already has, and that each method sits in the right group.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function entityProblems(string $context, string $name, array $entry, mixed $aggregates): array
    {
        if ($context === self::SHARED) {
            return [sprintf('entities.%s: the shared kernel has no entities', $name)];
        }

        $problems = [];
        $holder = is_array($aggregates) ? ($aggregates[$entry['aggregate']] ?? null) : null;

        if (! is_array($holder) || ($name !== $entry['aggregate'] && ! in_array($name, is_array($holder['children'] ?? null) ? $holder['children'] : [], true))) {
            $problems[] = sprintf('entities.%s: "aggregate" must name the aggregate whose root or child it is', $name);
        }

        if (self::listsNothing($entry)) {
            $problems[] = sprintf('entities.%s: lists no state, no behaviour, no assertion, no description and no table, so leave it out', $name);
        }

        if (($entry['table'] ?? null) !== null) {
            $problems = [...$problems, ...self::tableProblems("entities.{$name}.table", null, $entry['table'], self::TABLE_KEYS)];
        }

        foreach (array_keys($entry['state']) as $property) {
            if (preg_match('/^[a-z][A-Za-z0-9]*$/', (string) $property) !== 1) {
                $problems[] = sprintf('entities.%s: the state %s is not a camelCase name', $name, $property);
            } elseif ($property === self::ENTITY_ID) {
                $problems[] = sprintf('entities.%s: "state" holds id, which every entity already has — leave it out', $name);
            }
        }

        foreach (['behaviours' => false, 'assertions' => true] as $group => $assertion) {
            /** @var array<string, mixed> $methods */
            $methods = $entry[$group];

            foreach (array_keys($methods) as $method) {
                if (StructureReader::isAssertion((string) $method) !== $assertion) {
                    $problems[] = sprintf($assertion
                        ? 'entities.%s: "assertions" holds %s, whose name must start with assert'
                        : 'entities.%s: "behaviours" holds %s, which is named like an assertion — list it under "assertions"', $name, $method);
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, list<string|null>|string>  $keys
     * @return list<string>
     */
    private function entryProblems(string $section, string $name, mixed $entry, array $keys): array
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            return [sprintf('%s.%s: a name is StudlyCase', $section, $name)];
        }

        if (! is_array($entry)) {
            return [sprintf('%s.%s: is not an object', $section, $name)];
        }

        $problems = [];

        foreach (array_diff(array_keys($entry), array_keys($keys)) as $key) {
            $problems[] = sprintf('%s.%s: has an unknown key "%s"', $section, $name, $key);
        }

        foreach ($keys as $key => $kind) {
            $value = $entry[$key] ?? null;

            if ($key === 'description') {
                if (! self::isDescription($value)) {
                    $problems[] = sprintf('%s.%s: "description" must be text of at most %d characters, or null', $section, $name, self::DESCRIPTION_LENGTH);
                }

                continue;
            }

            $fits = match (true) {
                is_array($kind) => in_array($value, $kind, true),
                $kind === 'list' => is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_string($item)) === [],
                $kind === 'bool' => is_bool($value),
                $kind === 'string|null' => $value === null || is_string($value),
                $kind === 'map' => $this->isMapOf($value, fn (mixed $item): bool => $item === null || is_string($item) || is_int($item)),
                $kind === 'map:string' => $this->isMapOf($value, fn (mixed $item): bool => is_string($item) && $item !== ''),
                $kind === 'methods' => $this->isMethods($value),
                $kind === 'table' => $value === null || is_array($value),
                $kind === 'transitions' => $value === null || $this->isMapOf($value, fn (mixed $next): bool => is_array($next) && array_is_list($next) && array_filter($next, fn (mixed $case): bool => ! is_string($case)) === []),
                default => is_string($value) && $value !== '',
            };

            if (! $fits) {
                $problems[] = sprintf(
                    '%s.%s: "%s" must be %s',
                    $section,
                    $name,
                    $key,
                    match (true) {
                        is_array($kind) => 'one of '.implode(', ', array_map(fn (?string $option): string => $option ?? 'null', $kind)),
                        $kind === 'map' => 'an object of names to a string, an int or null',
                        $kind === 'map:string' => 'an object of names to a type',
                        $kind === 'methods' => 'an object of camelCase method names, each with "params" (names to a type) and "throws" (a list of exceptions)',
                        $kind === 'table' => 'the table the entity maps to, or left out',
                        $kind === 'transitions' => 'null, or an object of each case to the list of cases it may become',
                        default => $kind,
                    },
                );
            }
        }

        return $problems;
    }

    public function resourcePath(string $resource): string
    {
        return $this->root.'/'.self::HTTP_DIRECTORY.'/'.$resource.'.json';
    }

    public function relativeResourcePath(string $resource): string
    {
        return self::HTTP_DIRECTORY.'/'.$resource.'.json';
    }

    public function resourceExists(string $resource): bool
    {
        return is_file($this->resourcePath($resource));
    }

    /**
     * The resources that have a manifest, read from the file names.
     *
     * @return list<string>
     */
    public function resources(): array
    {
        $resources = array_map(
            fn (string $path): string => basename($path, '.json'),
            glob($this->root.'/'.self::HTTP_DIRECTORY.'/*.json') ?: [],
        );
        sort($resources);

        return $resources;
    }

    /**
     * A fingerprint of the resource manifest as it is on disk, empty when there is none.
     */
    public function resourceVersion(string $resource): string
    {
        return $this->resourceExists($resource) ? sha1($this->contents($this->resourcePath($resource))) : '';
    }

    /**
     * The decoded resource manifest, or null when the file is not JSON.
     */
    public function readResource(string $resource): mixed
    {
        return json_decode($this->contents($this->resourcePath($resource)), true);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function writeResource(array $manifest): void
    {
        $resource = (string) $manifest['resource'];
        $directory = dirname($this->resourcePath($resource));

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->resourcePath($resource), $this->encodeResource($manifest));
    }

    /**
     * The canonical text of a resource manifest: its keys in schema order, every map's keys and
     * every list sorted, an empty map written `{}`.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function encodeResource(array $manifest): string
    {
        $controller = is_array($manifest['controller'] ?? null) ? $manifest['controller'] : [];
        ksort($controller);

        foreach ($controller as $method => $useCases) {
            $useCases = is_array($useCases) ? $useCases : [];
            sort($useCases);
            $controller[$method] = $useCases;
        }

        $actions = is_array($manifest['actions'] ?? null) ? $manifest['actions'] : [];
        ksort($actions);

        foreach ($actions as $verb => $action) {
            $useCases = is_array($action['useCases'] ?? null) ? $action['useCases'] : [];
            sort($useCases);
            $actions[$verb] = (object) ['row' => $action['row'] ?? null, 'bulk' => $action['bulk'] ?? null, 'useCases' => $useCases];
        }

        $policy = $manifest['policy'] ?? null;

        if (is_array($policy)) {
            sort($policy);
        }

        $pages = is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [];
        ksort($pages);

        $document = [
            'resource' => $manifest['resource'],
            ...(is_string($manifest['description'] ?? null) ? ['description' => $manifest['description']] : []),
            'model' => $manifest['model'] ?? null,
            'controller' => (object) $controller,
            'actions' => (object) $actions,
            'policy' => $policy,
            'pages' => (object) $pages,
        ];

        /*
         * The menu is written only when the manifest says something about it: an entry, or null
         * to keep the list page out of the menu (list-pages.md, "Menu").
         */
        if (array_key_exists('menu', $manifest)) {
            $menu = $manifest['menu'];
            $document['menu'] = is_array($menu) ? (object) array_filter(
                ['title' => $menu['title'] ?? null, 'icon' => $menu['icon'] ?? null, 'group' => $menu['group'] ?? null, 'description' => $menu['description'] ?? null, 'ability' => $menu['ability'] ?? null],
                fn (mixed $value, string $key): bool => $value !== null || in_array($key, ['title', 'icon'], true),
                ARRAY_FILTER_USE_BOTH,
            ) : null;
        }

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * Whether a value is a menu entry: a translation key for its title and a kebab-case lucide
     * icon name, and, when it has them, the key of the group it sits in, the translation key of
     * its description and the policy's ability it asks in place of viewAny. Nothing else.
     *
     * @phpstan-assert-if-true array{title: string, icon: string, group?: string, description?: string, ability?: string} $menu
     */
    public static function isMenuEntry(mixed $menu): bool
    {
        $key = '/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/';

        return is_array($menu)
            && array_diff(array_keys($menu), ['title', 'icon', 'group', 'description', 'ability']) === []
            && is_string($menu['title'] ?? null) && preg_match($key, $menu['title']) === 1
            && is_string($menu['icon'] ?? null) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $menu['icon']) === 1
            && (! array_key_exists('group', $menu) || (is_string($menu['group']) && preg_match(self::MENU_GROUP, $menu['group']) === 1))
            && (! array_key_exists('description', $menu) || (is_string($menu['description']) && preg_match($key, $menu['description']) === 1))
            && (! array_key_exists('ability', $menu) || (is_string($menu['ability']) && preg_match(StructureMenu::ABILITY, $menu['ability']) === 1));
    }

    /**
     * What is wrong with a resource manifest's shape, one line per fault. Empty when it is fine.
     *
     * @return list<string>
     */
    public function resourceProblems(string $resource, mixed $document): array
    {
        if (! is_array($document)) {
            return ['is not a JSON object'];
        }

        $problems = [];

        if (($document['resource'] ?? null) !== $resource) {
            $problems[] = sprintf('says "resource": %s, but the file is named %s.json', json_encode($document['resource'] ?? null), $resource);
        }

        foreach (array_diff(array_keys($document), self::RESOURCE_KEYS) as $key) {
            $problems[] = sprintf('has an unknown key "%s"', $key);
        }

        if (! self::isDescription($document['description'] ?? null)) {
            $problems[] = sprintf('"description" must be text of at most %d characters, or left out', self::DESCRIPTION_LENGTH);
        }

        $model = $document['model'] ?? null;

        if ($model !== null && (! is_string($model) || preg_match('/^[A-Z][A-Za-z0-9]*$/', $model) !== 1)) {
            $problems[] = '"model" must be a StudlyCase model name or null';
        }

        $controller = $document['controller'] ?? null;

        if (! is_array($controller)) {
            $problems[] = 'has no "controller" object';
        } else {
            foreach ($controller as $method => $useCases) {
                if (preg_match('/^(__invoke|[a-z][A-Za-z0-9]*)$/', (string) $method) !== 1) {
                    $problems[] = sprintf('controller.%s: a method name is camelCase', $method);
                }

                if (! $this->isUseCaseList($useCases)) {
                    $problems[] = sprintf('controller.%s: must list use cases as "Context/UseCase"', $method);
                }
            }
        }

        $actions = $document['actions'] ?? null;

        if (! is_array($actions)) {
            $problems[] = 'has no "actions" object';
        } else {
            foreach ($actions as $verb => $action) {
                if (preg_match('/^[A-Z][A-Za-z0-9]*$/', (string) $verb) !== 1) {
                    $problems[] = sprintf('actions.%s: a verb is StudlyCase', $verb);

                    continue;
                }

                if (! is_array($action) || array_diff(array_keys($action), ['row', 'bulk', 'useCases']) !== [] || ! is_bool($action['row'] ?? null) || ! is_bool($action['bulk'] ?? null) || ! $this->isUseCaseList($action['useCases'] ?? null)) {
                    $problems[] = sprintf('actions.%s: must be {"row": bool, "bulk": bool, "useCases": ["Context/UseCase"]}', $verb);
                }
            }
        }

        $policy = $document['policy'] ?? null;

        if ($policy !== null && (! is_array($policy) || ! array_is_list($policy) || array_filter($policy, fn (mixed $ability): bool => ! is_string($ability) || preg_match('/^[a-z][A-Za-z0-9]*$/', $ability) !== 1) !== [])) {
            $problems[] = '"policy" must list camelCase abilities, or be null';
        }

        $pages = $document['pages'] ?? null;

        if (! is_array($pages)) {
            $problems[] = 'has no "pages" object';
        } else {
            foreach ($pages as $page => $kind) {
                if (preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', (string) $page) !== 1) {
                    $problems[] = sprintf('pages.%s: a page is its path under resources/js/pages, in kebab case', $page);
                }

                if (! in_array($kind, self::PAGE_KINDS, true)) {
                    $problems[] = sprintf('pages.%s: must be one of %s', $page, implode(', ', self::PAGE_KINDS));
                } elseif ($kind === 'dialog') {
                    $problem = self::dialogProblem((string) $page, is_string($model) ? $model : null);

                    if ($problem !== null) {
                        $problems[] = sprintf('pages.%s: %s', $page, $problem);
                    }
                }
            }
        }

        if (array_key_exists('menu', $document) && $document['menu'] !== null && ! self::isMenuEntry($document['menu'])) {
            $problems[] = '"menu" must be {"title": "a translation key", "icon": "a kebab-case lucide icon"}, with an optional "group" (the key of a group Navigation.php declares), "description" (a translation key) and "ability" (the policy ability the entry asks, viewAny when left out), or null to keep the list page out of the menu';
        }

        return $problems;
    }

    /**
     * What is wrong with a page of kind `dialog` at this path (form-pages.md, "Form dialogs"), or
     * null: a dialog is a model's create or edit form, kept at the path make:form-page would give
     * its page, so the reader finds it there again and kit:apply knows which form it builds.
     */
    public static function dialogProblem(string $page, ?string $model): ?string
    {
        if ($model === null) {
            return 'a dialog holds the form of a model, so it needs a resource with a model';
        }

        $paths = array_map(fn (string $form): string => FormDialogs::pageOf($model, $form), FormDialogs::forms());

        return in_array($page, $paths, true) ? null : 'a dialog is the create or the edit form, kept at the path its page would have: '.implode(' or ', $paths);
    }

    /**
     * A file's text, or nothing when it is gone: a file may be removed between listing its folder
     * and reading it, by git or by another process, and is then read as empty. The read is
     * silenced so that a file gone in that moment is an answer, not a warning. Every structure
     * class reads the project's files through this.
     */
    public static function text(string $path): string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        return $contents === false ? '' : $contents;
    }

    private function contents(string $path): string
    {
        return self::text($path);
    }

    private function isUseCaseList(mixed $useCases): bool
    {
        return is_array($useCases) && array_is_list($useCases) && array_filter(
            $useCases,
            fn (mixed $useCase): bool => ! is_string($useCase) || preg_match('#^[A-Z][A-Za-z0-9]*/[A-Z][A-Za-z0-9]*$#', $useCase) !== 1,
        ) === [];
    }

    /**
     * Whether a value is an entity's methods: camelCase names, each holding only its `params` and
     * its `throws`.
     */
    private function isMethods(mixed $value): bool
    {
        return $this->isMapOf($value, fn (mixed $method): bool => is_array($method)
            && count($method) === 2
            && array_key_exists('throws', $method)
            && $this->isMapOf($method['params'] ?? null, fn (mixed $type): bool => is_string($type) && $type !== '')
            && is_array($method['throws'])
            && array_is_list($method['throws'])
            && array_filter($method['throws'], fn (mixed $exception): bool => ! is_string($exception) || $exception === '') === [])
            && (! is_array($value) || array_filter(array_keys($value), fn (int|string $name): bool => preg_match('/^[a-z][A-Za-z0-9]*$/', (string) $name) !== 1) === []);
    }

    /**
     * An object whose keys are PHP names and whose values each pass the test. JSON's `{}` decodes
     * to an empty array, so an empty one counts.
     *
     * @param  Closure(mixed): bool  $fits
     */
    private function isMapOf(mixed $value, Closure $fits): bool
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (! is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1 || ! $fits($item)) {
                return false;
            }
        }

        return true;
    }
}
