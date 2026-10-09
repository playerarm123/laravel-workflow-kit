<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Illuminate\Support\Str;

/**
 * Turns the structure manifest into the steps that build it (structure.md): the `make:*`
 * generator calls, in the order they depend on each other, and the binding and route lines
 * `kit:apply` writes above the project's markers.
 *
 * Every step is `done` when the code already has it, `ready` when it can run now, or `waiting`
 * with the reason, which is code a person has to fill in first (a Command's fields, a list Row's
 * keys). A plan is read from the code as it stands, so planning again after a run picks up where
 * the run stopped. What no generator writes, such as a second repository or a method outside the
 * resource ones, is left to StructureComparer's differences.
 *
 * @phpstan-type Step array{key: string, title: string, state: string, reason: string|null, command: string|null, arguments: array<string, mixed>, marker: string|null, line: string|null, nodes: list<string>, swap: Swap|null}
 * @phpstan-type PlannedStep array{order: int, key: string, title: string, state: string, reason: string|null, command: string|null, arguments: array<string, mixed>, marker: string|null, line: string|null, nodes: list<string>, swap: Swap|null}
 * @phpstan-type Swap array{classes: array<string, string>, directories: list<string>, except: list<string>, names: array<string, string>, nameDirectories: list<string>}
 */
final class StructurePlanner
{
    public const string DONE = 'done';

    public const string READY = 'ready';

    public const string WAITING = 'waiting';

    private const array RESOURCE_METHODS = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

    /**
     * Where a list's TypeScript twins are declared, and where the code that reads them lives.
     */
    public const string TYPES = 'resources/js/types';

    public const array TYPE_READERS = ['resources/js/pages', 'resources/js/components'];

    public function __construct(
        private readonly StructureReader $reader,
        private readonly StructureFiles $files,
        private readonly StructureMarkers $markers,
    ) {}

