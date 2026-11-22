<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

/**
 * Brings a manifest back in step with the code without losing the design that is not built yet
 * (structure.md). What the code has wins: each piece it holds takes the code's shape. What only
 * the manifest lists is kept and reported, because it may be designed and not built, or the old
 * name of something renamed in the code; `$prune` takes it out on purpose. A piece in the middle
 * of a replacement is the design's, so it is left as the manifest has it.
 *
 * It only computes: the caller writes the manifest it returns.
 */
final class StructureSync
{
    /**
     * How a sync of one property of an entity's state names it, beside the methods it syncs by
     * their bare name: `state.{property}`.
     */
    public const string STATE_PREFIX = 'state.';

    public function __construct(
        private readonly StructureFiles $files,
        private readonly StructureReader $reader,
    ) {}

    /**
     * A context's manifest merged with its code.
     *
     * @return array{manifest: array<string, mixed>, changes: list<string>, kept: list<string>}
     */
    public function syncContext(string $context, bool $prune = false): array
    {
        $manifest = $this->contextManifest($context);
        $code = $this->reader->read($context);
        $changes = [];
        $kept = [];

        foreach (array_keys(StructureFiles::SECTIONS) as $section) {
            if ($section === 'entities') {
                continue;
            }

            /** @var array<string, array<string, mixed>> $entries */
            $entries = $manifest[$section];
            /** @var array<string, array<string, mixed>> $built */
            $built = $code[$section];

            foreach ($built as $name => $entry) {
                $name = (string) $name;

                if ($this->inReplacement($manifest, $section, $name)) {
                    continue;
                }

                $merged = $this->withIntent($entry, $entries[$name] ?? null);

                if ($section === 'aggregates') {
                    foreach ($this->designedChildren($merged, $entries[$name] ?? null) as $child) {
                        if ($prune) {
                            $changes[] = "aggregates.{$name}.children.{$child}: removed";
                        } else {
                            $kept[] = "aggregates.{$name}.children.{$child}";
                            $merged['children'][] = $child;
                        }
                    }
                }

                $change = $this->change("{$section}.{$name}", $entries[$name] ?? null, $merged);

                if ($change !== null) {
                    $changes[] = $change;
                    $entries[$name] = $merged;
                }
            }

            foreach (array_diff_key($entries, $built) as $name => $entry) {
                if ($this->inReplacement($manifest, $section, (string) $name)) {
                    continue;
                }

                if ($prune) {
                    unset($entries[$name]);
                    $changes[] = "{$section}.{$name}: removed";
                } else {
                    $kept[] = "{$section}.{$name}";
                }
            }

            $manifest[$section] = $entries;
        }

        $entities = $this->syncEntities($manifest['entities'], $code['entities'], $prune);
        $manifest['entities'] = $entities['entities'];
        $tables = $this->syncTables($context, $manifest[StructureFiles::TABLES], $code[StructureFiles::TABLES], $prune);
        $manifest[StructureFiles::TABLES] = $tables['tables'];

        return [
            'manifest' => $manifest,
            'changes' => [...$changes, ...$entities['changes'], ...$tables['changes']],
            'kept' => [...$kept, ...$entities['kept'], ...$tables['kept']],
        ];
    }

