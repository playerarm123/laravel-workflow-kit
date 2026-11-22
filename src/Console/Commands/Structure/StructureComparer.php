<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Closure;

/**
 * Compares the structure manifest with the code (structure.md), the one comparison both the
 * Architecture check `manifest` and `kit:plan` report, so the check's failures and the plan's
 * "fix by hand" list are the same lines.
 *
 * Each difference names its check:
 * - `files`: a manifest of the wrong shape, or a table designed in two places;
 * - `in-json`: the code holds a piece the manifest does not list;
 * - `in-code`: the manifest lists a piece the code does not have yet;
 * - `matches`: a piece the two describe differently.
 *
 * Each also names the graph node it is about (StructureGraph), or null for a piece the manifest
 * does not list.
 */
final class StructureComparer
{
    public const string IMPORT = 'php artisan kit:import';

    /**
     * @var array<string, list<array{0: string, 1: string|null}>> table → each context (and entity) whose manifest designs it
     */
    private array $designedTables = [];

    /**
     * @param  Closure(string): bool  $skip  contexts and pieces left out on both sides, such as the generator tests' scratch ones
     */
    public function __construct(
        private readonly StructureReader $reader,
        private readonly StructureFiles $files,
        private readonly Closure $skip,
    ) {}

    /**
     * Every difference between the manifest and the code.
     *
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    public function differences(): array
    {
        return [...$this->contextDifferences(), ...$this->resourceDifferences()];
    }

    /**
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function contextDifferences(): array
    {
        $differences = [];
        $code = $this->keep($this->reader->contexts());
        $valid = [];

        foreach ($this->keep($this->files->contexts()) as $context) {
            $document = $this->files->read($context);
            $problems = $this->files->problems($context, $document);

            foreach ($problems as $problem) {
                $differences[] = $this->difference('files', $this->files->relativePath($context), $problem);
            }

            if ($problems === [] && is_array($document)) {
                $valid[$context] = $document;
            }
        }

        $this->designedTables = $this->designedTables($valid);

        foreach ($code as $context) {
            if (! $this->files->exists($context)) {
                $differences[] = $this->difference('in-json', $this->files->relativePath($context), sprintf('is missing — run `%s --context=%s`', self::IMPORT, $context));

                continue;
            }

            if (! isset($valid[$context])) {
                continue;
            }

            $read = $this->reader->read($context);

            foreach ($this->reader->clashes($context) as $section => $names) {
                foreach ($names as $name => $aggregates) {
                    if (($this->skip)((string) $name)) {
                        continue;
                    }

                    $differences[] = $this->difference('matches', $this->files->relativePath($context), sprintf(
                        '%s.%s is declared in both %s — a name is unique within its context, so rename one',
                        $section,
                        $name,
                        implode(' and ', $aggregates),
                    ), self::contextNode($context, $section, (string) $name));
                }
            }

            foreach (StructureFiles::SECTIONS as $section => $keys) {
                foreach ($read[$section] as $name => $entry) {
                    if (($this->skip)((string) $name)) {
                        continue;
                    }

                    if ($section === 'entities') {
                        $differences = [...$differences, ...$this->entityDifferences($context, (string) $name, $entry, $valid[$context]['entities'][$name] ?? null)];

                        continue;
                    }

                    if (! array_key_exists($name, $valid[$context][$section])) {
                        $differences[] = $this->difference('in-json', $this->reader->classOf($context, $section, $name), sprintf(
                            'is not in %s — add it under "%s" (`%s --context=%s --sync` adds it, keeping what is not built yet)',
                            $this->files->relativePath($context),
                            $section,
                            self::IMPORT,
                            $context,
                        ));

                        continue;
                    }

                    $differences = [...$differences, ...$this->entryDifferences($context, $section, $name, array_keys($keys), $entry, $valid[$context][$section][$name])];
                }
            }
        }

        foreach ($valid as $context => $document) {
            if (! in_array($context, $code, true)) {
                $differences[] = $this->difference('in-code', $this->files->relativePath($context), 'names a context with no folder under app/Domain or app/Application', "context:{$context}");

                continue;
            }

            $read = $this->reader->read($context);

            foreach (array_keys(StructureFiles::SECTIONS) as $section) {
                foreach (array_keys($document[$section]) as $name) {
                    if ($section === 'entities' && ! array_key_exists($name, $read[$section]) && ! ($this->skip)((string) $name)) {
                        $differences = [...$differences, ...$this->entityDifferences($context, (string) $name, null, $document[$section][$name])];

                        continue;
                    }

                    if (! array_key_exists($name, $read[$section]) && ! ($this->skip)((string) $name)) {
                        $differences[] = $this->difference('in-code', $this->files->relativePath($context), sprintf('lists %s.%s, which the code does not have yet — build it, or take it out of the manifest', $section, $name), self::contextNode($context, $section, (string) $name));
                    }
                }
            }
        }

        return [...$differences, ...$this->tableDifferences($valid, $code)];
    }

    /**
     * Each table the valid manifests design, with every place that designs it.
     *
     * @param  array<string, array<string, mixed>>  $valid
     * @return array<string, list<array{0: string, 1: string|null}>>
     */
    private function designedTables(array $valid): array
    {
        $designed = [];

        foreach ($valid as $context => $document) {
            foreach ($document['entities'] as $entity => $entry) {
                if (is_array($entry) && is_string($entry['table']['name'] ?? null)) {
                    $designed[$entry['table']['name']][] = [(string) $context, (string) $entity];
                }
            }

            foreach (array_keys($document[StructureFiles::TABLES]) as $table) {
                $designed[(string) $table][] = [(string) $context, null];
            }
        }

        return $designed;
    }

