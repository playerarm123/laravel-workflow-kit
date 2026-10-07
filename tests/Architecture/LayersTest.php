<?php

use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\DomainEntity;

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of layers.md — change the two together.
 *
 * Contexts are discovered from the folders under app/Domain, never listed by hand, so a
 * new context is policed the moment it exists. Entry points are the layers outside code
 * comes in through; they reach the domain only for its vocabulary (enums, value
 * objects, exceptions) and do everything else through a use-case handler.
 *
 * @return array{
 *     domain_path: string,
 *     shared_context: string,
 *     base_classes: array<class-string, string>,
 *     domain_forbidden: list<string>,
 *     application_forbidden: list<string>,
 *     application_illuminate: list<string>,
 *     entry_points: array<string, string>,
 *     entry_forbidden: list<string>,
 *     models_forbidden: list<string>,
 *     policies_forbidden: list<string>,
 *     infra_forbidden: list<string>,
 *     service_method: string,
 * }
 */
function layersSpec(): array
{
    return [
        'domain_path' => 'app/Domain',
        'shared_context' => 'Shared',
        'base_classes' => [
            'App\Domain\Shared\DomainEntity' => 'app/Domain/Shared/DomainEntity.php',
            'App\Domain\Shared\AggregateRoot' => 'app/Domain/Shared/AggregateRoot.php',
            'App\Domain\Shared\DomainException' => 'app/Domain/Shared/DomainException.php',
        ],
        'domain_forbidden' => [
            'Illuminate', 'App\Application', 'App\Infra', 'App\Http', 'App\Models', 'App\Policies',
            'App\Console', 'App\Providers', 'App\Jobs', 'App\Listeners',
        ],
        'application_forbidden' => [
            'App\Infra', 'App\Http', 'App\Models', 'App\Policies', 'App\Console', 'App\Providers',
            'App\Jobs', 'App\Listeners',
        ],
        'application_illuminate' => [
            'Illuminate\Support\Facades\DB',
            'Illuminate\Support\Str',
            'Illuminate\Pagination',
            'Illuminate\Contracts\Pagination',
            'Illuminate\Support\Collection',
            'Illuminate\Support\LazyCollection',
            'Illuminate\Contracts\Support\Arrayable',
            'Illuminate\Http\UploadedFile',
            'Illuminate\Contracts\Auth',
        ],
        'entry_points' => [
            'App\Http' => 'app/Http',
            'App\Console' => 'app/Console',
            'App\Jobs' => 'app/Jobs',
            'App\Listeners' => 'app/Listeners',
        ],
        'entry_forbidden' => ['App\Infra'],
        'models_forbidden' => ['App\Application', 'App\Infra', 'App\Http', 'App\Console', 'App\Jobs', 'App\Listeners'],
        'policies_forbidden' => ['App\Application', 'App\Infra', 'App\Http', 'App\Console', 'App\Jobs', 'App\Listeners'],
        'infra_forbidden' => ['App\Http', 'App\Console', 'App\Jobs', 'App\Listeners'],
        'service_method' => 'handle',
    ];
}

/**
 * @return list<string> context folder names under app/Domain, the shared kernel included
 */
function layersContexts(): array
{
    $contexts = array_map('basename', ruleGlob(ruleProjectPath(layersSpec()['domain_path']).'/*', GLOB_ONLYDIR));
    sort($contexts);

    return $contexts;
}

/**
 * Is this domain class part of the vocabulary an outer layer may speak?
 *
 * @param  list<string>  $kinds  any of Enums, ValueObjects, Exceptions
 */
function layersIsDomainVocabulary(string $name, array $kinds): bool
{
    foreach ($kinds as $kind) {
        if (str_contains($name, '\\'.$kind.'\\')) {
            return true;
        }
    }

    return in_array('Exceptions', $kinds, true) && str_ends_with($name, 'Exception');
}

/**
 * One violation per forbidden dependency of every PHP file under a folder.
 *
 * @param  callable(string $dependency, string $file): ?string  $judge  a reason when the dependency is not allowed
 * @return list<array{subject: string, message: string}>
 */
function layersJudge(string $directory, callable $judge): array
{
    $violations = [];

    foreach (ruleSourceFiles($directory, ['php']) as $file) {
        foreach (ruleDependenciesOf($file) as $dependency) {
            $reason = $judge($dependency, $file);

            if ($reason !== null) {
                $violations[] = ['subject' => $file, 'message' => sprintf('uses %s (%s)', $dependency, $reason)];
            }
        }
    }

    return $violations;
}

/**
 * @param  list<string>  $namespaces
 */