    /**
     * A context's `tables` merged with the code's: every table it lists that the migrations build
     * and no entity maps to takes the code's shape, wherever the code would place it, and the
     * code's tables of this context that no other context lists are added. One it lists that is not
     * built is kept unless `$prune` takes it out.
     *
     * @param  array<array-key, mixed>  $designed
     * @param  array<string, array<string, mixed>>  $placed
     * @return array{tables: array<string, mixed>, changes: list<string>, kept: list<string>}
     */
    private function syncTables(string $context, array $designed, array $placed, bool $prune): array
    {
        $changes = [];
        $kept = [];
        $placements = $this->reader->tablePlacements();
        $elsewhere = $this->tablesDesignedOutside($context);
        $built = [];

        foreach ($placed as $table => $entry) {
            if (! isset($elsewhere[$table]) || array_key_exists($table, $designed)) {
                $built[$table] = $entry;
            }
        }

        foreach (array_keys($designed) as $table) {
            $table = (string) $table;

            // A table the migrations build that no entity maps to, even one the code cannot place.
            if (! isset($built[$table]) && array_key_exists($table, $placements) && ($placements[$table]['entity'] ?? null) === null) {
                $built[$table] = (array) $this->reader->tableEntry($table, $context, null, false);
            }
        }

        foreach ($built as $table => $entry) {
            $merged = $this->withIntent($entry, $designed[$table] ?? null);
            $change = $this->tableChange(StructureFiles::TABLES.".{$table}", $designed[$table] ?? null, $merged, StructureFiles::CONTEXT_TABLE_KEYS);

            if ($change !== null) {
                $changes[] = $change;
            }

            $designed[$table] = $merged;
        }

        foreach (array_keys(array_diff_key($designed, $built)) as $table) {
            if ($prune) {
                unset($designed[$table]);
                $changes[] = StructureFiles::TABLES.".{$table}: removed";
            } else {
                $kept[] = StructureFiles::TABLES.".{$table}";
            }
        }

        ksort($designed);

        return ['tables' => $designed, 'changes' => $changes, 'kept' => $kept];
    }

    /**
     * The tables the other contexts' manifests design, in an entity or under `tables`.
     *
     * @return array<string, true>
     */
    private function tablesDesignedOutside(string $context): array
    {
        $designed = [];

        foreach ($this->files->contexts() as $other) {
            $manifest = $other === $context ? null : $this->files->read($other);

            if (! is_array($manifest)) {
                continue;
            }

            foreach (is_array($manifest[StructureFiles::TABLES] ?? null) ? array_keys($manifest[StructureFiles::TABLES]) : [] as $table) {
                $designed[(string) $table] = true;
            }

            foreach (is_array($manifest['entities'] ?? null) ? $manifest['entities'] : [] as $entry) {
                if (is_array($entry) && is_string($entry['table']['name'] ?? null)) {
                    $designed[$entry['table']['name']] = true;
                }
            }
        }

        return $designed;
    }

    /**
     * One line saying how a table changes, or null when it does not, read in canonical form, so a
     * column's or an index's order counts and a flag left out is a flag that is false.
     *
     * @param  list<string>  $keys
     */
    private function tableChange(string $path, mixed $before, mixed $after, array $keys): ?string
    {
        if (! is_array($before)) {
            return "{$path}: added";
        }

        $before = StructureFiles::canonicalTable($before, $keys);
        $after = is_array($after) ? StructureFiles::canonicalTable($after, $keys) : [];
        $changed = [];

        foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;

            if (! in_array($key, ['columns', 'foreignKeys'], true) || ! is_array($was) || ! is_array($now)) {
                if ($was !== $now) {
                    $changed[] = sprintf('%s %s → %s', $key, $this->json($was), $this->json($now));
                }

                continue;
            }

            // Column by column, so a sync says which one moved rather than the whole map.
            foreach (array_unique([...array_keys($now), ...array_keys($was)]) as $column) {
                if (($was[$column] ?? null) !== ($now[$column] ?? null)) {
                    $changed[] = sprintf('%s.%s %s → %s', $key, $column, $this->json($was[$column] ?? null), $this->json($now[$column] ?? null));
                }
            }

            $shared = array_keys(array_intersect_key($was, $now));
            $order = array_values(array_filter(array_keys($now), fn (int|string $column): bool => in_array($column, $shared, true)));

            if ($shared !== $order) {
                $changed[] = sprintf('%s: order %s → %s', $key, implode(', ', $shared), implode(', ', $order));
            }
        }