    /**
     * What tells the tables apart project-wide (structure.md, "Tables"): a table designed in two
     * places, a table the migrations build that no manifest lists and no entity maps to, and each
     * entry of a context's `tables` against what the migrations build.
     *
     * @param  array<string, array<string, mixed>>  $valid
     * @param  list<string>  $code
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function tableDifferences(array $valid, array $code): array
    {
        $differences = [];
        $place = fn (string $table, array $where): string => $where[1] === null ? "{$where[0]} tables.{$table}" : "{$where[0]} entities.{$where[1]}.table";

        foreach ($this->designedTables as $table => $places) {
            if (count($places) > 1) {
                $differences[] = $this->difference('files', $this->files->relativePath($places[0][0]), sprintf(
                    'designs the table %s in %s — a table belongs to one place, so keep one',
                    $table,
                    implode(' and ', array_map(fn (array $where): string => $place((string) $table, $where), $places)),
                ), $places[0][1] === null ? self::tableNode($places[0][0], (string) $table) : self::contextNode($places[0][0], 'entities', $places[0][1]));
            }
        }

        $placements = $this->reader->tablePlacements();
        $schema = $this->reader->tables()->schema();

        foreach ($placements as $table => $placement) {
            if (isset($this->designedTables[$table]) || ($placement !== null && ($placement['entity'] !== null || ! isset($valid[$placement['context']]))) || ($this->skip)(self::studly((string) $table))) {
                continue;
            }

            $differences[] = $placement === null
                ? $this->difference('in-json', StructureTables::MIGRATIONS, sprintf('build the table %s, which no manifest lists — add it under "tables" of the context it belongs to', $table))
                : $this->difference('in-json', $this->files->relativePath($placement['context']), sprintf(
                    'does not list the table %s, which the migrations build — add it under "tables" (`%s --context=%s --sync` adds it, keeping what is not built yet)',
                    $table,
                    self::IMPORT,
                    $placement['context'],
                ));
        }

        foreach ($valid as $context => $document) {
            if (! in_array($context, $code, true)) {
                continue;
            }

            foreach ($document[StructureFiles::TABLES] as $table => $entry) {
                $table = (string) $table;
                $node = self::tableNode($context, $table);
                $file = $this->files->relativePath($context);
                $placement = $placements[$table] ?? null;

                if (! isset($schema[$table])) {
                    $differences[] = $this->difference('in-code', $file, sprintf('lists tables.%s, which no migration builds yet — build it, or take it out of the manifest', $table), $node);
                } elseif ($placement !== null && $placement['entity'] !== null) {
                    $differences[] = $this->difference('matches', $file, sprintf('lists tables.%s, but in the code it is the table of %s entities.%s — move it under entities.%s.table', $table, $placement['context'], $placement['entity'], $placement['entity']), $node);
                } else {
                    $differences = [...$differences, ...$this->tableEntryDifferences($context, "tables.{$table}", (array) $this->reader->tableEntry($table, $context, null, false), $entry, StructureFiles::CONTEXT_TABLE_KEYS, $node)];
                }
            }
        }

        return $differences;
    }

    /**
     * Where one table differs: its name and model, a column or a foreign key only one side has or
     * the two describe differently, the columns in another order, its indexes, timestamps and soft
     * deletes.
     *
     * @param  array<array-key, mixed>  $inCode
     * @param  array<array-key, mixed>  $inJson
     * @param  list<string>  $keys
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function tableEntryDifferences(string $context, string $at, array $inCode, array $inJson, array $keys, ?string $node): array
    {
        $file = $this->files->relativePath($context);
        $differences = [];
        $code = StructureFiles::canonicalTable($inCode, $keys);
        $json = StructureFiles::canonicalTable($inJson, $keys);
        $encode = fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (['name', 'model', 'timestamps', 'softDeletes', 'indexes'] as $key) {
            if (in_array($key, $keys, true) && ($code[$key] ?? null) !== ($json[$key] ?? null)) {
                $differences[] = $this->difference('matches', $file, sprintf('%s.%s is %s in the code but %s in the manifest', $at, $key, $encode($code[$key] ?? null), $encode($json[$key] ?? null)), $node);
            }
        }

        foreach (['columns', 'foreignKeys'] as $part) {
            /** @var array<string, mixed> $built */
            $built = is_array($code[$part] ?? null) ? $code[$part] : [];
            /** @var array<string, mixed> $designed */
            $designed = is_array($json[$part] ?? null) ? $json[$part] : [];

