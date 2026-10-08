<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * Reads the structure of a project's code into the shape of its manifest (structure.md): each
 * context's aggregates, domain services, ports and use cases for `.kit/structure/{Context}.json`,
 * and each HTTP resource's controller, actions, policy and pages for
 * `.kit/structure/http/{Resource}.json`.
 *
 * Plain PHP over the files and reflection, with no container and no facade, so the Architecture
 * check that compares the code with the JSON runs it without booting the app. `kit:import`
 * writes what it reads, and the check compares with it, so the two never read the code apart.
 *
 * Domain base classes are named as strings, not imported: a console command is an entry point,
 * and layers.md lets an entry point reach the domain for enums, value objects and exceptions only.
 */
final class StructureReader
{
    /**
     * The kit's own folders, which no project manifest lists.
     */
    public const array KIT_CONTEXTS = ['Shared', 'Audit', 'Auth', 'Concerns'];

    /**
     * The kit's own value objects in the shared kernel (numbers.md), which no manifest lists.
     */
    public const array KIT_SHARED = ['Money', 'Percent'];

    /**
     * The kit's own exceptions in the shared kernel (exceptions.md), which no manifest lists.
     */
    public const array KIT_SHARED_EXCEPTIONS = ['DomainValueException', 'EntityNotFoundException', 'InvalidMoneyException', 'InvalidPercentException', 'RepositoryException'];

    private const string DOMAIN_VALUE_EXCEPTION = 'App\Domain\Shared\Exceptions\DomainValueException';

    private const string APPLICATION_EXCEPTION = 'App\Application\ApplicationException';

    /**
     * The types a value object's field may name that are no class.
     */
    public const array BUILTIN_TYPES = ['string', 'int', 'float', 'bool', 'array', 'iterable', 'object', 'mixed', 'null', 'true', 'false', 'callable', 'self', 'static'];

    /**
     * The kit trait a status uses to declare where each case may go next (states.md).
     */
    public const string TRANSITIONS_TRAIT = 'App\Domain\Shared\Concerns\HasTransitions';

    /**
     * The folders of an aggregate, or of the shared kernel, a field's type is looked up in.
     */
    private const array VOCABULARY_FOLDERS = ['Enums', 'ValueObjects', 'Entities', ''];

    /**
     * The kit's own HTTP resources: the audit log page (audit-log.md).
     */
    public const array KIT_RESOURCES = ['AuditEntry'];

    /**
     * The starter kit's own controllers, which act on the signed-in user's account.
     */
    public const string STARTER_CONTROLLERS = 'App\Http\Controllers\Settings';

    private const string USE_POLICY = 'Illuminate\Database\Eloquent\Attributes\UsePolicy';

    private const string AGGREGATE_ROOT = 'App\Domain\Shared\AggregateRoot';

    private const string DOMAIN_ENTITY = 'App\Domain\Shared\DomainEntity';

    private const string ID_GENERATOR = 'App\Domain\Shared\Ports\IdGenerator';

    /**
     * @var array<string, string>|null port → adapter class names, read once from the providers
     */
    private ?array $bindings = null;

    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * Every context the project declares under app/Domain or app/Application, the kit's own aside.
     * The shared kernel is one of them, because its manifest lists its enums and value objects.
     *
     * @return list<string>
     */
    public function contexts(): array
    {
        $contexts = array_unique([
            ...$this->directoriesIn('app/Domain'),
            ...$this->directoriesIn('app/Application'),
        ]);

        $contexts = array_values(array_diff($contexts, array_diff(self::KIT_CONTEXTS, [StructureFiles::SHARED])));
        sort($contexts);

        return $contexts;
    }

    /**
     * One context as its manifest holds it.
     *
     * The shared kernel holds the kit's base classes and ports, so only its enums and value objects
     * are read.
     *
     * @return array{context: string, aggregates: array<string, array{children: list<string>, repository: bool}>, services: array<string, array{shape: string, creates: string|null, repositories: list<string>, exception: bool}>, ports: array<string, array{layer: string, adapter: string|null}>, useCases: array<string, array{shape: string, returns: string, creates: bool, query: bool, repositories: list<string>}>, enums: array<string, array{aggregate: string|null, backing: string|null, cases: array<string, string|int|null>, transitions: array<string, list<string>>|null}>, valueObjects: array<string, array{aggregate: string|null, fields: array<string, string>}>, exceptions: array<string, array{kind: string, aggregate: string|null, useCase: string|null}>, entities: array<string, array{aggregate: string, behaviours: array<string, array{params: array<string, string>, throws: list<string>}>, assertions: array<string, array{params: array<string, string>, throws: list<string>}>}>}
     */
    public function read(string $context): array
    {
        $shared = $context === StructureFiles::SHARED;

        return [
            'context' => $context,
            'aggregates' => $shared ? [] : $this->aggregates($context),
            'services' => $shared ? [] : $this->services($context),
            'ports' => $shared ? [] : $this->ports($context),
            'useCases' => $shared ? [] : $this->useCases($context),
            'enums' => $this->enums($context),
            'valueObjects' => $this->valueObjects($context),
            'exceptions' => $this->exceptions($context),
            'entities' => $shared ? [] : $this->entities($context),
        ];
    }