function layersFirstMatch(string $dependency, array $namespaces): ?string
{
    foreach ($namespaces as $namespace) {
        if (ruleIsIn($dependency, $namespace)) {
            return $namespace;
        }
    }

    return null;
}

/**
 * Every class or interface declared under app/Domain, loaded through the autoloader.
 *
 * @return list<class-string>
 */
function layersDomainClasses(): array
{
    $classes = [];

    foreach (ruleSourceFiles(layersSpec()['domain_path'], ['php']) as $file) {
        $name = ruleClassOf($file);

        if (class_exists($name) || interface_exists($name)) {
            $classes[] = $name;
        }
    }

    return $classes;
}

/**
 * @return list<string>
 */
function layersNamedTypes(?ReflectionType $type): array
{
    if ($type instanceof ReflectionNamedType) {
        return $type->isBuiltin() ? [] : [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        return array_merge([], ...array_map(layersNamedTypes(...), $type->getTypes()));
    }

    return [];
}

/**
 * Why a service's handle() takes none of the three shapes in layers.md, or null when it takes one.
 *
 * Creates is `(string $id, {X}Data $data): {Root}Entity`, Data in / Result out is
 * `({X}Data $data): {Name}Result`, and Plain names no `*Data` and no `*Result` at all.
 */
function layersServiceShapeViolation(ReflectionMethod $handle, string $folder, string $name): ?string
{
    $parameters = $handle->getParameters();
    $data = array_values(array_filter($parameters, fn (ReflectionParameter $parameter) => array_filter(
        layersNamedTypes($parameter->getType()),
        fn (string $type) => str_ends_with($type, 'Data'),
    ) !== []));
    $results = array_values(array_filter(layersNamedTypes($handle->getReturnType()), fn (string $type) => str_ends_with($type, 'Result')));

    foreach ([...array_merge([], ...array_map(fn (ReflectionParameter $parameter) => layersNamedTypes($parameter->getType()), $data)), ...$results] as $type) {
        if (! ruleIsIn($type, $folder)) {
            return sprintf('%s lives outside the service — move it to %s', $type, $folder);
        }
    }

    if ($results !== []) {
        if ($results !== [$folder.'\\'.$name.'Result']) {
            return sprintf('a Result is named %sResult', $name);
        }

        return count($parameters) === 1 && $data !== []
            ? null
            : sprintf('a Result comes back only from handle(%sData $data)', $name);
    }

    if ($data === []) {
        return null;
    }

    $first = $parameters[0]->getType();
    $return = $handle->getReturnType();

    $creates = count($parameters) === 2
        && $data === [$parameters[1]]
        && $first instanceof ReflectionNamedType && $first->getName() === 'string' && ! $first->allowsNull()
        && $return instanceof ReflectionNamedType && ! $return->allowsNull() && is_subclass_of($return->getName(), AggregateRoot::class);

    return $creates
        ? null
        : sprintf('a Data goes into handle(string $id, XData $data): RootEntity or handle(XData $data): %sResult', $name);
}

describe('layers', function () {
    it('ships the domain base classes at their fixed home', function () {
        $violations = [];

        foreach (layersSpec()['base_classes'] as $class => $path) {
            if (! is_file(ruleProjectPath($path))) {
                $violations[] = ['subject' => $class, 'message' => sprintf('is missing — copy it to %s', $path)];
            }
        }

        expect(ruleUnexcused('layers', 'base-classes', $violations))->toBe([]);
    });

    it('keeps the domain free of the framework and of every outer layer', function () {
        $violations = layersJudge(layersSpec()['domain_path'], function (string $dependency): ?string {
            $namespace = layersFirstMatch($dependency, layersSpec()['domain_forbidden']);

            return $namespace === null ? null : 'the domain depends on nothing outside app/Domain';
        });

        expect(ruleUnexcused('layers', 'domain-framework', $violations))->toBe([]);
    });

    it('keeps every context out of the others, and the shared kernel out of all of them', function () {
        $shared = layersSpec()['shared_context'];
        $violations = [];

        foreach (layersContexts() as $context) {
            $own = 'App\\Domain\\'.$context;

            $violations = [...$violations, ...layersJudge(layersSpec()['domain_path'].'/'.$context, function (string $dependency) use ($context, $own, $shared): ?string {
                if (! ruleIsIn($dependency, 'App\\Domain') || ruleIsIn($dependency, $own)) {
                    return null;
                }

                if ($context === $shared) {
                    return 'the shared kernel depends on no context';
                }

                return ruleIsIn($dependency, 'App\\Domain\\'.$shared)
                    ? null
                    : sprintf('%s may reach only itself and %s; cross contexts in a use-case handler', $context, $shared);
            })];
        }

        expect(ruleUnexcused('layers', 'domain-context', $violations))->toBe([]);
    });

    it('lets application code use only the domain and the listed framework pieces', function () {
        $violations = layersJudge('app/Application', function (string $dependency): ?string {
            if (layersFirstMatch($dependency, layersSpec()['application_forbidden']) !== null) {
                return 'application code never reaches infrastructure, HTTP, Eloquent models or policies';
            }

            if (ruleIsIn($dependency, 'Illuminate') && layersFirstMatch($dependency, layersSpec()['application_illuminate']) === null) {
                return 'not on the framework allowlist for application code';
            }

            return null;
        });

        expect(ruleUnexcused('layers', 'application', $violations))->toBe([]);
    });

    it('lets entry points reach the domain only for its enums, value objects and exceptions', function () {
        $violations = [];

        foreach (layersSpec()['entry_points'] as $directory) {
            $violations = [...$violations, ...layersJudge($directory, function (string $dependency): ?string {
                if (layersFirstMatch($dependency, layersSpec()['entry_forbidden']) !== null) {
                    return 'entry points never reach infrastructure; bind an application interface instead';
                }

                if (ruleIsIn($dependency, 'App\\Domain') && ! layersIsDomainVocabulary($dependency, ['Enums', 'ValueObjects', 'Exceptions'])) {
                    return 'go through a use-case handler for anything but enums, value objects and exceptions';
                }

                return null;
            })];
        }

        expect(ruleUnexcused('layers', 'entry-points', $violations))->toBe([]);
    });

    it('keeps Eloquent models to persistence and domain enums or value objects', function () {
        $violations = layersJudge('app/Models', function (string $dependency): ?string {
            if (layersFirstMatch($dependency, layersSpec()['models_forbidden']) !== null) {
                return 'models know only the domain vocabulary and their policies';
            }

            return ruleIsIn($dependency, 'App\\Domain') && ! layersIsDomainVocabulary($dependency, ['Enums', 'ValueObjects'])
                ? 'models may use only domain enums and value objects, for casts'
                : null;
        });

        expect(ruleUnexcused('layers', 'models', $violations))->toBe([]);
    });

    it('keeps policies to models and domain enums', function () {
        $violations = layersJudge('app/Policies', function (string $dependency): ?string {
            if (layersFirstMatch($dependency, layersSpec()['policies_forbidden']) !== null) {
                return 'a policy decides from the model and the user alone';
            }

            return ruleIsIn($dependency, 'App\\Domain') && ! layersIsDomainVocabulary($dependency, ['Enums'])
                ? 'policies may use only domain enums'
                : null;
        });

        expect(ruleUnexcused('layers', 'policies', $violations))->toBe([]);
    });

    it('keeps infrastructure from calling back into the entry points', function () {
        $violations = layersJudge('app/Infra', fn (string $dependency): ?string => layersFirstMatch($dependency, layersSpec()['infra_forbidden']) === null
            ? null
            : 'infrastructure implements ports; it is never the caller of an entry point');

        expect(ruleUnexcused('layers', 'infra', $violations))->toBe([]);
    });

    it('puts every use-case handler under Application/{Context}/UseCases', function () {
        $violations = [];

        foreach (ruleSourceFiles('app', ['php']) as $file) {
            if (str_ends_with($file, 'Handler.php') && preg_match('#^app/Application/[^/]+/UseCases/#', $file) !== 1) {
                $violations[] = ['subject' => $file, 'message' => 'a *Handler is a use case and lives in app/Application/{Context}/UseCases'];
            }
        }

        expect(ruleUnexcused('layers', 'handlers', $violations))->toBe([]);
    });

    it('keeps domain services at the context level, never inside an aggregate', function () {
        $violations = [];

        foreach (layersContexts() as $context) {
            foreach (ruleGlob(ruleProjectPath(layersSpec()['domain_path'].'/'.$context.'/*/Services'), GLOB_ONLYDIR) as $directory) {
                $violations[] = [
                    'subject' => ltrim(substr($directory, strlen(ruleProjectPath())), '/'),
                    'message' => sprintf('move it to %s/%s/Services', layersSpec()['domain_path'], $context),
                ];
            }
        }

        expect(ruleUnexcused('layers', 'service-location', $violations))->toBe([]);
    });

    it('gives every domain service one handle() in one of the three shapes', function () {
        $method = layersSpec()['service_method'];
        $violations = [];

        foreach (layersContexts() as $context) {
            $services = layersSpec()['domain_path'].'/'.$context.'/Services';

            foreach (ruleGlob(ruleProjectPath($services.'/*.php')) as $file) {
                $violations[] = ['subject' => $services.'/'.basename($file), 'message' => 'a service lives in its own folder, Services/{Name}/{Name}Service.php'];
            }

            foreach (ruleGlob(ruleProjectPath($services.'/*'), GLOB_ONLYDIR) as $directory) {
                $name = basename($directory);
                $folder = 'App\\Domain\\'.$context.'\\Services\\'.$name;
                $class = $folder.'\\'.$name.'Service';

                foreach (ruleSourceFiles($services.'/'.$name, ['php']) as $file) {
                    if (str_ends_with($file, 'Service.php') && basename($file) !== $name.'Service.php') {
                        $violations[] = ['subject' => $file, 'message' => sprintf('Services/%s holds %sService only — give this service its own folder', $name, $name)];
                    }
                }

                if (! class_exists($class)) {
                    $violations[] = ['subject' => $services.'/'.$name, 'message' => sprintf('holds no %sService — name the folder after its service', $name)];

                    continue;
                }

                $reflection = new ReflectionClass($class);
                $public = array_values(array_map(
                    fn (ReflectionMethod $public) => $public->getName(),
                    array_filter(
                        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                        fn (ReflectionMethod $public) => $public->getDeclaringClass()->getName() === $class && ! $public->isConstructor(),
                    ),
                ));

                if ($public !== [$method]) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('exposes %s — its one public method is %s()', $public === [] ? 'nothing' : implode('(), ', $public).'()', $method)];

                    continue;
                }

                $reason = layersServiceShapeViolation($reflection->getMethod($method), $folder, $name);

                if ($reason !== null) {
                    $violations[] = ['subject' => $class, 'message' => $reason];
                }
            }
        }

        expect(ruleUnexcused('layers', 'service-shape', $violations))->toBe([]);
    });

    it('declares every port the infrastructure implements in a Ports folder', function () {
        $violations = [];

        foreach (layersDomainClasses() as $class) {
            if (! interface_exists($class)) {
                continue;
            }

            if (str_contains($class, '\\Services\\')) {
                $violations[] = ['subject' => $class, 'message' => 'an interface is a port, not a service — move it to {Context}/Ports'];

                continue;
            }

            if (str_ends_with($class, 'Repository') || str_contains($class, '\\Ports\\')) {
                continue;
            }

            foreach (ruleSourceFiles('app/Infra', ['php']) as $file) {
                if (in_array($class, ruleDependenciesOf($file), true) && preg_match('/\bimplements\b[^{]*\b'.preg_quote(substr(strrchr($class, '\\') ?: $class, 1), '/').'\b/', ruleCodeWithoutComments($file)) === 1) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('is implemented by %s — a port lives in {Context}/Ports', $file)];
                }
            }
        }

        expect(ruleUnexcused('layers', 'ports', $violations))->toBe([]);
    });

    it('lets a repository hand out aggregate roots only', function () {
        $violations = [];

        foreach (layersDomainClasses() as $class) {
            if (! interface_exists($class) || ! str_ends_with($class, 'Repository')) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $types = [$method->getReturnType(), ...array_map(fn (ReflectionParameter $parameter) => $parameter->getType(), $method->getParameters())];

                foreach ($types as $type) {
                    foreach (layersNamedTypes($type) as $name) {
                        if (is_subclass_of($name, DomainEntity::class) && ! is_subclass_of($name, AggregateRoot::class)) {
                            $violations[] = ['subject' => $class, 'message' => sprintf('%s() exposes the child entity %s', $method->getName(), $name)];
                        }
                    }
                }
            }
        }

        expect(ruleUnexcused('layers', 'aggregate-repository', $violations))->toBe([]);
    });

    it('marks every aggregate root as one and keeps child entities out of that hierarchy', function () {
        $violations = [];

        foreach (layersDomainClasses() as $class) {
            if (! str_ends_with($class, 'Entity') || ! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $isChild = str_contains($class, '\\Entities\\');

            if ($isChild && (! is_subclass_of($class, DomainEntity::class) || is_subclass_of($class, AggregateRoot::class))) {
                $violations[] = ['subject' => $class, 'message' => 'a child entity in Entities/ extends DomainEntity, never AggregateRoot'];
            }

            if (! $isChild && ! is_subclass_of($class, AggregateRoot::class)) {
                $violations[] = ['subject' => $class, 'message' => 'an entity at the aggregate root extends AggregateRoot'];
            }
        }

        expect(ruleUnexcused('layers', 'aggregate-root', $violations))->toBe([]);
    });
});
