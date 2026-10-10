<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Closure;

/**
 * Compares the structure manifest with the code (structure.md), the one comparison both the
 * Architecture check `manifest` and `kit:plan` report, so the check's failures and the plan's
 * "fix by hand" list are the same lines.
 *
 * Each difference names its check:
 * - `files`: a manifest of the wrong shape;
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

        return $differences;
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

        if ($section === 'valueObjects') {
            $keys = array_diff($keys, ['behaviours', 'assertions']);
            $differences = $this->methodDifferences($context, $section, $name, $inCode, $inJson, self::contextNode($context, $section, $name));
        }

        foreach (array_diff($keys, StructureFiles::INTENT_KEYS) as $key) {
            $json = $inJson[$key] ?? null;

            $kind = StructureFiles::SECTIONS[$section][$key];

            if (is_array($json) && $kind === 'transitions') {
                $json = StructureFiles::orderedTransitions($json, is_array($inJson['cases'] ?? null) ? $inJson['cases'] : []);
            } elseif (is_array($json) && ! StructureFiles::isMap($kind)) {
                sort($json);
            }

            if ($section === 'services' && $key === 'exception' && $json === true && ($inCode[$key] ?? null) === false) {
                $differences[] = $this->difference('matches', $this->files->relativePath($context), sprintf(
                    'services.%s.exception is true in the manifest, but the code has no %sException beside the service — write it by hand, extending DomainException (make:domain-service adds one only to a service it builds)',
                    $name,
                    $name,
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

        $differences = [...$differences, ...$this->stateDifferences($context, $name, $inCode, $inJson, $node)];

        return [...$differences, ...$this->methodDifferences($context, 'entities', $name, $inCode, $inJson, $node)];
    }

    /**
     * The methods of one entity or value object that only the code has, only the manifest has, or
     * that the two describe differently, each told apart.
     *
     * @param  array<string, mixed>|null  $inCode
     * @param  array<string, mixed>|null  $inJson
     * @return list<array{check: string, subject: string, message: string, node: string|null}>
     */
    private function methodDifferences(string $context, string $section, string $name, ?array $inCode, ?array $inJson, ?string $node): array
    {
        $differences = [];
        $file = $this->files->relativePath($context);

        foreach (['behaviours', 'assertions'] as $group) {
            /** @var array<string, array{params: array<string, string>, throws: list<string>}> $code */
            $code = is_array($inCode[$group] ?? null) ? $inCode[$group] : [];
            /** @var array<string, array{params: array<string, string>, throws: list<string>}> $json */
            $json = is_array($inJson[$group] ?? null) ? $inJson[$group] : [];

            foreach ($code as $method => $entry) {
                if (! array_key_exists($method, $json)) {
                    $differences[] = $this->difference('in-json', $this->reader->classOf($context, $section, $name).'::'.$method, sprintf(
                        'is not in %s — add it under "%s.%s.%s" (`%s --context=%s --sync` adds it, keeping what is not built yet)',
                        $file,
                        $section,
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
                            '%s.%s.%s.%s is %s in the code but %s in the manifest',
                            $section,
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
                $differences[] = $this->difference('in-code', $file, sprintf('lists %s.%s.%s, which the code does not have yet — build it, or take it out of the manifest', $section, $name, $method), $node);
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
     * the code and the manifest compare entry by entry.
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
        return sprintf('%s:%s/%s', ['aggregates' => 'aggregate', 'services' => 'service', 'ports' => 'port', 'enums' => 'enum', 'valueObjects' => 'valueObject', 'exceptions' => 'exception', 'entities' => 'entity'][$section] ?? 'useCase', $context, $name);
    }

    /**
     * The graph node of a resource entry as resourceEntries() names it: `model`, `policy`,
     * `controller.{method}`, `actions.{Verb}` or `pages.{path}`.
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