    /**
     * Every step of the manifests named, or of all of them when none is named, in the order they
     * run.
     *
     * @param  list<string>  $contexts
     * @param  list<string>  $resources
     * @return list<Step>
     */
    public function steps(array $contexts = [], array $resources = []): array
    {
        $everything = $contexts === [] && $resources === [];
        $steps = [];

        foreach ($everything ? $this->files->contexts() : $contexts as $context) {
            $manifest = $this->files->read($context);

            if (is_array($manifest) && $this->files->problems($context, $manifest) === []) {
                $steps = [...$steps, ...$this->contextSteps($context, $manifest)];
            }
        }

        foreach ($everything ? $this->files->resources() : $resources as $resource) {
            $manifest = $this->files->readResource($resource);

            if (is_array($manifest) && $this->files->resourceProblems($resource, $manifest) === []) {
                $steps = [...$steps, ...$this->resourceSteps($resource, $manifest)];
            }
        }

        usort($steps, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(function (array $step): array {
            unset($step['order']);

            return $step;
        }, $steps);
    }

    /**
     * The step as the command line a person would type, or the line it writes above a marker.
     *
     * @param  array{command: string|null, arguments: array<string, mixed>, marker: string|null, line: string|null, swap: Swap|null}  $step
     */
    public static function describe(array $step): string
    {
        if ($step['swap'] !== null) {
            $short = fn (string $class): string => substr($class, (int) strrpos($class, '\\') + 1);

            $describe = sprintf(
                'swap %s in %s',
                implode(', ', array_map(fn (string $old, string $new): string => $short($old).' -> '.$short($new), array_keys($step['swap']['classes']), $step['swap']['classes'])),
                implode(', ', $step['swap']['directories']),
            );

            return $step['swap']['names'] === [] ? $describe : sprintf(
                '%s; %s in %s',
                $describe,
                implode(', ', array_map(fn (string $old, string $new): string => $old.' -> '.$new, array_keys($step['swap']['names']), $step['swap']['names'])),
                implode(', ', $step['swap']['nameDirectories']),
            );
        }

        if ($step['command'] === null) {
            return sprintf('%s  (above %s)', $step['line'], $step['marker']);
        }

        $parts = ['php artisan', $step['command']];

        foreach ($step['arguments'] as $key => $value) {
            if ($key === '--no-interaction') {
                continue;
            }

            if (! str_starts_with((string) $key, '--')) {
                $parts[] = (string) $value;
            } elseif ($value === true) {
                $parts[] = $key;
            } else {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    $parts[] = self::shellWord($key.'='.$item);
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * A word as a shell reads it: quoted only when it holds a character the shell would read itself,
     * such as the `?` and `|` of a field's type.
     */
    private static function shellWord(string $word): string
    {
        return preg_match('#^[\\w./:=,@-]+$#', $word) === 1 ? $word : escapeshellarg($word);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<PlannedStep>
     */
    private function contextSteps(string $context, array $manifest): array
    {
        $steps = [];

        foreach ($manifest['aggregates'] as $aggregate => $entry) {
            $root = "App\\Domain\\{$context}\\{$aggregate}\\{$aggregate}Entity";
            $rootReady = class_exists($root);

            $steps[] = $this->generator(1, "aggregate {$context}/{$aggregate}", $rootReady, null, 'make:entity', ['name' => $aggregate, '--domain' => "{$context}/{$aggregate}"], ["aggregate:{$context}/{$aggregate}", "entity:{$context}/{$aggregate}"]);

            foreach ($entry['children'] as $child) {
                $steps[] = $this->generator(2, "child {$context}/{$aggregate}/{$child}", class_exists("App\\Domain\\{$context}\\{$aggregate}\\Entities\\{$child}Entity"), $rootReady ? null : "the root {$aggregate}Entity comes first", 'make:entity', ['name' => $child, '--domain' => "{$context}/{$aggregate}", '--child' => true], ["aggregate:{$context}/{$aggregate}", "entity:{$context}/{$child}"]);
            }

            if ($entry['repository']) {
                $steps[] = $this->generator(3, "repository {$context}/{$aggregate}", class_exists("App\\Infra\\Persistence\\Eloquent\\Repositories\\Eloquent{$aggregate}Repository"), $rootReady ? null : "the root {$aggregate}Entity comes first", 'make:eloquent-repository', ['name' => $aggregate, '--domain' => "{$context}/{$aggregate}"], ["aggregate:{$context}/{$aggregate}"]);
            }
        }

        /** @var array<string, array{aggregate: string, behaviours: array<string, array{params: array<string, string>, throws: list<string>}>, assertions: array<string, array{params: array<string, string>, throws: list<string>}>}> $entities */
        $entities = $manifest['entities'];

        $planned = [];

        foreach ($this->exceptionSteps($context, $manifest['exceptions']) as $step) {
            $planned[$step['key']] = true;
            $steps[] = $step;
        }

        foreach ($entities as $entity => $entry) {
            foreach ($this->entitySteps($context, $entity, $entry) as $step) {
                if (! isset($planned[$step['key']])) {
                    $planned[$step['key']] = true;
                    $steps[] = $step;
                }
            }
        }

        foreach ($manifest['enums'] as $enum => $entry) {
            $domain = $entry['aggregate'] === null ? $context : "{$context}/{$entry['aggregate']}";
            $arguments = ['name' => $enum, '--domain' => $domain];

            if ($entry['backing'] !== null) {
                $arguments['--'.$entry['backing']] = true;
            }

            $arguments['--case'] = [];

            foreach ($entry['cases'] as $case => $value) {
                $arguments['--case'][] = $value === null ? (string) $case : "{$case}={$value}";
            }

            if (is_array($entry['transitions'] ?? null)) {
                $arguments['--transitions'] = true;
                $arguments['--transition'] = [];

                foreach (StructureFiles::orderedTransitions($entry['transitions'], $entry['cases']) as $case => $next) {
                    if ($next !== []) {
                        $arguments['--transition'][] = $case.':'.implode(',', $next);
                    }
                }
            }

            $steps[] = $this->generator(1, "enum {$context}/{$enum}", enum_exists('App\\Domain\\'.str_replace('/', '\\', $domain)."\\Enums\\{$enum}"), null, 'make:enum', $arguments, [StructureComparer::contextNode($context, 'enums', $enum)]);
        }

        foreach ($manifest['valueObjects'] as $valueObject => $entry) {
            $domain = $entry['aggregate'] === null ? $context : "{$context}/{$entry['aggregate']}";
            $missing = $this->missingTypes($entry['fields'], $context, $entry['aggregate']);
            $fields = [];

            foreach ($entry['fields'] as $field => $type) {
                $fields[] = "{$field}:{$type}";
            }

            $steps[] = $this->generator(
                1,
                "value object {$context}/{$valueObject}",
                class_exists('App\\Domain\\'.str_replace('/', '\\', $domain)."\\ValueObjects\\{$valueObject}"),
                $missing === [] ? null : implode(', ', $missing).(count($missing) === 1 ? ' is' : ' are').' not built yet',
                'make:value-object',
                [
                    'name' => $valueObject,
                    '--domain' => $domain,
                    '--field' => $fields,
                ],
                [StructureComparer::contextNode($context, 'valueObjects', $valueObject)],
            );
        }

        foreach ($manifest['ports'] as $port => $entry) {
            $interface = $entry['layer'] === 'domain' ? "App\\Domain\\{$context}\\Ports\\{$port}" : "App\\Application\\{$context}\\{$port}";
            $arguments = ['name' => $port, $entry['layer'] === 'domain' ? '--domain' : '--application' => $context];
            $adapter = $entry['adapter'] === null ? null : 'App\\'.str_replace('/', '\\', $entry['adapter']);

            if ($adapter !== null && preg_match('#^Infra/(.+)/(\w+)'.preg_quote($port, '#').'$#', $entry['adapter'], $match) === 1) {
                $arguments += ['--adapter' => $match[2], '--infra' => $match[1]];
            }

            $steps[] = $this->generator(4, "port {$context}/{$port}", interface_exists($interface) && ($adapter === null || class_exists($adapter)), null, 'make:port', $arguments, ["port:{$context}/{$port}"]);

            $replaces = is_string($entry['replaces'] ?? null) ? 'App\\'.str_replace('/', '\\', $entry['replaces']) : null;

            if ($adapter !== null && $replaces !== null) {
                $steps[] = $this->bindingSwap($interface, $replaces, $adapter, $entry['replaces'], $port, ["port:{$context}/{$port}"]);
            } elseif ($adapter !== null) {
                $steps[] = $this->binding($interface, $adapter, "binding {$port}", ["port:{$context}/{$port}"]);
            }
        }

        foreach ($manifest['services'] as $service => $entry) {
            $arguments = ['name' => $service, '--domain' => $context];

            match ($entry['shape']) {
                'creates' => $entry['creates'] !== null && ! str_contains($entry['creates'], '/') ? $arguments['--creates'] = $entry['creates'] : $arguments['--plain'] = true,
                'data' => $arguments['--data'] = true,
                default => $arguments['--plain'] = true,
            };

            $repository = $this->ownRepository($entry['repositories']);

            if ($repository !== null && $entry['shape'] !== 'creates') {
                $arguments['--repo'] = $repository;
            }

            $replaces = is_string($entry['replaces'] ?? null) ? $entry['replaces'] : null;

            if (($entry['exception'] ?? false) === true || ($replaces !== null && is_file($this->rootOf()."/app/Domain/{$context}/Services/{$replaces}/{$replaces}Exception.php"))) {
                $arguments['--exception'] = true;
            }

            $steps[] = $this->generator(5, "service {$context}/{$service}", class_exists("App\\Domain\\{$context}\\Services\\{$service}\\{$service}Service"), null, 'make:domain-service', $arguments, ["service:{$context}/{$service}"]);

            if ($replaces !== null) {
                $steps[] = $this->serviceSwap($context, $replaces, $service);
            }
        }

        foreach ($manifest['useCases'] as $useCase => $entry) {
            $arguments = ['name' => $useCase, '--domain' => $context];

            if ($entry['query']) {
                $arguments['--query'] = true;
            } elseif ($entry['shape'] === 'plain') {
                $arguments['--plain'] = true;
            } else {
                $arguments['--command'] = true;

                if ($entry['shape'] === 'command-result') {
                    $arguments['--result'] = true;
                }
            }

            if ($entry['creates']) {
                $arguments['--creates'] = true;
            }

            $repository = $entry['query'] ? null : $this->ownRepository($entry['repositories']);

            if ($repository !== null) {
                $arguments['--repo'] = $repository;
            }

            $steps[] = $this->generator(6, "use case {$context}/{$useCase}", $this->handlerClass($context, $useCase) !== null, null, 'make:use-case', $arguments, ["useCase:{$context}/{$useCase}"]);

            if ($entry['query']) {
                $steps[] = $this->binding(
                    "App\\Application\\{$context}\\UseCases\\{$useCase}\\{$useCase}Query",
                    "App\\Infra\\Persistence\\Eloquent\\Queries\\Eloquent{$useCase}Query",
                    "binding {$useCase}Query",
                    ["useCase:{$context}/{$useCase}"],
                );
            }

            if (is_string($entry['replaces'] ?? null) && $entry['query']) {
                $steps[] = $this->listTypes($context, $entry['replaces'], $useCase);
            }

            if (is_string($entry['replaces'] ?? null)) {
                $steps[] = $this->useCaseSwap($context, $entry['replaces'], $useCase, $entry['query']);
            }
        }

        return $steps;
    }

    /**
     * The steps that build the exceptions a manifest designs (exceptions.md), each with the
     * make:domain-exception its kind takes. An aggregate's refusal or invalid value is keyed as the
     * exception a method's `throws` would build, so a designed one stands in for it. A use case's
     * refusal waits for the use case whose folder it goes in.
     *
     * @param  array<string, array{kind: string, aggregate: string|null, useCase: string|null}>  $exceptions
     * @return list<PlannedStep>
     */
    private function exceptionSteps(string $context, array $exceptions): array
    {
        $steps = [];

        foreach ($exceptions as $exception => $entry) {
            $name = (string) preg_replace('/Exception$/', '', $exception);
            $node = [StructureComparer::contextNode($context, 'exceptions', $exception)];

            if ($entry['kind'] === 'application') {
                $useCase = $entry['useCase'];
                $arguments = ['name' => $name, '--domain' => $context, '--kind' => 'application'];
                $namespace = "App\\Application\\{$context}";

                if ($useCase !== null) {
                    $arguments['--use-case'] = $useCase;
                    $namespace .= "\\UseCases\\{$useCase}";
                }

                $steps[] = $this->generator(
                    7,
                    'exception '.($useCase === null ? $context : "{$context}/{$useCase}")."/{$exception}",
                    class_exists("{$namespace}\\{$exception}"),
                    $useCase !== null && $this->handlerClass($context, $useCase) === null ? "the use case {$context}/{$useCase} comes first" : null,
                    'make:domain-exception',
                    $arguments,
                    $node,
                );

                continue;
            }

            $domain = $context === StructureFiles::SHARED ? StructureFiles::SHARED : "{$context}/{$entry['aggregate']}";

            $steps[] = $this->generator(
                $context === StructureFiles::SHARED ? 1 : 2,
                "exception {$domain}/{$exception}",
                class_exists('App\\Domain\\'.str_replace('/', '\\', $domain)."\\Exceptions\\{$exception}"),
                null,
                'make:domain-exception',
                ['name' => $name, '--domain' => $domain, '--kind' => $entry['kind']],
                $node,
            );
        }

        return $steps;
    }

    /**
     * The steps that build an entity's methods: first each exception of its own aggregate that is
     * not built yet, then each method the entity's file does not declare. Two entities may throw
     * one exception, so the caller keeps one step per key. A method waits for its
     * entity, the types of its parameters and every exception it throws. The file is read, not
     * the class, because the class may already be loaded without the method.
     *
     * @param  array{aggregate: string, behaviours: array<string, array{params: array<string, string>, throws: list<string>}>, assertions: array<string, array{params: array<string, string>, throws: list<string>}>}  $entry
     * @return list<PlannedStep>
     */
    private function entitySteps(string $context, string $entity, array $entry): array
    {
        $aggregate = $entry['aggregate'];
        $domain = "{$context}/{$aggregate}";
        $nodes = [StructureComparer::contextNode($context, 'entities', $entity)];
        $file = $this->rootOf()."/app/Domain/{$domain}/".($entity === $aggregate ? '' : 'Entities/')."{$entity}Entity.php";
        $code = is_file($file) ? StructureFiles::text($file) : null;
        $steps = [];

        foreach ([...$entry['behaviours'], ...$entry['assertions']] as $method => $definition) {
            $missing = $this->missingTypes(array_map(fn (string $type): string => (string) preg_replace('/^\.\.\./', '', $type), $definition['params']), $context, $aggregate);

            foreach ($definition['throws'] as $exception) {
                if ($this->reader->exceptionClass($exception, $context, $aggregate) !== null) {
                    continue;
                }

                $missing[] = $exception;

                if (! str_contains($exception, '/')) {
                    $name = (string) preg_replace('/Exception$/', '', $exception);

                    $steps[$exception] = $this->generator(
                        2,
                        "exception {$domain}/{$exception}",
                        false,
                        $name === $exception ? "{$exception} must end with Exception, the name make:domain-exception writes" : null,
                        'make:domain-exception',
                        ['name' => $name, '--domain' => $domain, '--kind' => 'refusal'],
                        $nodes,
                    );
                }
            }

            $missing = array_values(array_unique($missing));
            $arguments = ['entity' => $entity, 'method' => (string) $method, '--domain' => $domain];

            if ($definition['params'] !== []) {
                $arguments['--param'] = array_map(fn (string $name, string $type): string => "{$name}:{$type}", array_keys($definition['params']), $definition['params']);
            }

            if ($definition['throws'] !== []) {
                $arguments['--throws'] = $definition['throws'];
            }

            $steps[] = $this->generator(
                3,
                "method {$context}/{$entity}::{$method}",
                $code !== null && preg_match('/function\s+'.preg_quote((string) $method, '/').'\s*\(/', $code) === 1,
                match (true) {
                    $code === null => "{$entity}Entity is not built yet",
                    $missing !== [] => implode(', ', $missing).(count($missing) === 1 ? ' is' : ' are').' not built yet',
                    default => null,
                },
                'make:entity-method',
                $arguments,
                $nodes,
            );
        }

        return array_values($steps);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<PlannedStep>
     */
    private function resourceSteps(string $resource, array $manifest): array
    {
        $model = $manifest['model'];

        if ($model === null) {
            return [];
        }

        $steps = [];
        $prefix = Str::kebab(Str::pluralStudly($model));
        $controllerClass = "App\\Http\\Controllers\\{$model}Controller";

        $steps[] = $this->generator(8, "model {$model}", class_exists("App\\Models\\{$model}"), null, 'make:model', ['name' => $model, '--factory' => true], ["model:{$resource}"]);

        if ($manifest['policy'] !== null) {
            $steps[] = $this->generator(8, "policy {$model}", class_exists("App\\Policies\\{$model}Policy"), null, 'make:policy', ['name' => $model], ["policy:{$resource}"]);
        }

        $methods = array_values(array_intersect(self::RESOURCE_METHODS, array_keys($manifest['controller'])));
        $useCases = array_merge([], ...array_values($manifest['controller']));
        $grid = in_array('grid', $manifest['pages'], true);

        if ($methods !== []) {
            $context = $useCases === [] ? null : explode('/', $useCases[0])[0];
            $arguments = ['name' => $model, '--domain' => $context, '--only' => implode(',', $methods)];

            foreach (['index' => ['--list', 'List'.Str::pluralStudly($model)], 'store' => ['--create', "Create{$model}"], 'update' => ['--update', "Update{$model}"], 'destroy' => ['--delete', "Delete{$model}"]] as $method => [$option, $default]) {
                $named = $manifest['controller'][$method][0] ?? null;

                if ($named !== null && explode('/', $named)[1] !== $default) {
                    $arguments[$option] = explode('/', $named)[1];
                }
            }

            if ($grid) {
                $arguments['--grid'] = true;
            }

            $missing = array_values(array_filter($useCases, fn (string $useCase): bool => $this->handlerOf($useCase) === null));
            $done = array_diff($methods, $this->publicMethodsOf($this->rootOf()."/app/Http/Controllers/{$model}Controller.php")) === [];

            $steps[] = $this->generator(9, "controller {$model}", $done, match (true) {
                $context === null => 'no use case names the context the controller belongs to — write it by hand',
                $missing !== [] => 'the use cases it calls come first: '.implode(', ', $missing),
                default => null,
            }, 'make:controller', $arguments, ["controller:{$resource}"]);

            $registered = $this->markers->resourceMethods("{$model}Controller");
            $steps[] = $this->routeStep(
                sprintf("Route::resource('%s', \\%s::class)->only(['%s']);", $prefix, $controllerClass, implode("', '", $methods)),
                '/Route::resource\\([^;]*\\b'.$model.'Controller::class/',
                "route {$prefix}",
                class_exists($controllerClass),
                ["controller:{$resource}"],
                $registered === null ? null : array_diff($methods, $registered) === [],
            );
        }

        foreach ($manifest['actions'] as $verb => $action) {
            $useCase = $action['useCases'][0] ?? null;
            [$context, $name] = $useCase === null ? [null, null] : explode('/', $useCase);
            $reason = match (true) {
                $useCase === null => 'the action names no use case — write it by hand',
                $this->handlerOf($useCase) === null => "the use case {$useCase} comes first",
                ! $this->commandHasFields($useCase) => "fill in the fields of {$name}Command first",
                default => null,
            };
            $slug = Str::kebab($verb);

            foreach (['row' => '', 'bulk' => 'Bulk'] as $kind => $infix) {
                if (! $action[$kind]) {
                    continue;
                }

                $class = "App\\Http\\Controllers\\{$model}{$infix}{$verb}Controller";
                $arguments = ['verb' => $verb, '--model' => $model, '--domain' => $context, '--use-case' => $name];

                if ($kind === 'bulk') {
                    $arguments['--bulk'] = true;
                }

                $steps[] = $this->generator(10, "action {$model}{$infix}{$verb}", class_exists($class), $reason, 'make:action', $arguments, ["action:{$resource}/{$verb}"]);

                $steps[] = $kind === 'row'
                    ? $this->routeStep(
                        sprintf("Route::post('%s/{%s}/%s', \\%s::class)->name('%s.%s');", $prefix, Str::snake($model), $slug, $class, $prefix, $slug),
                        '/\\b'.$model.$verb.'Controller::class/',
                        "route {$prefix}.{$slug}",
                        class_exists($class),
                        ["action:{$resource}/{$verb}"],
                    )
                    : $this->routeStep(
                        sprintf("Route::post('%s/%s', \\%s::class)->name('%s.bulk-%s');", $prefix, $slug, $class, $prefix, $slug),
                        '/\\b'.$model.'Bulk'.$verb.'Controller::class/',
                        "route {$prefix}.bulk-{$slug}",
                        class_exists($class),
                        ["action:{$resource}/{$verb}"],
                    );
            }
        }

        if (in_array('form', $manifest['pages'], true)) {
            $create = $manifest['controller']['store'][0] ?? null;
            $update = $manifest['controller']['update'][0] ?? null;
            $context = $create === null ? null : explode('/', $create)[0];
            $arguments = ['name' => $model, '--domain' => $context];

            if ($create !== null && explode('/', $create)[1] !== "Create{$model}") {
                $arguments['--create'] = explode('/', $create)[1];
            }

            if ($update !== null && explode('/', $update)[1] !== "Update{$model}") {
                $arguments['--update'] = explode('/', $update)[1];
            }

            $formPages = array_keys(array_filter($manifest['pages'], fn (string $kind): bool => $kind === 'form'));
            $formNodes = array_map(fn (string $page): string => "page:{$resource}/{$page}", $formPages);

            $steps[] = $this->generator(11, "form request {$model}", class_exists("App\\Http\\Requests\\{$model}\\Store{$model}Request"), match (true) {
                $create === null => 'store names no use case — write the requests by hand',
                $this->handlerOf($create) === null => "the use case {$create} comes first",
                ! $this->commandHasFields($create) => 'fill in the fields of '.explode('/', $create)[1].'Command first',
                default => null,
            }, 'make:form-request', $arguments, $formNodes);

            $steps[] = $this->generator(13, "form pages {$model}", array_filter($formPages, fn (string $page): bool => ! is_file($this->pagePath($page))) === [], class_exists("App\\Http\\Requests\\{$model}\\{$model}FormValues") ? null : "make:form-request writes {$model}FormValues first", 'make:form-page', ['name' => $model], $formNodes);
        }

        $index = $manifest['controller']['index'][0] ?? null;

        foreach ($manifest['pages'] as $page => $kind) {
            if (! in_array($kind, ['table', 'grid'], true)) {
                continue;
            }

            [$context, $name] = $index === null ? [null, null] : explode('/', $index);
            $arguments = ['name' => $name, '--domain' => $context];

            if ($kind === 'grid') {
                $arguments['--grid'] = true;
            }

            $steps[] = $this->generator(12, "list page {$page}", is_file($this->pagePath($page)), match (true) {
                $index === null => 'index names no list use case — write the page by hand',
                $this->handlerOf($index) === null => "the use case {$index} comes first",
                ! $this->rowHasKeys($index) => "fill in the keys of the {$name} Row first",
                default => null,
            }, 'make:list-page', $arguments, ["page:{$resource}/{$page}"]);
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $nodes  the graph nodes the step builds (StructureGraph)
     * @return PlannedStep
     */
    private function generator(int $order, string $title, bool $done, ?string $waiting, string $command, array $arguments, array $nodes): array
    {
        return [
            'order' => $order,
            'key' => $title,
            'title' => $title,
            'state' => $done ? self::DONE : ($waiting === null ? self::READY : self::WAITING),
            'reason' => $done ? null : $waiting,
            'command' => $command,
            'arguments' => [...$arguments, '--no-interaction' => true],
            'marker' => null,
            'line' => null,
            'nodes' => $nodes,
            'swap' => null,
        ];
    }

    /**
     * @param  list<string>  $nodes
     * @return PlannedStep
     */
    private function binding(string $port, string $adapter, string $title, array $nodes): array
    {
        $line = sprintf('\\%s::class => \\%s::class,', $port, $adapter);
        $done = $this->reader->boundAdapter($port) !== null || $this->markers->contains(StructureMarkers::BINDINGS, $line);
        $ready = interface_exists($port) && class_exists($adapter);

        return [
            'order' => 7,
            'key' => $title,
            'title' => $title,
            'state' => $done ? self::DONE : ($ready ? self::READY : self::WAITING),
            'reason' => $done || $ready ? null : 'the port and its adapter come first',
            'command' => null,
            'arguments' => [],
            'marker' => StructureMarkers::BINDINGS,
            'line' => $line,
            'nodes' => $nodes,
            'swap' => null,
        ];
    }

    /**
     * A route line, done once a route file registers its controller, whatever uri it chose. A
     * resource route is done only when it registers every method the manifest lists
     * (`$covers`); kit:apply widens its `only([...])` otherwise.
     *
     * @param  list<string>  $nodes
     * @return PlannedStep
     */
    private function routeStep(string $line, string $registered, string $title, bool $ready, array $nodes, ?bool $covers = null): array
    {
        $done = $covers ?? $this->markers->matches(StructureMarkers::ROUTES, $registered);

        return [
            'order' => 14,
            'key' => $title,
            'title' => $title,
            'state' => $done ? self::DONE : ($ready ? self::READY : self::WAITING),
            'reason' => $done || $ready ? null : 'its controller comes first',
            'command' => null,
            'arguments' => [],
            'marker' => StructureMarkers::ROUTES,
            'line' => $line,
            'nodes' => $nodes,
            'swap' => null,
        ];
    }

    /**
     * Rebinds a port to the adapter that replaces its old one. It is done once nothing under app/
     * but the old adapter itself names the old adapter, which is how the provider reads once the
     * line is swapped.
     *
     * @param  list<string>  $nodes
     * @return PlannedStep
     */
    private function bindingSwap(string $port, string $old, string $new, string $oldPath, string $name, array $nodes): array
    {
        $except = ["app/{$oldPath}.php"];
        $done = $this->reader->boundAdapter($port) === $new
            || $this->swapper()->references([$old], ['app'], $except) === [];
        $ready = class_exists($new);

        return [
            'order' => 7,
            'key' => "swap binding {$name}",
            'title' => "swap binding {$name}",
            'state' => $done ? self::DONE : ($ready ? self::READY : self::WAITING),
            'reason' => $done || $ready ? null : 'the new adapter comes first',
            'command' => null,
            'arguments' => [],
            'marker' => null,
            'line' => null,
            'nodes' => $nodes,
            'swap' => ['classes' => [$old => $new], 'directories' => ['app'], 'except' => $except, 'names' => [], 'nameDirectories' => []],
        ];
    }

    /**
     * Writes the TypeScript twins of a list that replaces an old one (list-pages.md), from its Row
     * and Criteria, beside the old twins the page still reads until the swap. A grid's twins have
     * no `{Items}Query`, and the old list's twins say which shape the page is.
     *
     * @return PlannedStep
     */
    private function listTypes(string $context, string $old, string $new): array
    {
        $names = self::listNames($new);
        $arguments = ['name' => $new, '--domain' => $context, '--types-only' => true];

        if ($this->swapper()->declaringFile(self::listNames($old)['query'], self::TYPES) === null) {
            $arguments['--grid'] = true;
        }

        return $this->generator(12, "types {$context}/{$new}", $this->swapper()->declaringFile($names['row'], self::TYPES) !== null, match (true) {
            $this->handlerClass($context, $new) === null => "the use case {$new} comes first",
            ! $this->rowHasKeys("{$context}/{$new}") => "fill in the keys of the {$new} Row first",
            default => null,
        }, 'make:list-page', $arguments, ["useCase:{$context}/{$new}"]);
    }

    /**
     * Points the HTTP layer and its tests at the use case that replaces an old one: its Handler,
     * Command and Result, each the class the new use case has in its place. It is done once nothing
     * under app/Http names the old Handler.
     *
     * A list also points its pages and components at the new list's TypeScript twins, once they
     * are written, and is done once nothing there names the old Row's twin either.
     *
     * @return PlannedStep
     */
    private function useCaseSwap(string $context, string $old, string $new, bool $list = false): array
    {
        $oldHandler = $this->handlerClass($context, $old);
        $newHandler = $this->handlerClass($context, $new);
        $classes = [];
        $names = [];

        foreach (['Handler', 'Command', 'Result'] as $suffix) {
            $oldClass = $oldHandler === null ? null : substr($oldHandler, 0, -7).$suffix;
            $newClass = $newHandler === null ? null : substr($newHandler, 0, -7).$suffix;

            if ($oldClass !== null && $newClass !== null && class_exists($oldClass) && class_exists($newClass)) {
                $classes[$oldClass] = $newClass;
            }
        }

        if ($list) {
            $oldNames = self::listNames($old);
            $newNames = self::listNames($new);

            foreach (['row', 'filters', 'query'] as $type) {
                if ($this->swapper()->declaringFile($oldNames[$type], self::TYPES) !== null) {
                    $names[$oldNames[$type]] = $newNames[$type];
                }
            }
        }

        $done = $oldHandler === null || (
            $this->swapper()->references([$oldHandler], ['app/Http']) === []
            && $this->swapper()->nameReferences(array_keys($names), self::TYPE_READERS) === []
        );
        $reason = match (true) {
            $newHandler === null => "the use case {$new} comes first",
            $this->commandHasFields("{$context}/{$old}") && ! $this->commandHasFields("{$context}/{$new}") => "fill in the fields of {$new}Command first, as {$old}Command has them",
            $list && $this->swapper()->declaringFile(self::listNames($new)['row'], self::TYPES) === null => "the TypeScript types of {$new} come first",
            default => null,
        };

        return [
            'order' => 15,
            'key' => "swap {$context}/{$old}",
            'title' => "swap {$old} for {$new}",
            'state' => $done ? self::DONE : ($reason === null ? self::READY : self::WAITING),
            'reason' => $done ? null : $reason,
            'command' => null,
            'arguments' => [],
            'marker' => null,
            'line' => null,
            'nodes' => ["useCase:{$context}/{$new}"],
            'swap' => ['classes' => $classes, 'directories' => ['app/Http', 'tests/Feature/Http'], 'except' => [], 'names' => $names, 'nameDirectories' => $names === [] ? [] : self::TYPE_READERS],
        ];
    }

    /**
     * Points everything that calls a domain service, or names one of its types, at the service
     * that replaces it: each class in the old service's folder becomes its counterpart in the new
     * one, named with the new prefix (`{Old}Data` -> `{New}Data`) or, without the prefix, the
     * same. Every folder under app/ and tests/ but the old service's own is searched, because a
     * service's types may reach a repository, an adapter or a controller's catch.
     *
     * It waits until the new service can stand in: its handle() is written, its Data has fields
     * when the old one has, and every old type named outside its folder has a counterpart. It is
     * done once nothing under app/ names the old service's folder.
     *
     * @return PlannedStep
     */
    private function serviceSwap(string $context, string $old, string $new): array
    {
        $oldFolder = "app/Domain/{$context}/Services/{$old}";
        $newFolder = "app/Domain/{$context}/Services/{$new}";
        $except = [$oldFolder, "tests/Unit/Domain/{$context}/Services/{$old}", "tests/Feature/Domain/{$context}/Services/{$old}"];
        $classes = [];
        $missing = null;

        foreach (StructureSwapper::phpFilesUnder($this->rootOf().'/'.$oldFolder) as $file) {
            $relative = substr($file, strlen($this->rootOf().'/'.$oldFolder) + 1, -4);
            $short = basename($relative);
            $counterpart = (str_starts_with($short, $old) ? $new.substr($short, strlen($old)) : $short);
            $counterpartPath = (dirname($relative) === '.' ? '' : dirname($relative).'/').$counterpart;
            $oldClass = 'App\\'.str_replace('/', '\\', substr($oldFolder, 4).'/'.$relative);
            $newClass = 'App\\'.str_replace('/', '\\', substr($newFolder, 4).'/'.$counterpartPath);

            if (is_file($this->rootOf()."/{$newFolder}/{$counterpartPath}.php")) {
                $classes[$oldClass] = $newClass;
            } elseif ($missing === null && $this->swapper()->references([$oldClass], ['app', 'tests'], $except) !== []) {
                $missing = [$counterpartPath, $relative];
            }
        }

        $service = $this->rootOf()."/{$newFolder}/{$new}Service.php";
        $done = ! is_dir($this->rootOf().'/'.$oldFolder) || $this->swapper()->references(["App\\Domain\\{$context}\\Services\\{$old}\\"], ['app'], [$oldFolder]) === [];
        $reason = match (true) {
            ! is_file($service) => "the service {$new} comes first",
            str_contains(StructureFiles::text($service), '::handle() is not implemented yet.') => "write {$new}Service::handle() first",
            $this->constructorHasFields($this->rootOf()."/{$oldFolder}/{$old}Data.php") && ! $this->constructorHasFields($this->rootOf()."/{$newFolder}/{$new}Data.php") => "fill in the fields of {$new}Data first, as {$old}Data has them",
            $missing !== null => "write {$new}/{$missing[0]} first, as {$old}/{$missing[1]} is still named outside its folder",
            default => null,
        };

        return [
            'order' => 15,
            'key' => "swap service {$context}/{$old}",
            'title' => "swap service {$old} for {$new}",
            'state' => $done ? self::DONE : ($reason === null ? self::READY : self::WAITING),
            'reason' => $done ? null : $reason,
            'command' => null,
            'arguments' => [],
            'marker' => null,
            'line' => null,
            'nodes' => ["service:{$context}/{$new}"],
            'swap' => ['classes' => $classes, 'directories' => ['app', 'tests'], 'except' => $except, 'names' => [], 'nameDirectories' => []],
        ];
    }

    /**
     * The names a list use case gives its pieces, as make:use-case and make:list-page write them:
     * `ListLotteryTypes` reads `LotteryTypeListRow`, sorts by `LotteryTypeListSort`, and its
     * TypeScript twins are `LotteryTypeRow`, `LotteryTypeFilters` and `LotteryTypesQuery`.
     *
     * @return array{item: string, sort: string, row: string, filters: string, query: string}
     */
    public static function listNames(string $useCase): array
    {
        $subjects = substr($useCase, 4);
        $item = Str::singular($subjects);

        return ['item' => $item, 'sort' => "{$item}ListSort", 'row' => "{$item}Row", 'filters' => "{$item}Filters", 'query' => "{$subjects}Query"];
    }

    private function swapper(): StructureSwapper
    {
        return new StructureSwapper($this->rootOf());
    }

    /**
     * The class types a value object's fields name that the code does not have yet, so the
     * value object waits for the enums and value objects it is built from.
     *
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private function missingTypes(array $fields, string $context, ?string $aggregate): array
    {
        $missing = [];

        foreach ($fields as $type) {
            foreach (preg_split('/[|&]/', ltrim($type, '?')) ?: [] as $part) {
                if (! in_array($part, StructureReader::BUILTIN_TYPES, true) && $this->reader->vocabularyClass($part, $context, $aggregate) === null) {
                    $missing[] = $part;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * The first repository of the use case's own context, which is the one a generator's `--repo`
     * can inject.
     *
     * @param  list<string>  $repositories
     */
    private function ownRepository(array $repositories): ?string
    {
        foreach ($repositories as $repository) {
            if (! str_contains($repository, '/')) {
                return $repository;
            }
        }

        return null;
    }

    private function handlerClass(string $context, string $useCase): ?string
    {
        foreach (["App\\Application\\{$context}\\UseCases\\{$useCase}\\{$useCase}Handler", "App\\Application\\{$context}\\UseCases\\{$useCase}Handler"] as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The handler class of a `Context/UseCase` reference, or null when it does not exist.
     */
    private function handlerOf(string $reference): ?string
    {
        [$context, $name] = explode('/', $reference) + [1 => ''];

        return $this->handlerClass($context, $name);
    }

    /**
     * Whether the use case's Command has fields yet. make:use-case writes it with none, and the
     * generators that read it (make:action, make:form-request) need them. It is read from the file,
     * since the class may have been loaded empty earlier in the same run.
     */
    private function commandHasFields(string $reference): bool
    {
        [$context, $name] = explode('/', $reference) + [1 => ''];

        return $this->constructorHasFields($this->rootOf()."/app/Application/{$context}/UseCases/{$name}/{$name}Command.php");
    }

    /**
     * Whether a class file's constructor takes any parameter, read from the file, since the class
     * may have been loaded empty earlier in the same run. A missing file has none.
     */
    private function constructorHasFields(string $file): bool
    {
        if (! is_file($file)) {
            return false;
        }

        $code = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', StructureFiles::text($file));

        return preg_match('/function __construct\s*\(([^)]*)\)/', $code, $match) === 1 && str_contains($match[1], '$');
    }

    /**
     * Whether the list's Row names more than its id. make:use-case --query writes it with `id`
     * alone, and make:list-page writes one column per key.
     */
    private function rowHasKeys(string $reference): bool
    {
        [$context, $name] = explode('/', $reference) + [1 => ''];

        foreach (glob($this->rootOf()."/app/Application/{$context}/UseCases/{$name}/*ListRow.php") ?: [] as $file) {
            if (preg_match('/@return\s+array\{([^}]*)\}/', StructureFiles::text($file), $shape) === 1) {
                $keys = array_filter(array_map(
                    fn (string $pair): string => trim(explode(':', $pair)[0]),
                    explode(',', $shape[1]),
                ));

                return array_diff($keys, ['id']) !== [];
            }
        }

        return false;
    }

    /**
     * The public methods a class file declares, read from the file rather than the loaded class,
     * because a generator may have added some since the class was loaded.
     *
     * @return list<string>
     */
    private function publicMethodsOf(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        preg_match_all('/public function (\w+)\(/', StructureFiles::text($file), $matches);

        return $matches[1];
    }

    private function pagePath(string $page): string
    {
        return $this->rootOf().'/resources/js/pages/'.$page.'.tsx';
    }

    private function rootOf(): string
    {
        return dirname($this->files->path('x'), 3);
    }
}
