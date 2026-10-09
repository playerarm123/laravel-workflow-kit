<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

/**
 * The structure manifest as the graphs the kit's structure screen draws (structure.md): one of
 * the whole project, one per context and one per HTTP resource. The screen only lays them out, so
 * every node, edge and status is decided here, where it is tested.
 *
 * A node's status comes from the plan's steps that build it and the comparer's differences about
 * it: `differs` (the code is built another way) first, then `ready` when any step can run now,
 * `waiting`, and `done`. A piece the code lacks, or builds another way, counts as differing only
 * once no step is left to build it: until then, as while a replacement is under way, the plan
 * says how it gets there.
 *
 * @phpstan-import-type Step from StructurePlanner
 *
 * @phpstan-type Node array{id: string, kind: string, label: string, items: list<string>, status: string|null, reason: string|null, command: string|null, target: array{view: string, name: string}|null, editable: bool, variant: string|null}
 * @phpstan-type Edge array{id: string, source: string, target: string, label: string|null}
 * @phpstan-type View array{nodes: list<Node>, edges: list<Edge>}
 * @phpstan-type Difference array{check: string, subject: string, message: string, node: string|null}
 */
final class StructureGraph
{
    public const string DIFFERS = 'differs';

    /**
     * Each status by how much it needs a person, least first.
     */
    private const array RANK = [StructurePlanner::DONE => 0, StructurePlanner::READY => 1, StructurePlanner::WAITING => 2, self::DIFFERS => 3];

    /**
     * Among the steps that build one piece, a step that can run now outranks one waiting on it,
     * so the card shows the command that moves it on.
     */
    private const array STEP_RANK = [StructurePlanner::DONE => 0, StructurePlanner::WAITING => 1, StructurePlanner::READY => 2, self::DIFFERS => 3];

    /**
     * @var array<string, array{status: string, reason: string|null, command: string|null}>
     */
    private array $statuses = [];

    public function __construct(
        private readonly StructureReader $reader,
        private readonly StructureFiles $files,
        private readonly StructurePlanner $planner,
        private readonly StructureComparer $comparer,
    ) {}

    /**
     * `manifests` and `versions` are the context manifests as they are on disk, which the screen's
     * forms start from and send back (StructureEditor), and `resourceManifests` and
     * `resourceVersions` the same for HTTP resources (StructureResourceEditor). `resourceBuilt`
     * names each resource entry the code already has, so the screen offers to change only the rest,
     * and `entityMethodsBuilt` names each entity method the code already has, as `{Entity}.{method}`,
     * and `childrenBuilt` each child entity, as `{Aggregate}.{Child}`.
     * `outOfStep` and `resourceOutOfStep` name each built entry the code describes another way, by
     * the path StructureSync takes it back from the code with.
     *
     * @return array{overview: View, contexts: array<string, View>, resources: array<string, View>, byHand: list<Difference>, manifests: array<string, array<string, mixed>>, versions: array<string, string>, resourceManifests: array<string, array<string, mixed>>, resourceVersions: array<string, string>, resourceBuilt: array<string, list<string>>, entityMethodsBuilt: array<string, list<string>>, childrenBuilt: array<string, list<string>>, outOfStep: array<string, list<string>>, resourceOutOfStep: array<string, list<string>>}
     */
    public function graph(): array
    {
        $differences = $this->comparer->differences();
        $this->statuses = $this->statuses($this->planner->steps(), $differences);

        $contexts = [];
        $contextManifests = [];
        $versions = [];
        $methodsBuilt = [];
        $childrenBuilt = [];
        $outOfStep = [];
        $sync = new StructureSync($this->files, $this->reader);

        foreach ($this->files->contexts() as $context) {
            $manifest = $this->files->read($context);

            if (is_array($manifest) && $this->files->problems($context, $manifest) === []) {
                $contexts[$context] = $this->contextView($context, $manifest);
                $contextManifests[$context] = $manifest;
                $versions[$context] = $this->files->version($context);
                $methodsBuilt[$context] = $this->builtMethods($context);
                $childrenBuilt[$context] = $this->builtChildren($context);
                $outOfStep[$context] = $sync->contextOutOfStep($context);
            }
        }

        $resources = [];
        $manifests = [];
        $resourceVersions = [];
        $resourceBuilt = [];
        $resourceOutOfStep = [];

        foreach ($this->files->resources() as $resource) {
            $manifest = $this->files->readResource($resource);

            if (is_array($manifest) && $this->files->resourceProblems($resource, $manifest) === []) {
                $resourceBuilt[$resource] = $this->builtEntries($resource);
                $resources[$resource] = $this->resourceView($resource, $manifest, $resourceBuilt[$resource]);
                $manifests[$resource] = $manifest;
                $resourceVersions[$resource] = $this->files->resourceVersion($resource);
                $resourceOutOfStep[$resource] = $sync->resourceOutOfStep($resource);
            }
        }

        return [
            'overview' => $this->overview($contexts, $resources, $manifests),
            'contexts' => $contexts,
            'resources' => $resources,
            'byHand' => array_values(array_filter($differences, fn (array $difference): bool => $difference['node'] === null)),
            'manifests' => $contextManifests,
            'versions' => $versions,
            'resourceManifests' => $manifests,
            'resourceVersions' => $resourceVersions,
            'resourceBuilt' => $resourceBuilt,
            'entityMethodsBuilt' => $methodsBuilt,
            'childrenBuilt' => $childrenBuilt,
            'outOfStep' => $outOfStep,
            'resourceOutOfStep' => $resourceOutOfStep,
        ];
    }