    /**
     * The names a context declares twice in one section, each with the aggregates that hold it. A
     * manifest keys a section by name, so a second one would hide the first.
     *
     * @return array<string, array<string, list<string>>> section → name → aggregates
     */
    public function clashes(string $context): array
    {
        $clashes = [];

        foreach (['enums' => 'Enums', 'valueObjects' => 'ValueObjects'] as $section => $folder) {
            $holders = [];

            foreach ($this->vocabularyIn($context, $folder) as [$aggregate, $class]) {
                $holders[$this->basename($class)][] = $aggregate ?? $context;
            }

            foreach ($holders as $name => $aggregates) {
                if (count($aggregates) > 1) {
                    $clashes[$section][$name] = $aggregates;
                }
            }
        }

        $holders = [];

        foreach ($this->exceptionClassesIn($context) as $exception) {
            $holders[$exception['name']][] = $exception['aggregate'] ?? $exception['useCase'] ?? $context;
        }

        foreach ($holders as $name => $aggregates) {
            if (count($aggregates) > 1) {
                $clashes['exceptions'][$name] = $aggregates;
            }
        }

        return $clashes;
    }

    /**
     * The class a field's type names, written the way the manifest writes it (a name in the same
     * aggregate, `Shared/Name`, or `Context/Aggregate/Name`), or null when no such class is built.
     */
    public function vocabularyClass(string $type, string $context, ?string $aggregate): ?string
    {
        return $this->classNamed($type, $context, $aggregate, self::VOCABULARY_FOLDERS);
    }

    /**
     * The exception a method's `throws` names, written the way the manifest writes it, or null
     * when no such exception is built.
     */
    public function exceptionClass(string $type, string $context, ?string $aggregate): ?string
    {
        return $this->classNamed($type, $context, $aggregate, ['Exceptions', '']);
    }

    /**
     * @param  list<string>  $folders
     */
    private function classNamed(string $type, string $context, ?string $aggregate, array $folders): ?string
    {
        $segments = explode('/', $type);
        $name = (string) array_pop($segments);

        $namespace = match (count($segments)) {
            0 => $context === StructureFiles::SHARED ? 'App\\Domain\\Shared' : "App\\Domain\\{$context}\\{$aggregate}",
            1 => $segments[0] === StructureFiles::SHARED ? 'App\\Domain\\Shared' : null,
            2 => "App\\Domain\\{$segments[0]}\\{$segments[1]}",
            default => null,
        };

        foreach ($namespace === null ? [] : $folders as $folder) {
            $class = $namespace.($folder === '' ? '' : '\\'.$folder).'\\'.$name;

            if (class_exists($class) || interface_exists($class)) {
                return $class;
            }
        }

        return $segments === [] && (class_exists($name) || interface_exists($name)) ? $name : null;
    }

    /**
     * Every HTTP resource: a `{Model}Controller`, the `{Model}{Verb}Controller` actions and the
     * policy of a model, or a controller named after no model. The kit's and the starter kit's own
     * are left out.
     *
     * @return list<string>
     */
    public function resources(): array
    {
        $resources = [];

        foreach ($this->controllers() as $stem => $controller) {
            $resources[] = $this->actionOf($stem, $controller)['resource'] ?? $stem;
        }

        foreach ($this->models() as $model) {
            if ($this->policyOf($model) !== null) {
                $resources[] = $model;
            }
        }

        $resources = array_values(array_diff(array_unique($resources), self::KIT_RESOURCES));
        sort($resources);

        return $resources;
    }

    /**
     * One HTTP resource as its manifest holds it.
     *
     * @return array{resource: string, model: string|null, controller: array<string, list<string>>, actions: array<string, array{row: bool, bulk: bool, useCases: list<string>}>, policy: list<string>|null, pages: array<string, string>}
     */
    public function readResource(string $resource): array
    {
        $model = in_array($resource, $this->models(), true) ? $resource : null;
        $controllers = $this->controllers();
        $controller = [];
        $pages = [];

        $resourceController = $controllers[$resource] ?? null;

        if ($resourceController !== null && $this->actionOf($resource, $resourceController) === null) {
            foreach ($this->publicMethods($resourceController) as $method) {
                $controller[$method->getName()] = $this->useCasesOf($method);

                foreach ($this->rendersOf($method) as $page) {
                    $kind = $this->pageKind($page);

                    if ($kind !== null) {
                        $pages[$page] = $kind;
                    }
                }
            }
        }

        $actions = [];

        foreach ($controllers as $stem => $class) {
            $action = $this->actionOf($stem, $class);

            if ($action === null || $action['resource'] !== $resource) {
                continue;
            }

            $actions[$action['verb']] ??= ['row' => false, 'bulk' => false, 'useCases' => []];
            $actions[$action['verb']][$action['bulk'] ? 'bulk' : 'row'] = true;
            $actions[$action['verb']]['useCases'] = array_values(array_unique([
                ...$actions[$action['verb']]['useCases'],
                ...$this->useCasesOf(new ReflectionMethod($class, '__invoke')),
            ]));
            sort($actions[$action['verb']]['useCases']);
        }

        ksort($controller);
        ksort($actions);
        ksort($pages);

        return [
            'resource' => $resource,
            'model' => $model,
            'controller' => $controller,
            'actions' => $actions,
            'policy' => $model === null ? null : $this->policyOf($model),
            'pages' => $pages,
        ];
    }