        return $changed === [] ? null : $path.': '.implode(', ', $changed);
    }

    /**
     * An HTTP resource's manifest merged with its code. A controller method or an action that
     * already calls a use case standing in for another is left alone: the code still calls the old
     * one until kit:apply swaps it in.
     *
     * @return array{manifest: array<string, mixed>, changes: list<string>, kept: list<string>}
     */
    public function syncResource(string $resource, bool $prune = false): array
    {
        $manifest = $this->resourceManifest($resource);
        $code = $this->reader->readResource($resource);
        $replacers = $this->replacingUseCases();
        $changes = [];
        $kept = [];

        foreach (['model', 'policy'] as $key) {
            if ($code[$key] !== null) {
                $change = $this->change($key, $manifest[$key], $code[$key]);

                if ($change !== null) {
                    $changes[] = $change;
                    $manifest[$key] = $code[$key];
                }
            } elseif ($manifest[$key] !== null) {
                $kept[] = $key;
            }
        }

        foreach (['controller', 'actions', 'pages'] as $section) {
            /** @var array<string, mixed> $entries */
            $entries = $manifest[$section];
            /** @var array<string, mixed> $built */
            $built = $code[$section];

            foreach ($built as $name => $value) {
                if ($this->callsReplacer($entries[$name] ?? null, $replacers)) {
                    continue;
                }

                $change = $this->change("{$section}.{$name}", $entries[$name] ?? null, $value);

                if ($change !== null) {
                    $changes[] = $change;
                    $entries[$name] = $value;
                }
            }

            foreach (array_keys(array_diff_key($entries, $built)) as $name) {
                if ($prune) {
                    unset($entries[$name]);
                    $changes[] = "{$section}.{$name}: removed";
                } else {
                    $kept[] = "{$section}.{$name}";
                }
            }

            $manifest[$section] = $entries;
        }

        if (array_key_exists('menu', $code)) {
            $change = $this->change('menu', $manifest['menu'] ?? null, $code['menu']);

            if ($change !== null) {
                $changes[] = $change;
                $manifest['menu'] = $code['menu'];
            }
        } elseif (is_array($manifest['menu'] ?? null)) {
            $kept[] = 'menu';
        }

        return ['manifest' => $manifest, 'changes' => $changes, 'kept' => $kept];
    }

    /**
     * The built pieces of a context whose manifest entry the code describes another way, as the
     * paths a sync would change: `{section}.{name}`, `entities.{Entity}.{method}` for a method, or
     * `entities.{Entity}.state.{property}` for a property of an entity's state.
     *
     * @return list<string>
     */
    public function contextOutOfStep(string $context): array
    {
        return $this->outOfStep($this->syncContext($context)['changes']);
    }

    /**
     * The built entries of an HTTP resource whose manifest entry the code describes another way:
     * `model`, `policy`, `controller.{method}`, `actions.{Verb}` or `pages.{path}`.
     *
     * @return list<string>
     */
    public function resourceOutOfStep(string $resource): array
    {
        return $this->outOfStep($this->syncResource($resource)['changes']);
    }

    /**
     * The manifest with one piece of a context taken from the code, or why it cannot be.
     *
     * @return array{manifest: array<string, mixed>}|array{error: string}
     */
    public function syncPiece(string $context, string $section, string $name): array
    {
        $manifest = $this->contextManifest($context);
        $built = $this->reader->read($context)[$section][$name] ?? null;

        if (! is_array($built)) {
            return ['error' => "The code has no {$name}, so there is nothing to sync from."];
        }

        if ($this->inReplacement($manifest, $section, $name)) {
            return ['error' => "{$name} is in the middle of a replacement. kit:apply and kit:retire settle it."];
        }

        $merged = $this->withIntent($built, $manifest[$section][$name] ?? null);

        if ($section === 'aggregates') {
            $merged['children'] = [...$merged['children'], ...$this->designedChildren($merged, $manifest[$section][$name] ?? null)];
        }

        $manifest[$section][$name] = $merged;

        return ['manifest' => $manifest];
    }

    /**
     * The manifest with one method of an entity taken from the code, or why it cannot be.
     *
     * @return array{manifest: array<string, mixed>}|array{error: string}
     */
    public function syncMethod(string $context, string $entity, string $method): array
    {
        $manifest = $this->contextManifest($context);
        $built = $this->reader->read($context)['entities'][$entity] ?? null;

        foreach (['behaviours', 'assertions'] as $group) {
            if (! is_array($built[$group][$method] ?? null)) {
                continue;
            }

            $entry = $manifest['entities'][$entity] ?? ['aggregate' => $built['aggregate'], 'state' => [], 'behaviours' => [], 'assertions' => []];
            $other = $group === 'behaviours' ? 'assertions' : 'behaviours';
            unset($entry[$other][$method]);
            $entry['aggregate'] = $built['aggregate'];
            $entry[$group][$method] = $built[$group][$method];
            $manifest['entities'][$entity] = $entry;

            return ['manifest' => $manifest];
        }

        return ['error' => "The code has no {$entity}::{$method}(), so there is nothing to sync from."];
    }

    /**
     * The manifest with one property of an entity's state taken from the code, or why it cannot be.
     * The property keeps its place in the manifest, or goes last when the manifest lacks it.
     *
     * @return array{manifest: array<string, mixed>}|array{error: string}
     */
    public function syncProperty(string $context, string $entity, string $property): array
    {
        $manifest = $this->contextManifest($context);
        $built = $this->reader->read($context)['entities'][$entity] ?? null;

        if (! is_array($built) || ! array_key_exists($property, $built['state'])) {
            return ['error' => "The code has no {$entity}.{$property}, so there is nothing to sync from."];
        }

        $entry = $manifest['entities'][$entity] ?? ['aggregate' => $built['aggregate'], 'state' => [], 'behaviours' => [], 'assertions' => []];
        $entry['aggregate'] = $built['aggregate'];
        $entry['state'][$property] = $built['state'][$property];
        $manifest['entities'][$entity] = $entry;

        return ['manifest' => $manifest];
    }

    /**
     * The manifest with one entry of an HTTP resource taken from the code, or why it cannot be.
     * `$section` is `model`, `policy`, `controller`, `actions` or `pages`.
     *
     * @return array{manifest: array<string, mixed>}|array{error: string}
     */
    public function syncResourcePiece(string $resource, string $section, string $name): array
    {
        $manifest = $this->resourceManifest($resource);
        $code = $this->reader->readResource($resource);

        if (in_array($section, ['model', 'policy'], true)) {
            if ($code[$section] === null) {
                return ['error' => "The code has no {$section} for {$resource}, so there is nothing to sync from."];
            }

            $manifest[$section] = $code[$section];

            return ['manifest' => $manifest];
        }

        if (! in_array($section, ['controller', 'actions', 'pages'], true) || ! array_key_exists($name, $code[$section])) {
            return ['error' => "The code has no {$name}, so there is nothing to sync from."];
        }

        if ($this->callsReplacer($manifest[$section][$name] ?? null, $this->replacingUseCases())) {
            return ['error' => "{$name} already calls a use case that is replacing another. kit:apply swaps it in."];
        }

        $manifest[$section][$name] = $code[$section][$name];

        return ['manifest' => $manifest];
    }

    /**
     * The paths of the changes that rewrite an entry the manifest already has: an added entry has
     * no card to sync, and an entity's aggregate moves with its methods.
     *
     * @param  list<string>  $changes
     * @return list<string>
     */
    private function outOfStep(array $changes): array
    {
        $paths = [];

        foreach ($changes as $change) {
            [$path, $what] = explode(': ', $change, 2);

            if ($what !== 'added' && $what !== 'removed' && ! str_starts_with($what, 'aggregate ')) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * An entity's state merged with the code's: the code's properties in the constructor's order,
     * then those only the manifest lists, which are kept unless `$prune` takes them out.
     *
     * @param  array<array-key, mixed>  $designed
     * @param  array<string, string>  $built
     * @return array{state: array<string, mixed>, changes: list<string>, kept: list<string>}
     */
    private function syncState(string $entity, array $designed, array $built, bool $prune): array
    {
        $changes = [];
        $kept = [];
        $state = [];

        foreach ($built as $property => $type) {
            $change = $this->change("entities.{$entity}.state.{$property}", $designed[$property] ?? null, $type);

            if ($change !== null) {
                $changes[] = $change;
            }

            $state[$property] = $type;
        }

        $designedOrder = array_values(array_filter(array_map(strval(...), array_keys($designed)), fn (string $property): bool => array_key_exists($property, $built)));
        $builtOrder = array_values(array_filter(array_keys($built), fn (string $property): bool => array_key_exists($property, $designed)));

        if ($designedOrder !== $builtOrder) {
            $changes[] = sprintf('entities.%s.state: order %s → %s', $entity, implode(', ', $designedOrder), implode(', ', $builtOrder));
        }

        foreach (array_diff_key($designed, $built) as $property => $type) {
            if ($prune) {
                $changes[] = "entities.{$entity}.state.{$property}: removed";
            } else {
                $kept[] = "entities.{$entity}.state.{$property}";
                $state[(string) $property] = $type;
            }
        }

        return ['state' => $state, 'changes' => $changes, 'kept' => $kept];
    }

    /**
     * @param  array<string, mixed>  $designed
     * @param  array<string, mixed>  $built
     * @return array{entities: array<string, mixed>, changes: list<string>, kept: list<string>}
     */
    private function syncEntities(array $designed, array $built, bool $prune): array
    {
        $changes = [];
        $kept = [];

        foreach ($built as $entity => $entry) {
            /** @var array{aggregate: string, state: array<string, string>, behaviours: array<string, mixed>, assertions: array<string, mixed>, table?: array<string, mixed>} $entry */
            $current = $designed[$entity] ?? null;

            if (is_array($current) && $current['aggregate'] !== $entry['aggregate']) {
                $changes[] = sprintf('entities.%s: aggregate %s → %s', $entity, $this->json($current['aggregate']), $this->json($entry['aggregate']));
            }

            $state = $this->syncState($entity, is_array($current['state'] ?? null) ? $current['state'] : [], $entry['state'], $prune);
            $changes = [...$changes, ...$state['changes']];
            $kept = [...$kept, ...$state['kept']];
            $merged = $this->withIntent(['aggregate' => $entry['aggregate'], 'state' => $state['state'], 'behaviours' => [], 'assertions' => []], $current);
            $table = is_array($entry['table'] ?? null) ? $entry['table'] : null;
            $designedTable = is_array($current['table'] ?? null) ? $current['table'] : null;

            if ($table !== null) {
                $change = $this->tableChange("entities.{$entity}.table", $designedTable, $table, StructureFiles::TABLE_KEYS);

                if ($change !== null) {
                    $changes[] = $change;
                }

                $merged['table'] = $table;
            } elseif ($designedTable !== null && $prune) {
                $changes[] = "entities.{$entity}.table: removed";
            } elseif ($designedTable !== null) {
                $kept[] = "entities.{$entity}.table";
                $merged['table'] = $designedTable;
            }

            foreach (['behaviours', 'assertions'] as $group) {
                /** @var array<string, mixed> $methods */
                $methods = is_array($current[$group] ?? null) ? $current[$group] : [];

                foreach ($entry[$group] as $method => $definition) {
                    $change = $this->change("entities.{$entity}.{$method}", $methods[$method] ?? null, $definition);

                    if ($change !== null) {
                        $changes[] = $change;
                    }

                    $methods[$method] = $definition;
                }

                foreach (array_keys(array_diff_key($methods, $entry[$group])) as $method) {
                    if ($prune) {
                        unset($methods[$method]);
                        $changes[] = "entities.{$entity}.{$method}: removed";
                    } else {
                        $kept[] = "entities.{$entity}.{$method}";
                    }
                }

                $merged[$group] = $methods;
            }

            $designed[$entity] = $merged;
        }

        /** @var array<string, array{state: array<string, string>, behaviours: array<string, mixed>, assertions: array<string, mixed>, table?: array<string, mixed>|null}> $unbuilt */
        $unbuilt = array_diff_key($designed, $built);

        foreach ($unbuilt as $entity => $entry) {
            $paths = [
                ...array_map(fn (int|string $property): string => "entities.{$entity}.state.{$property}", array_keys($entry['state'])),
                ...array_map(fn (int|string $method): string => "entities.{$entity}.{$method}", [...array_keys($entry['behaviours']), ...array_keys($entry['assertions'])]),
                ...(is_array($entry['table'] ?? null) ? ["entities.{$entity}.table"] : []),
            ];

            foreach ($paths as $path) {
                if ($prune) {
                    $changes[] = "{$path}: removed";
                } else {
                    $kept[] = $path;
                }
            }

            if ($prune) {
                unset($designed[$entity]);
            }
        }

        // An entity with no state, no method, no description and no table left has no entry (structure.md).
        return [
            'entities' => array_filter($designed, fn (mixed $entry): bool => is_array($entry) && ! StructureFiles::listsNothing($entry)),
            'changes' => $changes,
            'kept' => $kept,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextManifest(string $context): array
    {
        $manifest = $this->files->exists($context) ? $this->files->read($context) : null;

        return is_array($manifest)
            ? StructureFiles::withDefaults($manifest)
            : ['context' => $context, ...array_fill_keys([...array_keys(StructureFiles::SECTIONS), StructureFiles::TABLES], [])];
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceManifest(string $resource): array
    {
        $manifest = $this->files->resourceExists($resource) ? $this->files->readResource($resource) : null;

        return is_array($manifest)
            ? $manifest
            : ['resource' => $resource, 'model' => null, 'controller' => [], 'actions' => [], 'policy' => null, 'pages' => []];
    }

    /**
     * The children the manifest designs for a built aggregate and the code does not have yet:
     * kit:apply builds them, so a sync keeps them as it keeps any piece not built.
     *
     * @param  array<string, mixed>  $built
     * @return list<string>
     */
    private function designedChildren(array $built, mixed $designed): array
    {
        $children = is_array($designed) && is_array($designed['children'] ?? null) ? $designed['children'] : [];

        return array_values(array_diff($children, is_array($built['children']) ? $built['children'] : []));
    }

    /**
     * The code's entry with what only the manifest says (structure.md, INTENT_KEYS) put back.
     *
     * @param  array<string, mixed>  $built
     * @return array<string, mixed>
     */
    private function withIntent(array $built, mixed $designed): array
    {
        foreach (StructureFiles::INTENT_KEYS as $key) {
            if (is_array($designed) && array_key_exists($key, $designed)) {
                $built[$key] = $designed[$key];
            }
        }

        return $built;
    }

    /**
     * Whether a piece is replacing another, or is being replaced: the manifest says what it will
     * be, and the code catches up through kit:apply and kit:retire.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function inReplacement(array $manifest, string $section, string $name): bool
    {
        /** @var array<string, mixed> $entries */
        $entries = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];

        if (is_string($entries[$name]['replaces'] ?? null)) {
            return true;
        }

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['replaces'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every use case that replaces another, as `Context/UseCase`.
     *
     * @return list<string>
     */
    private function replacingUseCases(): array
    {
        $replacers = [];

        foreach ($this->files->contexts() as $context) {
            $manifest = $this->files->read($context);
            $useCases = is_array($manifest) && is_array($manifest['useCases'] ?? null) ? $manifest['useCases'] : [];

            foreach ($useCases as $name => $entry) {
                if (is_array($entry) && is_string($entry['replaces'] ?? null)) {
                    $replacers[] = "{$context}/{$name}";
                }
            }
        }

        return $replacers;
    }

    /**
     * @param  list<string>  $replacers
     */
    private function callsReplacer(mixed $entry, array $replacers): bool
    {
        $useCases = is_array($entry) ? ($entry['useCases'] ?? $entry) : [];

        return is_array($useCases) && array_intersect($useCases, $replacers) !== [];
    }

    /**
     * One line saying how an entry changes, or null when it does not. An entry's keys compare one
     * by one, in any order; the maps inside one (cases, fields, params) keep theirs.
     */
    private function change(string $path, mixed $before, mixed $after): ?string
    {
        if ($before === null) {
            return "{$path}: added";
        }

        if (is_array($before) && is_array($after) && ! array_is_list($before) && ! array_is_list($after)) {
            $keys = [];

            foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $key) {
                if ($this->comparable($before[$key] ?? null) !== $this->comparable($after[$key] ?? null)) {
                    $keys[] = sprintf('%s %s → %s', $key, $this->json($before[$key] ?? null), $this->json($after[$key] ?? null));
                }
            }

            return $keys === [] ? null : $path.': '.implode(', ', $keys);
        }

        return $this->comparable($before) === $this->comparable($after)
            ? null
            : sprintf('%s: %s → %s', $path, $this->json($before), $this->json($after));
    }

    /**
     * A value as the canonical form would write it: lists of names sorted, so a reordered
     * `repositories` or `throws` is no change.
     */
    private function comparable(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value) && array_filter($value, is_string(...)) === $value) {
            sort($value);

            return $value;
        }

        return array_map($this->comparable(...), $value);
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