    /**
     * The child entities of a context's aggregates the code already has, as `{Aggregate}.{Child}`.
     *
     * @return list<string>
     */
    private function builtChildren(string $context): array
    {
        $built = [];

        foreach ($this->reader->read($context)['aggregates'] as $aggregate => $entry) {
            foreach ($entry['children'] as $child) {
                $built[] = "{$aggregate}.{$child}";
            }
        }

        return $built;
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<Difference>  $differences
     * @return array<string, array{status: string, reason: string|null, command: string|null}>
     */
    private function statuses(array $steps, array $differences): array
    {
        $statuses = [];

        foreach ($steps as $step) {
            foreach ($step['nodes'] as $node) {
                $statuses[$node] = $this->worse(self::STEP_RANK, $statuses[$node] ?? null, [
                    'status' => $step['state'],
                    'reason' => $step['reason'],
                    'command' => $step['state'] === StructurePlanner::READY ? StructurePlanner::describe($step) : null,
                ]);
            }
        }

        foreach ($differences as $difference) {
            $node = $difference['node'];
            $built = ($statuses[$node ?? ''] ?? null) === null || $statuses[$node ?? '']['status'] === StructurePlanner::DONE;

            if ($node !== null && in_array($difference['check'], ['matches', 'in-code'], true) && $built) {
                $statuses[$node] = $this->worse(self::RANK, $statuses[$node] ?? null, ['status' => self::DIFFERS, 'reason' => $difference['message'], 'command' => null]);
            }
        }

        return $statuses;
    }

    /**
     * @param  array<string, int>  $rank
     * @param  array{status: string, reason: string|null, command: string|null}|null  $current
     * @param  array{status: string, reason: string|null, command: string|null}  $next
     * @return array{status: string, reason: string|null, command: string|null}
     */
    private function worse(array $rank, ?array $current, array $next): array
    {
        return $current === null || $rank[$next['status']] > $rank[$current['status']] ? $next : $current;
    }

    /**
     * @param  array<string, View>  $contexts
     * @param  array<string, View>  $resources
     * @param  array<string, array<string, mixed>>  $manifests
     * @return View
     */
    private function overview(array $contexts, array $resources, array $manifests): array
    {
        $nodes = [];
        $edges = [];

        foreach ($contexts as $context => $view) {
            $nodes[] = $this->summary("context:{$context}", 'context', $context, $view, ['view' => 'context', 'name' => $context]);

            foreach ($view['nodes'] as $node) {
                if ($node['kind'] === 'external' && $node['target'] !== null && isset($contexts[$node['target']['name']])) {
                    $edges[] = $this->edge("context:{$context}", "context:{$node['target']['name']}", 'uses');
                }
            }
        }

        foreach ($resources as $resource => $view) {
            $nodes[] = $this->summary("resource:{$resource}", 'resource', $resource, $view, ['view' => 'resource', 'name' => $resource]);
            $calls = [];

            foreach ($this->useCasesOf($manifests[$resource]) as $useCase) {
                $calls[explode('/', $useCase)[0]][$useCase] = true;
            }

            foreach ($calls as $context => $useCases) {
                if (isset($contexts[$context])) {
                    $edges[] = $this->edge("resource:{$resource}", "context:{$context}", count($useCases) === 1 ? '1 use case' : count($useCases).' use cases');
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $this->unique($edges)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return View
     */
    private function contextView(string $context, array $manifest): array
    {
        $nodes = [];
        $edges = [];
        $external = [];
        $built = $this->reader->read($context);

        /** @var array<string, array{children: list<string>, repository: bool}> $aggregates */
        $aggregates = $manifest['aggregates'];

        foreach ($aggregates as $aggregate => $entry) {
            $nodes[] = $this->node("aggregate:{$context}/{$aggregate}", 'aggregate', $aggregate, [
                ...array_map(fn (string $child): string => "child {$child}", $entry['children']),
                ...($entry['repository'] ? ['repository'] : []),
            ], editable: ! isset($built['aggregates'][$aggregate]));
        }

        $reference = function (string $source, string $aggregate, ?string $label) use ($context, $aggregates, &$edges, &$external): void {
            if (! str_contains($aggregate, '/') && isset($aggregates[$aggregate])) {
                $edges[] = $this->edge($source, "aggregate:{$context}/{$aggregate}", $label);

                return;
            }

            [$owner, $name] = str_contains($aggregate, '/') ? explode('/', $aggregate, 2) : [$context, $aggregate];
            $external["external:{$owner}/{$name}"] = $this->node("external:{$owner}/{$name}", 'external', "{$owner} / {$name}", [], ['view' => 'context', 'name' => $owner]);
            $edges[] = $this->edge($source, "external:{$owner}/{$name}", $label);
        };

        /** @var array<string, array{shape: string, creates: string|null, repositories: list<string>, replaces?: string|null}> $services */
        $services = $manifest['services'];

        foreach ($services as $service => $entry) {
            $id = "service:{$context}/{$service}";
            $nodes[] = $this->node($id, 'service', $service, [
                $entry['shape'],
                ...(is_string($entry['replaces'] ?? null) ? ['replaces '.$entry['replaces']] : []),
            ], editable: ! isset($built['services'][$service]));

            if (is_string($entry['replaces'] ?? null) && isset($services[$entry['replaces']])) {
                $edges[] = $this->edge($id, "service:{$context}/{$entry['replaces']}", 'replaces');
            }

            if ($entry['creates'] !== null) {
                $reference($id, $entry['creates'], 'creates');
            }

            foreach ($entry['repositories'] as $repository) {
                $reference($id, $repository, null);
            }
        }

        /** @var array<string, array{layer: string, adapter: string|null, replaces?: string|null}> $ports */
        $ports = $manifest['ports'];

        foreach ($ports as $port => $entry) {
            $nodes[] = $this->node("port:{$context}/{$port}", 'port', $port, [
                $entry['layer'],
                $entry['adapter'] === null ? 'no adapter' : 'adapter '.$entry['adapter'],
                ...(is_string($entry['replaces'] ?? null) ? ['replacing adapter '.$entry['replaces']] : []),
            ], editable: ! isset($built['ports'][$port]));
        }

        /** @var array<string, array{shape: string, returns: string, creates: bool, query: bool, repositories: list<string>, replaces?: string|null}> $useCases */
        $useCases = $manifest['useCases'];

        foreach ($useCases as $useCase => $entry) {
            $id = "useCase:{$context}/{$useCase}";
            $nodes[] = $this->node($id, 'useCase', $useCase, [
                $entry['shape'],
                'returns '.$entry['returns'],
                ...($entry['creates'] ? ['creates'] : []),
                ...($entry['query'] ? ['query'] : []),
                ...(is_string($entry['replaces'] ?? null) ? ['replaces '.$entry['replaces']] : []),
            ], editable: ! isset($built['useCases'][$useCase]), variant: $entry['query'] ? 'query' : null);

            if (is_string($entry['replaces'] ?? null) && isset($useCases[$entry['replaces']])) {
                $edges[] = $this->edge($id, "useCase:{$context}/{$entry['replaces']}", 'replaces');
            }

            foreach ($entry['repositories'] as $repository) {
                $reference($id, $repository, null);
            }
        }

        [$vocabularyNodes, $vocabularyEdges, $vocabularyExternal] = $this->vocabulary($context, $manifest, $built);
        [$exceptionNodes, $exceptionEdges] = $this->exceptions($context, $manifest, $built);
        [$entityNodes, $entityEdges, $entityExternal] = $this->entities($context, $manifest);

        return [
            'nodes' => [...$nodes, ...$vocabularyNodes, ...$exceptionNodes, ...$entityNodes, ...array_values([...$external, ...$vocabularyExternal, ...$entityExternal])],
            'edges' => $this->unique([...$edges, ...$vocabularyEdges, ...$exceptionEdges, ...$entityEdges]),
        ];
    }

    /**
     * A context's enums and value objects: each tied to the aggregate that holds it, and each value
     * object to the classes its fields name. A class of another context or of the shared kernel is
     * drawn as a card that opens it; a builtin or a class outside the domain gets no line.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $built  what the code holds, as StructureReader::read() reads it
     * @return array{0: list<Node>, 1: list<Edge>, 2: array<string, Node>}
     */
    private function vocabulary(string $context, array $manifest, array $built): array
    {
        $nodes = [];
        $edges = [];
        $external = [];

        /** @var array<string, array{children: list<string>, repository: bool}> $aggregates */
        $aggregates = $manifest['aggregates'];
        /** @var array<string, array{aggregate: string|null, backing: string|null, cases: array<string, string|int|null>, transitions?: array<string, list<string>>|null}> $enums */
        $enums = $manifest['enums'];
        /** @var array<string, array{aggregate: string|null, fields: array<string, string>}> $valueObjects */
        $valueObjects = $manifest['valueObjects'];
        /** @var array<string, mixed> $builtEnums */
        $builtEnums = $built['enums'] ?? [];
        /** @var array<string, mixed> $builtValueObjects */
        $builtValueObjects = $built['valueObjects'] ?? [];

        $holder = function (string $id, ?string $aggregate) use ($context, $aggregates, &$edges): void {
            if ($aggregate !== null && isset($aggregates[$aggregate])) {
                $edges[] = $this->edge("aggregate:{$context}/{$aggregate}", $id, 'vocabulary');
            }
        };

        foreach ($enums as $enum => $entry) {
            $id = StructureComparer::contextNode($context, 'enums', $enum);
            $transitions = $entry['transitions'] ?? null;
            $lines = is_array($transitions) ? $this->moves($transitions) : [];

            if (! is_array($transitions)) {
                foreach ($entry['cases'] as $case => $value) {
                    $lines[] = $value === null ? (string) $case : "{$case} = {$value}";
                }
            }

            $nodes[] = $this->node($id, 'enum', $enum, $this->capped([$entry['backing'] ?? 'pure', ...$lines]), editable: ! isset($builtEnums[$enum]), variant: is_array($transitions) ? 'status' : null);
            $holder($id, $entry['aggregate']);
        }

        foreach ($valueObjects as $valueObject => $entry) {
            $id = StructureComparer::contextNode($context, 'valueObjects', $valueObject);
            $fields = [];

            foreach ($entry['fields'] as $field => $type) {
                $fields[] = "{$field}: {$type}";

                foreach (preg_split('/[|&]/', ltrim($type, '?')) ?: [] as $part) {
                    $target = $this->typeTarget($context, $part, $enums, $valueObjects, $aggregates);

                    if ($target === null) {
                        continue;
                    }

                    if (str_starts_with($target, 'external:')) {
                        $owner = explode('/', substr($target, strlen('external:')))[0];
                        $external[$target] = $this->node($target, 'external', str_replace('/', ' / ', substr($target, strlen('external:'))), [], ['view' => 'context', 'name' => $owner]);
                    }

                    $edges[] = $this->edge($id, $target, (string) $field);
                }
            }

            $nodes[] = $this->node($id, 'valueObject', $valueObject, $this->capped($fields), editable: ! isset($builtValueObjects[$valueObject]));
            $holder($id, $entry['aggregate']);
        }

        return [$nodes, $edges, $external];
    }

    /**
     * A context's exceptions (exceptions.md), each tied to what refuses with it: an aggregate's
     * refusal or invalid value to the aggregate, a use case's refusal to its use case.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $built  what the code holds, as StructureReader::read() reads it
     * @return array{0: list<Node>, 1: list<Edge>}
     */
    private function exceptions(string $context, array $manifest, array $built): array
    {
        $nodes = [];
        $edges = [];

        /** @var array<string, array{kind: string, aggregate: string|null, useCase: string|null}> $exceptions */
        $exceptions = $manifest['exceptions'];
        /** @var array<string, mixed> $builtExceptions */
        $builtExceptions = $built['exceptions'] ?? [];

        foreach ($exceptions as $exception => $entry) {
            $id = StructureComparer::contextNode($context, 'exceptions', $exception);
            $nodes[] = $this->node($id, 'exception', $exception, [
                ['refusal' => 'refusal', 'value' => 'invalid value', 'application' => 'use case refusal'][$entry['kind']] ?? $entry['kind'],
                ...($entry['aggregate'] !== null ? ["in {$entry['aggregate']}"] : []),
                ...($entry['useCase'] !== null ? ["of {$entry['useCase']}"] : []),
            ], editable: ! isset($builtExceptions[$exception]), variant: $entry['kind']);

            if ($entry['aggregate'] !== null && isset($manifest['aggregates'][$entry['aggregate']])) {
                $edges[] = $this->edge("aggregate:{$context}/{$entry['aggregate']}", $id, $entry['kind'] === 'value' ? 'rejects' : 'refuses');
            }

            if ($entry['useCase'] !== null && isset($manifest['useCases'][$entry['useCase']])) {
                $edges[] = $this->edge("useCase:{$context}/{$entry['useCase']}", $id, 'refuses');
            }
        }

        return [$nodes, $edges];
    }

    /**
     * A context's entities that list methods: each tied to the aggregate that holds it, as its root
     * or a child, and to the classes its methods' parameters name, each line labelled with the
     * method. A card is never editable as a whole: the screen changes one method at a time, and
     * `entityMethodsBuilt` says which ones the code already has.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{0: list<Node>, 1: list<Edge>, 2: array<string, Node>}
     */
    private function entities(string $context, array $manifest): array
    {
        $nodes = [];
        $edges = [];
        $external = [];

        /** @var array<string, array{children: list<string>, repository: bool}> $aggregates */
        $aggregates = $manifest['aggregates'];
        /** @var array<string, mixed> $enums */
        $enums = $manifest['enums'];
        /** @var array<string, mixed> $valueObjects */
        $valueObjects = $manifest['valueObjects'];
        /** @var array<string, array{aggregate: string, behaviours: array<string, array{params: array<string, string>, throws: list<string>}>, assertions: array<string, array{params: array<string, string>, throws: list<string>}>}> $entities */
        $entities = $manifest['entities'];

        foreach ($entities as $entity => $entry) {
            $id = StructureComparer::contextNode($context, 'entities', $entity);
            $lines = [];

            foreach ([...$entry['behaviours'], ...$entry['assertions']] as $method => $definition) {
                $lines[] = $method.'('.implode(', ', $definition['params']).')';

                foreach ($definition['throws'] as $exception) {
                    $target = $this->exceptionTarget($context, $entry['aggregate'], $exception, $manifest);

                    if ($target === null) {
                        continue;
                    }

                    if (str_starts_with($target, 'external:')) {
                        $owner = explode('/', substr($target, strlen('external:')))[0];
                        $external[$target] = $this->node($target, 'external', str_replace('/', ' / ', substr($target, strlen('external:'))), [], ['view' => 'context', 'name' => $owner]);
                    }

                    $edges[] = $this->edge($id, $target, (string) $method);
                }

                foreach ($definition['params'] as $type) {
                    foreach (preg_split('/[|&]/', ltrim((string) preg_replace('/^\.\.\./', '', $type), '?')) ?: [] as $part) {
                        $target = $this->typeTarget($context, $part, $enums, $valueObjects, $aggregates);

                        if ($target === null || $target === $id) {
                            continue;
                        }

                        if (str_starts_with($target, 'external:')) {
                            $owner = explode('/', substr($target, strlen('external:')))[0];
                            $external[$target] = $this->node($target, 'external', str_replace('/', ' / ', substr($target, strlen('external:'))), [], ['view' => 'context', 'name' => $owner]);
                        }

                        $edges[] = $this->edge($id, $target, (string) $method);
                    }
                }
            }

            $nodes[] = $this->node($id, 'entity', $entity, $this->capped($lines));

            if (isset($aggregates[$entry['aggregate']])) {
                $edges[] = $this->edge("aggregate:{$context}/{$entry['aggregate']}", $id, $entity === $entry['aggregate'] ? 'root' : 'child');
            }
        }

        return [$nodes, $edges, $external];
    }

    /**
     * The card a method's exception points at: a designed exception of this context, a card that
     * opens the context of one designed elsewhere, or null for one no manifest designs.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function exceptionTarget(string $context, string $aggregate, string $exception, array $manifest): ?string
    {
        $segments = explode('/', $exception);
        $name = (string) array_pop($segments);
        $owner = $segments === [] ? $context : $segments[0];
        $holder = $segments[1] ?? ($segments === [] ? $aggregate : null);

        if ($owner === $context) {
            $entry = $manifest['exceptions'][$name] ?? null;

            return is_array($entry) && $entry['aggregate'] === $holder ? StructureComparer::contextNode($context, 'exceptions', $name) : null;
        }

        $elsewhere = $this->files->exists($owner) ? $this->files->read($owner) : null;

        return is_array($elsewhere) && is_array($elsewhere['exceptions'][$name] ?? null) ? 'external:'.$exception : null;
    }

    /**
     * The card a field's type points at, or null for a builtin, a class outside the domain, or a
     * name the manifest does not have.
     *
     * @param  array<string, mixed>  $enums
     * @param  array<string, mixed>  $valueObjects
     * @param  array<string, array{children: list<string>, repository: bool}>  $aggregates
     */
    private function typeTarget(string $context, string $type, array $enums, array $valueObjects, array $aggregates): ?string
    {
        if (in_array($type, StructureReader::BUILTIN_TYPES, true)) {
            return null;
        }

        $segments = explode('/', $type);

        if (count($segments) > 1) {
            return $segments[0] === $context ? $this->typeTarget($context, (string) end($segments), $enums, $valueObjects, $aggregates) : 'external:'.$type;
        }

        if (isset($enums[$type])) {
            return StructureComparer::contextNode($context, 'enums', $type);
        }

        if (isset($valueObjects[$type])) {
            return StructureComparer::contextNode($context, 'valueObjects', $type);
        }

        $entity = str_ends_with($type, 'Entity') ? substr($type, 0, -strlen('Entity')) : null;

        foreach ($aggregates as $aggregate => $entry) {
            if ($entity === $aggregate || in_array($entity, $entry['children'], true)) {
                return "aggregate:{$context}/{$aggregate}";
            }
        }

        return null;
    }

    /**
     * A status's cases, each with the cases it may become, or marked final when it becomes none.
     *
     * @param  array<string, list<string>>  $transitions
     * @return list<string>
     */
    private function moves(array $transitions): array
    {
        $lines = [];

        foreach ($transitions as $case => $next) {
            $lines[] = $next === [] ? "{$case} · final" : "{$case} → ".implode(', ', $next);
        }

        return $lines;
    }

    /**
     * A card's lines, cut at six: the first five and how many more there are.
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    private function capped(array $items): array
    {
        return count($items) <= 6 ? $items : [...array_slice($items, 0, 5), '… '.(count($items) - 5).' more'];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $built  the entries the code already has
     * @return View
     */
    private function resourceView(string $resource, array $manifest, array $built): array
    {
        $nodes = [];
        $edges = [];
        $external = [];

        $call = function (string $source, string $useCase, ?string $label) use (&$edges, &$external): void {
            [$context, $name] = explode('/', $useCase, 2) + [1 => ''];
            $external["external:{$useCase}"] = $this->node("external:{$useCase}", 'external', "{$context} / {$name}", [], ['view' => 'context', 'name' => $context]);
            $edges[] = $this->edge($source, "external:{$useCase}", $label);
        };

        if (is_string($manifest['model'])) {
            $nodes[] = $this->node("model:{$resource}", 'model', $manifest['model'], [], editable: ! in_array('model', $built, true));
        }

        if (is_array($manifest['policy'])) {
            /** @var list<string> $abilities */
            $abilities = $manifest['policy'];
            sort($abilities);
            $nodes[] = $this->node("policy:{$resource}", 'policy', "{$resource}Policy", $abilities, editable: ! in_array('policy', $built, true));

            if (is_string($manifest['model'])) {
                $edges[] = $this->edge("model:{$resource}", "policy:{$resource}", 'policy');
            }
        }

        /** @var array<string, list<string>> $controller */
        $controller = $manifest['controller'];

        if ($controller !== []) {
            $nodes[] = $this->node("controller:{$resource}", 'controller', "{$resource}Controller", array_keys($controller));

            foreach ($controller as $method => $useCases) {
                foreach ($useCases as $useCase) {
                    $call("controller:{$resource}", $useCase, $method);
                }
            }
        }

        /** @var array<string, array{row: bool, bulk: bool, useCases: list<string>}> $actions */
        $actions = $manifest['actions'];

        foreach ($actions as $verb => $action) {
            $id = "action:{$resource}/{$verb}";
            $nodes[] = $this->node($id, 'action', $verb, [...($action['row'] ? ['row'] : []), ...($action['bulk'] ? ['bulk'] : [])], editable: ! in_array("actions.{$verb}", $built, true));

            foreach ($action['useCases'] as $useCase) {
                $call($id, $useCase, null);
            }
        }

        /** @var array<string, string> $pages */
        $pages = $manifest['pages'];

        foreach ($pages as $page => $kind) {
            $nodes[] = $this->node("page:{$resource}/{$page}", 'page', $page, [$kind], editable: ! in_array("pages.{$page}", $built, true), variant: $kind);

            if ($controller !== []) {
                $edges[] = $this->edge("controller:{$resource}", "page:{$resource}/{$page}", null);
            }
        }

        return ['nodes' => [...$nodes, ...array_values($external)], 'edges' => $this->unique($edges)];
    }

    /**
     * The entries of a resource the code already has, as StructureComparer::resourceEntries()
     * names them: `model`, `policy`, `controller.{method}`, `actions.{Verb}`, `pages.{path}`.
     *
     * @return list<string>
     */
    private function builtEntries(string $resource): array
    {
        $entries = StructureComparer::resourceEntries($this->reader->readResource($resource));

        return array_map(strval(...), array_keys(array_filter($entries, fn (mixed $value): bool => $value !== null)));
    }

    /**
     * The entity methods of a context the code already has, as `{Entity}.{method}`.
     *
     * @return list<string>
     */
    private function builtMethods(string $context): array
    {
        $built = [];

        foreach ($this->reader->read($context)['entities'] as $entity => $entry) {
            foreach (array_keys([...$entry['behaviours'], ...$entry['assertions']]) as $method) {
                $built[] = "{$entity}.{$method}";
            }
        }

        return $built;
    }

    /**
     * Every use case a resource's controller and actions call, as `Context/UseCase`.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function useCasesOf(array $manifest): array
    {
        /** @var array<string, list<string>> $controller */
        $controller = $manifest['controller'];
        /** @var array<string, array{useCases: list<string>}> $actions */
        $actions = $manifest['actions'];

        return [
            ...array_merge([], ...array_values($controller)),
            ...array_merge([], ...array_map(fn (array $action): array => $action['useCases'], array_values($actions))),
        ];
    }

    /**
     * A context or resource on the overview, counting the statuses of the pieces inside it.
     *
     * @param  View  $view
     * @param  array{view: string, name: string}  $target
     * @return Node
     */
    private function summary(string $id, string $kind, string $label, array $view, array $target): array
    {
        $counts = [];
        $worst = null;

        foreach ($view['nodes'] as $node) {
            if ($node['status'] !== null) {
                $counts[$node['status']] = ($counts[$node['status']] ?? 0) + 1;
                $worst = $worst === null || self::RANK[$node['status']] > self::RANK[$worst] ? $node['status'] : $worst;
            }
        }

        $items = [];

        foreach (array_keys(self::RANK) as $status) {
            if (isset($counts[$status])) {
                $items[] = "{$counts[$status]} {$status}";
            }
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'items' => $items,
            'status' => $worst,
            'reason' => null,
            'command' => null,
            'target' => $target,
            'editable' => false,
            'variant' => null,
        ];
    }

    /**
     * @param  list<string>  $items
     * @param  array{view: string, name: string}|null  $target
     * @param  bool  $editable  whether the screen may change it: a piece the code does not have yet
     * @param  string|null  $variant  what sets a node apart within its kind: `query` for a list use case, `status` for an enum that declares its moves, a page's kind
     * @return Node
     */
    private function node(string $id, string $kind, string $label, array $items, ?array $target = null, bool $editable = false, ?string $variant = null): array
    {
        $status = $kind === 'external' ? null : ($this->statuses[$id] ?? ['status' => StructurePlanner::DONE, 'reason' => null, 'command' => null]);

        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'items' => $items,
            'status' => $status['status'] ?? null,
            'reason' => $status['reason'] ?? null,
            'command' => $status['command'] ?? null,
            'target' => $target,
            'editable' => $editable,
            'variant' => $variant,
        ];
    }

    /**
     * @return Edge
     */
    private function edge(string $source, string $target, ?string $label): array
    {
        return ['id' => "{$source}->{$target}:{$label}", 'source' => $source, 'target' => $target, 'label' => $label];
    }

    /**
     * @param  list<Edge>  $edges
     * @return list<Edge>
     */
    private function unique(array $edges): array
    {
        $unique = [];

        foreach ($edges as $edge) {
            $unique[$edge['id']] = $edge;
        }

        return array_values($unique);
    }
}