    /**
     * The adapter a provider's `$bindings` binds a port to, as written there, or null.
     */
    public function boundAdapter(string $port): ?string
    {
        return $this->bindings()[$port] ?? null;
    }

    /**
     * The class a resource stands for: its controller, or its model when it has no controller.
     */
    public function resourceClass(string $resource): string
    {
        $controller = "App\\Http\\Controllers\\{$resource}Controller";

        return class_exists($controller) ? $controller : "App\\Models\\{$resource}";
    }

    /**
     * The class a manifest entry stands for, so a check can name the file a reader opens.
     */
    public function classOf(string $context, string $section, string $name): string
    {
        return match ($section) {
            'aggregates' => "App\\Domain\\{$context}\\{$name}\\{$name}Entity",
            'entities' => $this->entityClassOf($context, $name),
            'enums', 'valueObjects' => $this->vocabularyClassOf($context, $section === 'enums' ? 'Enums' : 'ValueObjects', $name),
            'exceptions' => $this->exceptionClassOf($context, $name),
            'services' => "App\\Domain\\{$context}\\Services\\{$name}\\{$name}Service",
            'ports' => $this->portClass($context, $name) ?? "App\\Domain\\{$context}\\Ports\\{$name}",
            default => $this->handlerClass($context, $name) ?? "App\\Application\\{$context}\\UseCases\\{$name}\\{$name}Handler",
        };
    }

    private function entityClassOf(string $context, string $name): string
    {
        foreach ($this->entityClasses($context) as $classes) {
            if (isset($classes[$name])) {
                return $classes[$name];
            }
        }

        return "App\\Domain\\{$context}\\{$name}\\{$name}Entity";
    }

    /**
     * @return array<string, array{children: list<string>, repository: bool}>
     */
    private function aggregates(string $context): array
    {
        $aggregates = [];

        foreach ($this->entityClasses($context) as $folder => $classes) {
            $children = array_keys(array_slice($classes, 1, preserve_keys: true));
            sort($children);

            $aggregates[$folder] = [
                'children' => array_map(strval(...), $children),
                'repository' => interface_exists("App\\Domain\\{$context}\\{$folder}\\{$folder}Repository"),
            ];
        }

        ksort($aggregates);

        return $aggregates;
    }

    /**
     * Each aggregate's entities, its root first and then its children, each by its name without
     * `Entity`.
     *
     * @return array<string, array<string, class-string>> aggregate → entity name → class
     */
    private function entityClasses(string $context): array
    {
        $entities = [];

        foreach ($this->directoriesIn("app/Domain/{$context}") as $folder) {
            $root = "App\\Domain\\{$context}\\{$folder}\\{$folder}Entity";

            if (! class_exists($root) || ! is_subclass_of($root, self::AGGREGATE_ROOT)) {
                continue;
            }

            $entities[$folder] = [$folder => $root];

            foreach ($this->classesIn("app/Domain/{$context}/{$folder}/Entities") as $child) {
                if (class_exists($child) && is_subclass_of($child, self::DOMAIN_ENTITY) && ! is_subclass_of($child, self::AGGREGATE_ROOT)) {
                    $entities[$folder][$this->stripSuffix($this->basename($child), 'Entity')] = $child;
                }
            }
        }

        return $entities;
    }

    /**
     * Each entity that declares a behaviour or an assertion, with the aggregate that holds it. An
     * entity that declares neither is left out, so a manifest lists only what it has to say.
     *
     * @return array<string, array{aggregate: string, behaviours: array<string, array{params: array<string, string>, throws: list<string>}>, assertions: array<string, array{params: array<string, string>, throws: list<string>}>}>
     */
    private function entities(string $context): array
    {
        $entities = [];

        foreach ($this->entityClasses($context) as $aggregate => $classes) {
            foreach ($classes as $name => $class) {
                $methods = $this->entityMethods($class, $context, $aggregate);

                if ($methods['behaviours'] !== [] || $methods['assertions'] !== []) {
                    $entities[$name] ??= ['aggregate' => $aggregate, ...$methods];
                }
            }
        }

        ksort($entities);

        return $entities;
    }

    /**
     * The public methods an entity declares itself, sorted into behaviours (they change it and
     * return void) and assertions (`assert*`), each with its parameters in order and the
     * exceptions it throws. Getters, static builders and what a base class or an interface
     * declares are left out.
     *
     * @param  class-string  $class
     * @return array{behaviours: array<string, array{params: array<string, string>, throws: list<string>}>, assertions: array<string, array{params: array<string, string>, throws: list<string>}>}
     */
    private function entityMethods(string $class, string $context, string $aggregate): array
    {
        $reflection = new ReflectionClass($class);
        $inherited = [];

        foreach ([$reflection->getParentClass() ?: null, ...$reflection->getInterfaces()] as $declarer) {
            foreach ($declarer?->getMethods() ?? [] as $method) {
                $inherited[] = $method->getName();
            }
        }

        $methods = ['behaviours' => [], 'assertions' => []];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || $method->isConstructor() || $method->getDeclaringClass()->getName() !== $class || in_array($name, $inherited, true)) {
                continue;
            }

