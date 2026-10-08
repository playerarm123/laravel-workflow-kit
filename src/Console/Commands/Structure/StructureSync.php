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

        return [
            'manifest' => $manifest,
            'changes' => [...$changes, ...$entities['changes']],
            'kept' => [...$kept, ...$entities['kept']],
        ];
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

        return ['manifest' => $manifest, 'changes' => $changes, 'kept' => $kept];
    }

    /**
     * The built pieces of a context whose manifest entry the code describes another way, as the
     * paths a sync would change: `{section}.{name}`, or `entities.{Entity}.{method}` for a method.
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

        $manifest[$section][$name] = $this->withIntent($built, $manifest[$section][$name] ?? null);

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

            $entry = $manifest['entities'][$entity] ?? ['aggregate' => $built['aggregate'], 'behaviours' => [], 'assertions' => []];
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
     * @param  array<string, mixed>  $designed
     * @param  array<string, mixed>  $built
     * @return array{entities: array<string, mixed>, changes: list<string>, kept: list<string>}
     */
    private function syncEntities(array $designed, array $built, bool $prune): array
    {
        $changes = [];
        $kept = [];

        foreach ($built as $entity => $entry) {
            /** @var array{aggregate: string, behaviours: array<string, mixed>, assertions: array<string, mixed>} $entry */
            $current = $designed[$entity] ?? null;

            if (is_array($current) && $current['aggregate'] !== $entry['aggregate']) {
                $changes[] = sprintf('entities.%s: aggregate %s → %s', $entity, $this->json($current['aggregate']), $this->json($entry['aggregate']));
            }

            $merged = ['aggregate' => $entry['aggregate'], 'behaviours' => [], 'assertions' => []];

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

        /** @var array<string, array{behaviours: array<string, mixed>, assertions: array<string, mixed>}> $unbuilt */
        $unbuilt = array_diff_key($designed, $built);

        foreach ($unbuilt as $entity => $entry) {
            foreach ([...array_keys($entry['behaviours']), ...array_keys($entry['assertions'])] as $method) {
                if ($prune) {
                    $changes[] = "entities.{$entity}.{$method}: removed";
                } else {
                    $kept[] = "entities.{$entity}.{$method}";
                }
            }

            if ($prune) {
                unset($designed[$entity]);
            }
        }

        // An entity with no method left has no entry (structure.md).
        return [
            'entities' => array_filter($designed, fn (mixed $entry): bool => is_array($entry) && ($entry['behaviours'] !== [] || $entry['assertions'] !== [])),
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
            : ['context' => $context, ...array_fill_keys(array_keys(StructureFiles::SECTIONS), [])];
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