            foreach ($built as $column => $definition) {
                if (! array_key_exists($column, $designed)) {
                    $differences[] = $this->difference('in-json', $file, sprintf('does not list %s.%s.%s, which the migrations build as %s — add it (`%s --context=%s --sync` adds it, keeping what is not built yet)', $at, $part, $column, $encode($definition), self::IMPORT, $context), $node);
                } elseif ($designed[$column] !== $definition) {
                    $differences[] = $this->difference('matches', $file, sprintf('%s.%s.%s is %s in the code but %s in the manifest', $at, $part, $column, $encode($definition), $encode($designed[$column])), $node);
                }
            }

            foreach (array_keys(array_diff_key($designed, $built)) as $column) {
                $differences[] = $this->difference('in-code', $file, sprintf('lists %s.%s.%s, which the migrations do not build yet — build it, or take it out of the manifest', $at, $part, $column), $node);
            }

            $shared = array_keys(array_intersect_key($built, $designed));
            $order = array_values(array_filter(array_keys($designed), fn (int|string $column): bool => in_array($column, $shared, true)));

            if ($shared !== $order) {
                $differences[] = $this->difference('matches', $file, sprintf('%s.%s is in the order %s in the code but %s in the manifest', $at, $part, implode(', ', $shared), implode(', ', $order)), $node);
            }
        }

        return $differences;
    }

    /**
     * Where an entity's table differs. One the code maps the entity to and no manifest lists is
     * missing from the manifest, unless a context lists it under `tables`, which the project-wide
     * comparison already names.
     *
     * @param  array<string, mixed>|null  $inCode
     * @param  array<string, mixed>|null  $inJson
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function entityTableDifferences(string $context, string $name, ?array $inCode, ?array $inJson, ?string $node): array
    {
        $file = $this->files->relativePath($context);
        $code = is_array($inCode['table'] ?? null) ? $inCode['table'] : null;
        $json = is_array($inJson['table'] ?? null) ? $inJson['table'] : null;

        if ($code !== null && $json === null) {
            $table = (string) $code['name'];

            if (isset($this->designedTables[$table])) {
                return [];
            }

            return [$this->difference('in-json', $this->reader->classOf($context, 'entities', $name), sprintf(
                'maps to the table %s, which %s does not list — add it under "entities.%s.table" (`%s --context=%s --sync` adds it, keeping what is not built yet)',
                $table,
                $file,
                $name,
                self::IMPORT,
                $context,
            ))];
        }

        if ($json === null) {
            return [];
        }

        $table = is_string($json['name'] ?? null) ? $json['name'] : '';

        if ($code !== null) {
            return $this->tableEntryDifferences($context, "entities.{$name}.table", $code, $json, StructureFiles::TABLE_KEYS, $node);
        }

        if (! isset($this->reader->tables()->schema()[$table])) {
            return [$this->difference('in-code', $file, sprintf('lists entities.%s.table %s, which no migration builds yet — build it, or take it out of the manifest', $name, $table), $node)];
        }

        $placement = $this->reader->tablePlacements()[$table] ?? null;

        return [$this->difference('matches', $file, $placement !== null && $placement['entity'] !== null
            ? sprintf('entities.%s.table is %s, which the code maps to %s entities.%s', $name, $table, $placement['context'], $placement['entity'])
            : sprintf('entities.%s.table is %s, which the migrations build, but no model of %s maps to it — give app/Models/%s that table, and a root\'s repository its model', $name, $table, $name, is_string($json['model'] ?? null) ? $json['model'] : $name), $node)];
    }

    /**
     * The graph node of an entry of a context's `tables`.
     */
    public static function tableNode(string $context, string $table): string
    {
        return "table:{$context}/{$table}";
    }

    private static function studly(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
    }

    /**
     * The keys of one manifest entry that the code describes differently.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $inCode
     * @param  array<string, mixed>  $inJson
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function entryDifferences(string $context, string $section, string $name, array $keys, array $inCode, array $inJson): array
    {
        $differences = [];

        foreach (array_diff($keys, StructureFiles::INTENT_KEYS) as $key) {
            $json = $inJson[$key] ?? null;

            $kind = StructureFiles::SECTIONS[$section][$key];

            if (is_array($json) && $kind === 'transitions') {
                $json = StructureFiles::orderedTransitions($json, is_array($inJson['cases'] ?? null) ? $inJson['cases'] : []);
            } elseif (is_array($json) && ! StructureFiles::isMap($kind)) {
                sort($json);
            }

            if (in_array($section, ['services', 'applicationServices'], true) && $key === 'exception' && $json === true && ($inCode[$key] ?? null) === false) {
                $differences[] = $this->difference('matches', $this->files->relativePath($context), sprintf(
                    '%s.%s.exception is true in the manifest, but the code has no %sException beside the service — write it by hand, extending %s (%s adds one only to a service it builds)',
                    $section,
                    $name,
                    $name,
                    $section === 'services' ? 'DomainException' : 'ApplicationException',
                    $section === 'services' ? 'make:domain-service' : 'make:application-service',
                ), self::contextNode($context, $section, $name));

                continue;
            }

            if (($inCode[$key] ?? null) !== $json) {
                $differences[] = $this->difference('matches', $this->files->relativePath($context), sprintf(
                    '%s.%s.%s is %s in the code but %s in the manifest',
                    $section,
                    $name,
                    $key,
                    json_encode($inCode[$key] ?? null, JSON_UNESCAPED_SLASHES),
                    json_encode($json, JSON_UNESCAPED_SLASHES),
                ), self::contextNode($context, $section, $name));
            }
        }

        return $differences;
    }

    /**
     * The methods of one entity that only the code has, only the manifest has, or that the two
     * describe differently. Each one is told apart, because a whole entry would bury the one
     * method that differs. They show on the entity's card, or on the card of the aggregate that
     * holds it when the manifest lists no methods for it, so it has no card of its own.
     *
     * @param  array<string, mixed>|null  $inCode
     * @param  array<string, mixed>|null  $inJson
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function entityDifferences(string $context, string $name, ?array $inCode, ?array $inJson): array
    {
        $differences = [];
        $file = $this->files->relativePath($context);
        $aggregate = (string) ($inCode['aggregate'] ?? $inJson['aggregate'] ?? $name);
        $node = $inJson === null ? self::contextNode($context, 'aggregates', $aggregate) : self::contextNode($context, 'entities', $name);

        if ($inCode !== null && $inJson !== null && $inCode['aggregate'] !== $inJson['aggregate']) {
            $differences[] = $this->difference('matches', $file, sprintf('entities.%s.aggregate is %s in the code but %s in the manifest', $name, json_encode($inCode['aggregate']), json_encode($inJson['aggregate'])), $node);
        }

        $differences = [...$differences, ...$this->stateDifferences($context, $name, $inCode, $inJson, $node), ...$this->entityTableDifferences($context, $name, $inCode, $inJson, $node)];

        foreach (['behaviours', 'assertions'] as $group) {
            /** @var array<string, array{params: array<string, string>, throws: list<string>}> $code */
            $code = is_array($inCode[$group] ?? null) ? $inCode[$group] : [];
            /** @var array<string, array{params: array<string, string>, throws: list<string>}> $json */
            $json = is_array($inJson[$group] ?? null) ? $inJson[$group] : [];

            foreach ($code as $method => $entry) {
                if (! array_key_exists($method, $json)) {
                    $differences[] = $this->difference('in-json', $this->reader->classOf($context, 'entities', $name).'::'.$method, sprintf(
                        'is not in %s — add it under "entities.%s.%s" (`%s --context=%s --sync` adds it, keeping what is not built yet)',
                        $file,
                        $name,
                        $group,
                        self::IMPORT,
                        $context,
                    ));

                    continue;
                }

                $throws = $json[$method]['throws'];
                sort($throws);

                foreach (['params' => $json[$method]['params'], 'throws' => $throws] as $key => $value) {
                    if ($entry[$key] !== $value) {
                        $differences[] = $this->difference('matches', $file, sprintf(
                            'entities.%s.%s.%s is %s in the code but %s in the manifest',
                            $name,
                            $method,
                            $key,
                            json_encode($entry[$key], JSON_UNESCAPED_SLASHES),
                            json_encode($value, JSON_UNESCAPED_SLASHES),
                        ), $node);
                    }
                }
            }

            foreach (array_diff_key($json, $code) as $method => $entry) {
                $differences[] = $this->difference('in-code', $file, sprintf('lists entities.%s.%s, which the code does not have yet — build it, or take it out of the manifest', $name, $method), $node);
            }
        }

        return $differences;
    }

    /**
     * Where an entity's state differs: a property only one side has, a type the two write
     * differently, the two in another order (the order is the constructor's, which `reconstitute()`
     * follows), and a property the code holds with no getter to read it by.
     *
     * @param  array<string, mixed>|null  $inCode
     * @param  array<string, mixed>|null  $inJson
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function stateDifferences(string $context, string $name, ?array $inCode, ?array $inJson, ?string $node): array
    {
        $differences = [];
        $file = $this->files->relativePath($context);
        /** @var array<string, string> $code */
        $code = is_array($inCode['state'] ?? null) ? $inCode['state'] : [];
        /** @var array<string, string> $json */
        $json = is_array($inJson['state'] ?? null) ? $inJson['state'] : [];

        foreach ($code as $property => $type) {
            if (! array_key_exists($property, $json)) {
                $differences[] = $this->difference('in-json', $this->reader->classOf($context, 'entities', $name).'::$'.$property, sprintf(
                    'is not in %s — add it under "entities.%s.state" (`%s --context=%s --sync` adds it, keeping what is not built yet)',
                    $file,
                    $name,
                    self::IMPORT,
                    $context,
                ));
            } elseif ($json[$property] !== $type) {
                $differences[] = $this->difference('matches', $file, sprintf('entities.%s.state.%s is %s in the code but %s in the manifest', $name, $property, json_encode($type, JSON_UNESCAPED_SLASHES), json_encode($json[$property], JSON_UNESCAPED_SLASHES)), $node);
            }
        }

        $shared = array_keys(array_intersect_key($code, $json));
        $designed = array_values(array_filter(array_keys($json), fn (string $property): bool => in_array($property, $shared, true)));

        if ($shared !== $designed) {
            $differences[] = $this->difference('matches', $file, sprintf('entities.%s.state is in the order %s in the code but %s in the manifest', $name, implode(', ', $shared), implode(', ', $designed)), $node);
        }

        foreach (array_keys(array_diff_key($json, $code)) as $property) {
            $differences[] = $this->difference('in-code', $file, sprintf('lists entities.%s.state.%s, which the code does not have yet — build it, or take it out of the manifest', $name, $property), $node);
        }

        if ($inCode !== null) {
            foreach ($this->reader->stateWithoutGetter($context)[$name] ?? [] as $property) {
                $differences[] = $this->difference('matches', $this->reader->classOf($context, 'entities', $name).'::$'.$property, sprintf(
                    'has no getter — add public function %s(): %s that returns it',
                    $property,
                    $code[$property] ?? 'mixed',
                ), $node);
            }
        }

        return $differences;
    }

    /**
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function resourceDifferences(): array
    {
        $differences = [];
        $code = $this->keep($this->reader->resources());
        $valid = [];

        foreach ($this->keep($this->files->resources()) as $resource) {
            $document = $this->files->readResource($resource);
            $problems = $this->files->resourceProblems($resource, $document);

            foreach ($problems as $problem) {
                $differences[] = $this->difference('files', $this->files->relativeResourcePath($resource), $problem);
            }

            if ($problems === [] && is_array($document)) {
                $valid[$resource] = $document;
            }
        }

        foreach ($code as $resource) {
            if (! $this->files->resourceExists($resource)) {
                $differences[] = $this->difference('in-json', $this->files->relativeResourcePath($resource), sprintf('is missing — run `%s --resource=%s`', self::IMPORT, $resource));

                continue;
            }

            if (! isset($valid[$resource])) {
                continue;
            }

            $inJson = self::resourceEntries($valid[$resource]);

            foreach (self::resourceEntries($this->reader->readResource($resource)) as $entry => $inCode) {
                if (! array_key_exists($entry, $inJson)) {
                    $differences[] = $this->difference('in-json', $this->reader->resourceClass($resource), sprintf(
                        'has %s, which %s does not list (`%s --resource=%s --sync` adds it, keeping what is not built yet)',
                        $entry,
                        $this->files->relativeResourcePath($resource),
                        self::IMPORT,
                        $resource,
                    ));

                    continue;
                }

                if ($inCode !== $inJson[$entry]) {
                    $differences[] = $this->difference('matches', $this->files->relativeResourcePath($resource), sprintf(
                        '%s is %s in the code but %s in the manifest',
                        $entry,
                        json_encode($inCode, JSON_UNESCAPED_SLASHES),
                        json_encode($inJson[$entry], JSON_UNESCAPED_SLASHES),
                    ), self::resourceNode($resource, $entry));
                }
            }
        }

        foreach ($valid as $resource => $document) {
            if (! in_array($resource, $code, true)) {
                $differences[] = $this->difference('in-code', $this->files->relativeResourcePath($resource), 'names an HTTP resource the code does not have yet — build it, or take the file out', "resource:{$resource}");

                continue;
            }

            $read = self::resourceEntries($this->reader->readResource($resource));

            foreach (array_keys(self::resourceEntries($document)) as $entry) {
                if (! array_key_exists($entry, $read)) {
                    $differences[] = $this->difference('in-code', $this->files->relativeResourcePath($resource), sprintf('lists %s, which the code does not have yet — build it, or take it out of the manifest', $entry), self::resourceNode($resource, $entry));
                }
            }
        }

        return $differences;
    }

    /**
     * A resource's `model`, `policy` and each entry of its maps by `section.name`, lists sorted, so
     * the code and the manifest compare entry by entry. A menu entry is `menu.{menu page}`
     * (StructureReader::menuPageOf()), so a difference in it shows on that page; `"menu": null` and no entry at all compare equal.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public static function resourceEntries(array $manifest): array
    {
        $policy = $manifest['policy'] ?? null;

        if (is_array($policy)) {
            sort($policy);
        }

        $entries = ['model' => $manifest['model'] ?? null, 'policy' => $policy];

        foreach (['controller', 'actions', 'pages'] as $section) {
            $values = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];

            foreach ($values as $name => $value) {
                if (is_array($value) && array_is_list($value)) {
                    sort($value);
                } elseif (is_array($value) && is_array($value['useCases'] ?? null)) {
                    $useCases = $value['useCases'];
                    sort($useCases);
                    $value = ['row' => $value['row'] ?? null, 'bulk' => $value['bulk'] ?? null, 'useCases' => $useCases];
                }

                $entries[$section.'.'.$name] = $value;
            }
        }

        $menu = $manifest['menu'] ?? null;

        if (is_array($menu)) {
            $pages = is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [];
            $entries['menu.'.(StructureReader::menuPageOf($pages) ?? '')] = [
                'title' => $menu['title'] ?? null,
                'icon' => $menu['icon'] ?? null,
                ...array_filter(['group' => $menu['group'] ?? null, 'description' => $menu['description'] ?? null], fn (mixed $value): bool => $value !== null),
                // viewAny is what an entry that names no ability asks, so naming it changes nothing.
                ...(($menu['ability'] ?? StructureMenu::DEFAULT_ABILITY) === StructureMenu::DEFAULT_ABILITY ? [] : ['ability' => $menu['ability']]),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function keep(array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => ! ($this->skip)($name)));
    }

    /**
     * @return array{check: string, subject: string, message: string, node: string|null}
     */
    private function difference(string $check, string $subject, string $message, ?string $node = null): array
    {
        return ['check' => $check, 'subject' => $subject, 'message' => $message, 'node' => $node];
    }

    /**
     * The graph node of a context manifest entry: `aggregate:{Context}/{Name}` and so on.
     */
    public static function contextNode(string $context, string $section, string $name): string
    {
        return sprintf('%s:%s/%s', ['aggregates' => 'aggregate', 'services' => 'service', 'applicationServices' => 'applicationService', 'ports' => 'port', 'enums' => 'enum', 'valueObjects' => 'valueObject', 'exceptions' => 'exception', 'entities' => 'entity'][$section] ?? 'useCase', $context, $name);
    }

    /**
     * The graph node of a resource entry as resourceEntries() names it: `model`, `policy`,
     * `controller.{method}`, `actions.{Verb}`, `pages.{path}` or `menu.{path}`, which is the
     * menu page's own node.
     */
    public static function resourceNode(string $resource, string $entry): string
    {
        [$section, $name] = explode('.', $entry, 2) + [1 => ''];

        return match ($section) {
            'model', 'policy', 'controller' => "{$section}:{$resource}",
            'actions' => "action:{$resource}/{$name}",
            default => "page:{$resource}/{$name}",
        };
    }
}