            $assertion = self::isAssertion($name);
            $returns = $method->getReturnType();

            if (! $assertion && ! ($returns instanceof ReflectionNamedType && $returns->getName() === 'void')) {
                continue;
            }

            $params = [];

            foreach ($method->getParameters() as $parameter) {
                $params[$parameter->getName()] = ($parameter->isVariadic() ? '...' : '').$this->fieldType($parameter->getType(), $context, $aggregate);
            }

            $methods[$assertion ? 'assertions' : 'behaviours'][$name] = [
                'params' => $params,
                'throws' => $this->throwsOf($reflection, $name, $context, $aggregate),
            ];
        }

        ksort($methods['behaviours']);
        ksort($methods['assertions']);

        return $methods;
    }

    /**
     * Whether a method of an entity is an assertion: `assert` followed by what it asserts.
     */
    public static function isAssertion(string $method): bool
    {
        return preg_match('/^assert[A-Z0-9_]/', $method) === 1;
    }

    /**
     * The exceptions a method throws, read from its body: every `throw X::…()` and `throw new X`,
     * and those of every method of the same class it calls through `$this->`, `self::` or
     * `static::`, followed as deep as they go. A rethrow (`throw $e`) names nothing new.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, true>  $visited
     * @return list<string>
     */
    private function throwsOf(ReflectionClass $class, string $method, string $context, string $aggregate, array &$visited = []): array
    {
        $visited[$method] = true;
        $reflection = $class->getMethod($method);
        $file = (string) $reflection->getFileName();
        $lines = array_slice(explode("\n", StructureFiles::text($file)), (int) $reflection->getStartLine() - 1, (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1);
        $tokens = array_values(array_filter(
            token_get_all("<?php\n".implode("\n", $lines)),
            fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true),
        ));
        $throws = [];

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;

            if (is_array($token) && $token[0] === T_THROW) {
                $name = is_array($next) && $next[0] === T_NEW ? ($tokens[$index + 2] ?? null) : $next;

                if (is_array($name) && in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ! in_array(strtolower($name[1]), ['self', 'static', 'parent'], true)) {
                    $throws[] = $this->typeLabel($this->resolveName($name[1], $file, $class->getNamespaceName()), $context, $aggregate);
                }

                continue;
            }

            $callee = match (true) {
                is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$this' && is_array($next) && in_array($next[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) => $tokens[$index + 2] ?? null,
                is_array($token) && (($token[0] === T_STRING && strtolower($token[1]) === 'self') || $token[0] === T_STATIC) && is_array($next) && $next[0] === T_DOUBLE_COLON => $tokens[$index + 2] ?? null,
                default => null,
            };

            if (! is_array($callee) || $callee[0] !== T_STRING || ($tokens[$index + 3] ?? null) !== '(' || isset($visited[$callee[1]])) {
                continue;
            }

            if ($class->hasMethod($callee[1]) && $class->getMethod($callee[1])->getDeclaringClass()->getName() === $class->getName()) {
                $throws = [...$throws, ...$this->throwsOf($class, $callee[1], $context, $aggregate, $visited)];
            }
        }

        $throws = array_values(array_unique($throws));
        sort($throws);

        return $throws;
    }

    /**
     * A class name as a file spells it, made full through the file's `use` lines and namespace.
     */
    private function resolveName(string $name, string $file, string $namespace): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $imports = [];
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', StructureFiles::text($file), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $imports[$match[2] ?? $this->basename($match[1])] = ltrim($match[1], '\\');
        }

        $first = explode('\\', $name, 2);

        if (isset($imports[$first[0]])) {
            return $imports[$first[0]].(isset($first[1]) ? '\\'.$first[1] : '');
        }

        return ($namespace === '' ? '' : $namespace.'\\').$name;
    }

    /**
     * Each enum with its cases in order and, for a status, the cases each one may become.
     *
     * @return array<string, array{aggregate: string|null, backing: string|null, cases: array<string, string|int|null>, transitions: array<string, list<string>>|null}>
     */
    private function enums(string $context): array
    {
        $enums = [];

        foreach ($this->vocabularyIn($context, 'Enums') as [$aggregate, $class]) {
            if (! enum_exists($class)) {
                continue;
            }

            $enum = new ReflectionEnum($class);
            $cases = [];

            foreach ($enum->getCases() as $case) {
                $cases[$case->getName()] = $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null;
            }

            $enums[$this->basename($class)] ??= [
                'aggregate' => $aggregate,
                'backing' => $enum->getBackingType()?->getName(),
                'cases' => $cases,
                'transitions' => $this->transitions($enum, $cases),
            ];
        }

        ksort($enums);

        return $enums;
    }

    /**
     * Where each case of a status may go next, read from its `transitions()`, or null for an enum
     * that does not use the kit's trait.
     *
     * @param  ReflectionEnum<UnitEnum>  $enum
     * @param  array<string, string|int|null>  $cases
     * @return array<string, list<string>>|null
     */
    private function transitions(ReflectionEnum $enum, array $cases): ?array
    {
        if (! in_array(self::TRANSITIONS_TRAIT, $enum->getTraitNames(), true) || ! $enum->hasMethod('transitions')) {
            return null;
        }

        $method = $enum->getMethod('transitions');
        $transitions = [];

        foreach ($enum->getCases() as $case) {
            $next = $method->invoke($case->getValue());
            $transitions[$case->getName()] = is_array($next)
                ? array_values(array_map(fn (mixed $target): string => $target instanceof UnitEnum ? $target->name : (string) json_encode($target), $next))
                : [];
        }

        return StructureFiles::orderedTransitions($transitions, $cases);
    }

    /**
     * Each concrete class in a ValueObjects folder, with its constructor's parameters in order.
     *
     * @return array<string, array{aggregate: string|null, fields: array<string, string>}>
     */
    private function valueObjects(string $context): array
    {
        $valueObjects = [];

        foreach ($this->vocabularyIn($context, 'ValueObjects') as [$aggregate, $class]) {
            if (! class_exists($class) || enum_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $fields = [];

            foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $fields[$parameter->getName()] = $this->fieldType($parameter->getType(), $context, $aggregate);
            }

            $valueObjects[$this->basename($class)] ??= ['aggregate' => $aggregate, 'fields' => $fields];
        }

        ksort($valueObjects);

        return $valueObjects;
    }

    /**
     * The classes directly in a folder of each aggregate, or of the shared kernel's root, with the
     * aggregate that holds them (null in the shared kernel). The kit's own are left out.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private function vocabularyIn(string $context, string $folder): array
    {
        if ($context === StructureFiles::SHARED) {
            return array_values(array_map(
                fn (string $class): array => [null, $class],
                array_filter(
                    $this->classesIn("app/Domain/{$context}/{$folder}", recursive: false),
                    fn (string $class): bool => ! in_array($this->basename($class), self::KIT_SHARED, true),
                ),
            ));
        }

        $classes = [];

        foreach ($this->directoriesIn("app/Domain/{$context}") as $aggregate) {
            foreach ($this->classesIn("app/Domain/{$context}/{$aggregate}/{$folder}", recursive: false) as $class) {
                $classes[] = [$aggregate, $class];
            }
        }

        return $classes;
    }

    /**
     * Each exception a context designs, by kind (exceptions.md): an aggregate's refusal, an invalid
     * value of an aggregate or of the shared kernel, and a use case's refusal in the application.
     * What the kit or another generator owns is left out: the context's base, a repository's not
     * found and failure, a domain service's own exception (its service says it has one) and the
     * kit's exceptions in the shared kernel.
     *
     * @return array<string, array{kind: string, aggregate: string|null, useCase: string|null}>
     */
    private function exceptions(string $context): array
    {
        $exceptions = [];

        foreach ($this->exceptionClassesIn($context) as $exception) {
            $exceptions[$exception['name']] ??= ['kind' => $exception['kind'], 'aggregate' => $exception['aggregate'], 'useCase' => $exception['useCase']];
        }

        ksort($exceptions);

        return $exceptions;
    }

    /**
     * @return list<array{name: string, class: string, kind: string, aggregate: string|null, useCase: string|null}>
     */
    private function exceptionClassesIn(string $context): array
    {
        $found = [];

        if ($context === StructureFiles::SHARED) {
            foreach ($this->classesIn('app/Domain/Shared/Exceptions', recursive: false) as $class) {
                $name = $this->basename($class);

                if (! in_array($name, self::KIT_SHARED_EXCEPTIONS, true) && class_exists($class) && is_subclass_of($class, self::DOMAIN_VALUE_EXCEPTION)) {
                    $found[] = ['name' => $name, 'class' => $class, 'kind' => 'value', 'aggregate' => null, 'useCase' => null];
                }
            }

            return $found;
        }

        $refusalBase = "App\\Domain\\{$context}\\Exceptions\\{$context}DomainException";

        foreach ($this->vocabularyIn($context, 'Exceptions') as [$aggregate, $class]) {
            if (! class_exists($class)) {
                continue;
            }

            $kind = match (true) {
                is_subclass_of($class, $refusalBase) => 'refusal',
                is_subclass_of($class, self::DOMAIN_VALUE_EXCEPTION) => 'value',
                default => null,
            };

            if ($kind !== null) {
                $found[] = ['name' => $this->basename($class), 'class' => $class, 'kind' => $kind, 'aggregate' => $aggregate, 'useCase' => null];
            }
        }

        foreach ($this->classesIn("app/Application/{$context}") as $class) {
            if (! str_ends_with($class, 'Exception') || ! class_exists($class) || ! is_subclass_of($class, self::APPLICATION_EXCEPTION)) {
                continue;
            }

            $namespace = substr($class, 0, (int) strrpos($class, '\\'));
            $useCase = preg_match('/^App\\\\Application\\\\\w+\\\\UseCases\\\\(\w+)$/', $namespace, $match) === 1 ? $match[1] : null;

            $found[] = ['name' => $this->basename($class), 'class' => $class, 'kind' => 'application', 'aggregate' => null, 'useCase' => $useCase];
        }

        return $found;
    }

    private function exceptionClassOf(string $context, string $name): string
    {
        foreach ($this->exceptionClassesIn($context) as $exception) {
            if ($exception['name'] === $name) {
                return $exception['class'];
            }
        }

        return $context === StructureFiles::SHARED ? "App\\Domain\\Shared\\Exceptions\\{$name}" : "App\\Domain\\{$context}\\Exceptions\\{$name}";
    }

    private function vocabularyClassOf(string $context, string $folder, string $name): string
    {
        foreach ($this->vocabularyIn($context, $folder) as [, $class]) {
            if ($this->basename($class) === $name) {
                return $class;
            }
        }

        return "App\\Domain\\{$context}\\{$folder}\\{$name}";
    }

    /**
     * A field's type as the manifest writes it: a builtin as PHP spells it, a class of the same
     * aggregate by its name, one of the shared kernel as `Shared/Name`, one of another aggregate as
     * `Context/Aggregate/Name`, and anything else by its full name.
     */
    private function fieldType(?ReflectionType $type, string $context, ?string $aggregate): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $this->typeLabel($type->getName(), $context, $aggregate);

            return $type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true) ? '?'.$name : $name;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return implode($type instanceof ReflectionUnionType ? '|' : '&', array_map(
                fn (ReflectionType $part): string => $this->fieldType($part, $context, $aggregate),
                $type->getTypes(),
            ));
        }

        return 'mixed';
    }

    private function typeLabel(string $class, string $context, ?string $aggregate): string
    {
        if (preg_match('/^App\\\\Domain\\\\Shared\\\\(?:\w+\\\\)*(\w+)$/', $class, $match) === 1) {
            return $context === StructureFiles::SHARED ? $match[1] : StructureFiles::SHARED.'/'.$match[1];
        }

        if (preg_match('/^App\\\\Domain\\\\(\w+)\\\\(\w+)\\\\(?:\w+\\\\)*(\w+)$/', $class, $match) === 1) {
            return $match[1] === $context && $match[2] === $aggregate ? $match[3] : "{$match[1]}/{$match[2]}/{$match[3]}";
        }

        return $class;
    }

    /**
     * @return array<string, array{shape: string, creates: string|null, repositories: list<string>, exception: bool}>
     */
    private function services(string $context): array
    {
        $services = [];

        foreach ($this->directoriesIn("app/Domain/{$context}/Services") as $name) {
            $class = "App\\Domain\\{$context}\\Services\\{$name}\\{$name}Service";

            if (! class_exists($class) || ! method_exists($class, 'handle')) {
                continue;
            }

            $handle = new ReflectionMethod($class, 'handle');
            $shape = $this->serviceShape($handle);

            $services[$name] = [
                'shape' => $shape,
                'creates' => $shape === 'creates' ? $this->aggregateOf($this->typeNames($handle->getReturnType())[0], $context) : null,
                'repositories' => $this->repositoriesOf($class, $context),
                'exception' => class_exists("App\\Domain\\{$context}\\Services\\{$name}\\{$name}Exception"),
            ];
        }

        ksort($services);

        return $services;
    }

    /**
     * `creates` builds a root from an id and a Data, `data` returns a Result, anything else is
     * plain. LayersTest's `service-shape` check already refuses a fourth shape.
     */
    private function serviceShape(ReflectionMethod $handle): string
    {
        $returns = $this->typeNames($handle->getReturnType());

        if (array_filter($returns, fn (string $type): bool => str_ends_with($type, 'Result')) !== []) {
            return 'data';
        }

        $parameters = $handle->getParameters();
        $takesData = array_filter($parameters, fn ($parameter): bool => array_filter(
            $this->typeNames($parameter->getType()),
            fn (string $type): bool => str_ends_with($type, 'Data'),
        ) !== []) !== [];

        return $takesData && count($returns) === 1 && is_subclass_of($returns[0], self::AGGREGATE_ROOT) ? 'creates' : 'plain';
    }

    /**
     * The aggregate a root entity class belongs to, as `Aggregate` inside the context and
     * `Context/Aggregate` outside it.
     */
    private function aggregateOf(string $root, string $context): string
    {
        if (preg_match('/^App\\\\Domain\\\\(\w+)\\\\(\w+)\\\\\w+Entity$/', $root, $match) !== 1) {
            return $this->stripSuffix($this->basename($root), 'Entity');
        }

        return $match[1] === $context ? $match[2] : $match[1].'/'.$match[2];
    }

    /**
     * @return array<string, array{layer: string, adapter: string|null}>
     */
    private function ports(string $context): array
    {
        $ports = [];

        foreach ($this->classesIn("app/Domain/{$context}/Ports") as $class) {
            if (interface_exists($class)) {
                $ports[$this->basename($class)] = ['layer' => 'domain', 'adapter' => $this->adapterOf($class)];
            }
        }

        foreach ($this->classesIn("app/Application/{$context}", recursive: false) as $class) {
            if (interface_exists($class)) {
                $ports[$this->basename($class)] = ['layer' => 'application', 'adapter' => $this->adapterOf($class)];
            }
        }

        ksort($ports);

        return $ports;
    }

    /**
     * @return array<string, array{shape: string, returns: string, creates: bool, query: bool, repositories: list<string>}>
     */
    private function useCases(string $context): array
    {
        $useCases = [];

        foreach ($this->classesIn("app/Application/{$context}/UseCases") as $class) {
            if (! str_ends_with($class, 'Handler') || ! class_exists($class) || ! method_exists($class, '__invoke')) {
                continue;
            }

            $name = $this->stripSuffix($this->basename($class), 'Handler');
            $invoke = new ReflectionMethod($class, '__invoke');
            $returns = $this->typeNames($invoke->getReturnType());
            $takesCommand = array_filter($invoke->getParameters(), fn ($parameter): bool => array_filter(
                $this->typeNames($parameter->getType()),
                fn (string $type): bool => str_ends_with($type, 'Command'),
            ) !== []) !== [];
            $returnsResult = array_filter($returns, fn (string $type): bool => str_ends_with($type, 'Result')) !== [];
            $namespace = substr($class, 0, (int) strrpos($class, '\\'));

            $useCases[$name] = [
                'shape' => match (true) {
                    $takesCommand && $returnsResult => 'command-result',
                    $takesCommand => 'command',
                    default => 'plain',
                },
                'returns' => $returnsResult ? 'result' : implode('|', array_map($this->basename(...), $returns)),
                'creates' => in_array(self::ID_GENERATOR, $this->constructorTypes($class), true),
                'query' => interface_exists("{$namespace}\\{$name}Query"),
                'repositories' => $this->repositoriesOf($class, $context),
            ];
        }

        ksort($useCases);

        return $useCases;
    }

    /**
     * The repositories a class injects, as `Aggregate` inside its own context and `Context/Aggregate`
     * outside it.
     *
     * @return list<string>
     */
    private function repositoriesOf(string $class, string $context): array
    {
        $repositories = [];

        foreach ($this->constructorTypes($class) as $type) {
            if (preg_match('/^App\\\\Domain\\\\(\w+)\\\\(\w+)\\\\\2Repository$/', $type, $match) !== 1 || ! interface_exists($type)) {
                continue;
            }

            $repositories[] = $match[1] === $context ? $match[2] : $match[1].'/'.$match[2];
        }

        sort($repositories);

        return array_values(array_unique($repositories));
    }

    /**
     * @return list<string>
     */
    private function constructorTypes(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return [];
        }

        return array_merge([], ...array_map(
            fn ($parameter): array => $this->typeNames($parameter->getType()),
            $constructor->getParameters(),
        ));
    }

    /**
     * The adapter a provider's `$bindings` binds a port to, as a path under App (`Infra/Media/X`),
     * or null when no provider binds it. Read from the property's default, so no provider runs.
     */
    private function adapterOf(string $port): ?string
    {
        $adapter = $this->bindings()[$port] ?? null;

        return $adapter === null ? null : str_replace('\\', '/', substr($adapter, strlen('App\\')));
    }

    /**
     * Each provider's `$bindings` as written: port names to adapter names, neither loaded.
     *
     * @return array<string, string>
     */
    private function bindings(): array
    {
        if ($this->bindings !== null) {
            return $this->bindings;
        }

        $this->bindings = [];

        foreach ([...$this->classesIn('app/Providers'), ...$this->classesIn('app/Infra')] as $class) {
            if (! str_ends_with($class, 'ServiceProvider') || ! class_exists($class)) {
                continue;
            }

            $bindings = (new ReflectionClass($class))->getDefaultProperties()['bindings'] ?? [];

            if (is_array($bindings)) {
                foreach ($bindings as $abstract => $concrete) {
                    if (is_string($abstract) && is_string($concrete)) {
                        $this->bindings[$abstract] = $concrete;
                    }
                }
            }
        }

        return $this->bindings;
    }

    private function portClass(string $context, string $name): ?string
    {
        foreach (["App\\Domain\\{$context}\\Ports\\{$name}", "App\\Application\\{$context}\\{$name}"] as $class) {
            if (interface_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    private function handlerClass(string $context, string $name): ?string
    {
        foreach (["App\\Application\\{$context}\\UseCases\\{$name}\\{$name}Handler", "App\\Application\\{$context}\\UseCases\\{$name}Handler"] as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function typeNames(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            return array_merge([], ...array_map($this->typeNames(...), $type->getTypes()));
        }

        return [];
    }

    /**
     * The classes the PHP files under a folder declare, from each file's namespace and name.
     *
     * @return list<string>
     */
    private function classesIn(string $relative, bool $recursive = true): array
    {
        $directory = $this->root.'/'.$relative;

        if (! is_dir($directory)) {
            return [];
        }

        $files = $recursive ? StructureSwapper::phpFilesUnder($directory) : (glob($directory.'/*.php') ?: []);
        $classes = [];

        foreach ($files as $file) {
            if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', StructureFiles::text($file), $match) === 1) {
                $classes[] = $match[1].'\\'.basename($file, '.php');
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function directoriesIn(string $relative): array
    {
        $directories = glob($this->root.'/'.$relative.'/*', GLOB_ONLYDIR) ?: [];

        return array_map(basename(...), $directories);
    }

    private function basename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function stripSuffix(string $name, string $suffix): string
    {
        return str_ends_with($name, $suffix) ? substr($name, 0, -strlen($suffix)) : $name;
    }

    /**
     * The project's controllers by stem (`CompanyUser` for `CompanyUserController`), the starter
     * kit's and the base class aside.
     *
     * @return array<string, class-string>
     */
    private function controllers(): array
    {
        $controllers = [];

        foreach ($this->classesIn('app/Http/Controllers', recursive: false) as $class) {
            if (! str_ends_with($class, 'Controller') || str_starts_with($class, self::STARTER_CONTROLLERS.'\\') || ! class_exists($class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $controllers[$this->stripSuffix($this->basename($class), 'Controller')] = $class;
        }

        ksort($controllers);

        return $controllers;
    }

    /**
     * The models under app/Models, by class name.
     *
     * @return list<string>
     */
    private function models(): array
    {
        return array_values(array_filter(
            array_map($this->basename(...), $this->classesIn('app/Models', recursive: false)),
            fn (string $model): bool => class_exists("App\\Models\\{$model}"),
        ));
    }

    /**
     * The model, verb and kind of an action controller (actions.md): an invokable controller whose
     * stem starts with a model's name, the longest one that fits. Null for any other controller.
     *
     * @param  class-string  $class
     * @return array{resource: string, verb: string, bulk: bool}|null
     */
    private function actionOf(string $stem, string $class): ?array
    {
        if (! method_exists($class, '__invoke')) {
            return null;
        }

        $models = array_filter($this->models(), fn (string $model): bool => $stem !== $model && str_starts_with($stem, $model));

        if ($models === []) {
            return null;
        }

        usort($models, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $model = $models[0];
        $verb = substr($stem, strlen($model));
        $bulk = str_starts_with($verb, 'Bulk') && strlen($verb) > strlen('Bulk');

        return ['resource' => $model, 'verb' => $bulk ? substr($verb, strlen('Bulk')) : $verb, 'bulk' => $bulk];
    }

    /**
     * The abilities of the policy a model names through #[UsePolicy], `before()` aside, or null.
     *
     * @return list<string>|null
     */
    private function policyOf(string $model): ?array
    {
        $class = "App\\Models\\{$model}";

        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(self::USE_POLICY);
        $policy = $attributes === [] ? null : ($attributes[0]->getArguments()[0] ?? null);

        if (! is_string($policy) || ! class_exists($policy)) {
            return null;
        }

        $abilities = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $this->publicMethods($policy),
                fn (ReflectionMethod $method): bool => $method->getName() !== 'before',
            ),
        );
        sort($abilities);

        return $abilities;
    }

    /**
     * The public instance methods a class declares itself, magic ones aside but `__invoke`.
     *
     * @param  class-string  $class
     * @return list<ReflectionMethod>
     */
    private function publicMethods(string $class): array
    {
        return array_values(array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class
                && ! $method->isStatic()
                && ($method->getName() === '__invoke' || ! str_starts_with($method->getName(), '__')),
        ));
    }

    /**
     * The use cases a controller method injects, as `Context/UseCase`.
     *
     * @return list<string>
     */
    private function useCasesOf(ReflectionMethod $method): array
    {
        $useCases = [];

        foreach ($method->getParameters() as $parameter) {
            foreach ($this->typeNames($parameter->getType()) as $type) {
                if (preg_match('/^App\\\\Application\\\\(\w+)\\\\UseCases\\\\(?:\w+\\\\)?(\w+)Handler$/', $type, $match) === 1) {
                    $useCases[] = $match[1].'/'.$match[2];
                }
            }
        }

        sort($useCases);

        return array_values(array_unique($useCases));
    }

    /**
     * The pages a controller method renders by a literal name.
     *
     * @return list<string>
     */
    private function rendersOf(ReflectionMethod $method): array
    {
        $lines = file((string) $method->getFileName()) ?: [];
        $code = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));

        preg_match_all('/Inertia::render\(\s*[\'"]([^\'"]+)[\'"]/', $code, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * What a page is, read from its file the way list-pages.md and form-pages.md tell them apart,
     * or null when the page has no file.
     */
    private function pageKind(string $page): ?string
    {
        $path = $this->root.'/resources/js/pages/'.$page.'.tsx';

        if (! is_file($path)) {
            return null;
        }

        $code = StructureFiles::text($path);

        return match (true) {
            preg_match('/\buseDataTable\s*(<[^()]*>)?\s*\(/', $code) === 1 => 'table',
            preg_match('/<InfiniteScroll\b/', $code) === 1 => 'grid',
            preg_match('#from\s+[\'"]@/components/[^\'"]+/form[\'"]#', $code) === 1 => 'form',
            default => 'page',
        };
    }
}
