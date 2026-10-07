<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of repositories.md — change the two together.
 *
 * Every repository interface under app/Domain is paired with `Eloquent{Name}` here, written
 * as one-line calls to EloquentRepository, and proven by a test file that carries the case
 * titles below — the shared `repositoryContract()` registers the ones every repository needs.
 * Which titles are required follows the methods the interface declares and whether the model
 * uses SoftDeletes.
 *
 * @return array{
 *     kit_files: list<string>,
 *     repository_path: string,
 *     repository_namespace: string,
 *     test_path: string,
 *     required_methods: list<string>,
 *     contract_call: string,
 *     models_namespace: string,
 *     forbidden_code: array<string, string>,
 *     titles: array<string, list<string>>,
 * }
 */
function repositoriesSpec(): array
{
    return [
        'kit_files' => [
            'app/Infra/Persistence/Eloquent/Repositories/EloquentRepository.php',
            'app/Infra/Persistence/Eloquent/Repositories/WriteMode.php',
            'app/Infra/Logging/EntityPayloads/EntityLogPayload.php',
            'app/Domain/Shared/Exceptions/RepositoryException.php',
            'app/Domain/Shared/Exceptions/EntityNotFoundException.php',
            'app/Domain/Shared/ClonableAggregate.php',
            'tests/Feature/Infra/Persistence/Eloquent/RepositoryContract.php',
        ],
        'repository_path' => 'app/Infra/Persistence/Eloquent/Repositories',
        'repository_namespace' => 'App\Infra\Persistence\Eloquent\Repositories',
        'test_path' => 'tests/Feature/Infra/Persistence/Eloquent/Repositories',
        'required_methods' => ['save', 'update'],
        'contract_call' => 'repositoryContract(',
        'models_namespace' => 'App\Models',
        'forbidden_code' => [
            '#/\*\*\s*@var\b#' => 'an inline @var — the @extends pair already types the helpers',
            '#\$currentModel\b#' => 'the old toModel() parameter',
            '#->save\(\)#' => 'Model::save() — write through the base helpers',
            '#\bfindOrNew\(#' => 'a read before the write — upsert decides in one statement',
        ],
        'titles' => [
            'always' => [
                'reads back what it saved, field for field',
                'writes over an existing row instead of failing like a plain insert',
                'wraps a failed write in its repository exception',
                'wraps a row it cannot rebuild in a reconstitute failure',
                'updates an existing row without inserting a new one',
                'throws not found from update when the row no longer exists',
            ],
            'getById' => ['throws not found from getById for an unknown id'],
            'delete' => [
                'deletes the row and everything the aggregate owns',
                'throws not found from delete when the row no longer exists',
            ],
            'delete:soft' => [
                'soft deletes the row so no read finds it any more',
                'throws not found from delete when the row no longer exists',
            ],
            'restore' => ['restores a soft deleted row'],
            'purge' => ['purges a soft deleted row for good'],
            'clone' => [
                'clones into a new aggregate with fresh ids and leaves the source untouched',
                'refuses to clone onto an id that already exists',
            ],
        ],
    ];
}

/**
 * Every repository interface declared under app/Domain.
 *
 * @return list<class-string>
 */
function repositoriesInterfaces(): array
{
    $interfaces = [];

    foreach (ruleSourceFiles('app/Domain', ['php']) as $file) {
        $name = ruleClassOf($file);

        if (str_ends_with($name, 'Repository') && interface_exists($name)) {
            $interfaces[] = $name;
        }
    }

    sort($interfaces);

    return $interfaces;
}

/**
 * @param  class-string  $interface
 * @return class-string
 */
function repositoriesImplementationOf(string $interface): string
{
    /** The name is built, then checked with class_exists() by every caller. */
    return repositoriesSpec()['repository_namespace'].'\\Eloquent'.substr(strrchr($interface, '\\') ?: $interface, 1);
}

/**
 * @param  class-string  $class
 */
function repositoriesFileOf(string $class): string
{
    return repositoriesSpec()['repository_path'].'/'.substr(strrchr($class, '\\') ?: $class, 1).'.php';
}

/**
 * The case titles a repository's test file must carry.
 *
 * @param  class-string  $interface
 * @return list<string>
 */
function repositoriesRequiredTitles(string $interface): array
{
    $titles = repositoriesSpec()['titles'];
    $methods = array_map(fn (ReflectionMethod $method) => $method->getName(), (new ReflectionClass($interface))->getMethods());
    $required = $titles['always'];

    if (in_array('getById', $methods, true)) {
        $required = [...$required, ...$titles['getById']];
    }

    if (in_array('delete', $methods, true)) {
        $required = [...$required, ...(repositoriesUsesSoftDeletes($interface) ? $titles['delete:soft'] : $titles['delete'])];
    }

    foreach (['restore', 'purge', 'clone'] as $optional) {
        if (in_array($optional, $methods, true)) {
            $required = [...$required, ...$titles[$optional]];
        }
    }

    return array_values(array_unique($required));
}

/**
 * Reads the model from the repository's newQuery() body, so the check needs no database.
 *
 * @param  class-string  $interface
 */
function repositoriesUsesSoftDeletes(string $interface): bool
{
    $file = repositoriesFileOf(repositoriesImplementationOf($interface));

    if (! is_file(ruleProjectPath($file))) {
        return false;
    }

    $code = ruleCodeWithoutComments($file);

    if (preg_match('/function newQuery\(\)[^{]*\{\s*return\s+(\w+)::/', $code, $matches) !== 1) {
        return false;
    }

    foreach (ruleDependenciesOf($file) as $dependency) {
        if (str_ends_with($dependency, '\\'.$matches[1]) && class_exists($dependency)) {
            return in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($dependency), true);
        }
    }

    return false;
}

describe('repositories', function () {
    it('ships the persistence kit at its fixed home', function () {
        $violations = [];

        foreach (repositoriesSpec()['kit_files'] as $file) {
            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => 'is missing — copy it from the kit'];
            }
        }

        expect(ruleUnexcused('repositories', 'kit-files', $violations))->toBe([]);
    });

    it('gives every repository interface an Eloquent implementation', function () {
        $violations = [];

        foreach (repositoriesInterfaces() as $interface) {
            $class = repositoriesImplementationOf($interface);

            if (! class_exists($class) || ! in_array($interface, class_implements($class), true)) {
                $violations[] = ['subject' => $interface, 'message' => sprintf('needs %s implementing it', $class)];
            }
        }

        expect(ruleUnexcused('repositories', 'implementation', $violations))->toBe([]);
    });

    it('binds every repository interface to its implementation in a provider', function () {
        $providers = implode("\n", array_map(
            fn (string $file) => ruleCodeWithoutComments($file),
            array_values(array_filter(ruleSourceFiles('app', ['php']), fn (string $file) => str_ends_with($file, 'ServiceProvider.php'))),
        ));
        $violations = [];

        foreach (repositoriesInterfaces() as $interface) {
            $short = substr(strrchr($interface, '\\') ?: $interface, 1);
            $pattern = sprintf('/\b%s::class\s*=>\s*\\\\?(?:\w+\\\\)*Eloquent%s::class/', preg_quote($short, '/'), preg_quote($short, '/'));

            if (preg_match($pattern, $providers) !== 1) {
                $violations[] = ['subject' => $interface, 'message' => sprintf('is not bound to Eloquent%s in any *ServiceProvider', $short)];
            }
        }

        expect(ruleUnexcused('repositories', 'binding', $violations))->toBe([]);
    });

    it('writes every repository in the one locked shape', function () {
        $violations = [];

        foreach (repositoriesInterfaces() as $interface) {
            $class = repositoriesImplementationOf($interface);
            $file = repositoriesFileOf($class);

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, repositoriesSpec()['repository_namespace'].'\\EloquentRepository')) {
                $violations[] = ['subject' => $class, 'message' => 'must extend EloquentRepository'];
            }

            $source = (string) file_get_contents(ruleProjectPath($file));

            if (preg_match('/@extends\s+EloquentRepository<\s*\w+\s*,\s*\w+\s*>/', $source) !== 1) {
                $violations[] = ['subject' => $class, 'message' => 'needs `@extends EloquentRepository<Model, Entity>` above the class'];
            }

            foreach (repositoriesSpec()['forbidden_code'] as $pattern => $what) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = ['subject' => $class, 'message' => 'contains '.$what];
                }
            }

            $models = preg_quote(repositoriesSpec()['models_namespace'], '/');

            preg_match_all('/^use\s+'.$models.'\\\\(\w+)(?:\s+as\s+(\w+))?;/m', $source, $imports, PREG_SET_ORDER);

            foreach ($imports as $import) {
                $usedAs = $import[2] ?? $import[1];

                if (preg_match('/\b'.preg_quote($usedAs, '/').'::create\(/', $source) === 1) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('calls %s::create() — write through the base helpers', $usedAs)];
                }
            }
        }

        expect(ruleUnexcused('repositories', 'shape', $violations))->toBe([]);
    });

    it('exposes exactly what the interface declares, every override marked', function () {
        $violations = [];

        foreach (repositoriesInterfaces() as $interface) {
            $class = repositoriesImplementationOf($interface);

            if (! class_exists($class)) {
                continue;
            }

            $declared = array_map(fn (ReflectionMethod $method) => $method->getName(), (new ReflectionClass($interface))->getMethods());

            foreach (repositoriesSpec()['required_methods'] as $required) {
                if (! in_array($required, $declared, true)) {
                    $violations[] = ['subject' => $interface, 'message' => sprintf('must declare %s()', $required)];
                }
            }

            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $overrides = in_array($method->getName(), $declared, true)
                    || ($parent !== false && $parent->hasMethod($method->getName()));

                if ($method->isPublic() && ! in_array($method->getName(), $declared, true)) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('%s() is public but not on %s', $method->getName(), $interface)];
                }

                if ($overrides && $method->getAttributes(Override::class) === []) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('%s() needs #[Override]', $method->getName())];
                }
            }
        }

        expect(ruleUnexcused('repositories', 'public-surface', $violations))->toBe([]);
    });

    it('proves every repository with the required test cases', function () {
        $violations = [];

        foreach (repositoriesInterfaces() as $interface) {
            $class = repositoriesImplementationOf($interface);
            $test = repositoriesSpec()['test_path'].'/'.substr(strrchr($class, '\\') ?: $class, 1).'Test.php';

            if (! is_file(ruleProjectPath($test))) {
                $violations[] = ['subject' => $test, 'message' => 'is missing'];

                continue;
            }

            $source = (string) file_get_contents(ruleProjectPath($test));
            $byContract = str_contains($source, repositoriesSpec()['contract_call'])
                ? [...repositoriesSpec()['titles']['always'], ...repositoriesSpec()['titles']['getById']]
                : [];

            foreach (repositoriesRequiredTitles($interface) as $title) {
                if (! in_array($title, $byContract, true) && ! str_contains($source, "it('".$title."'")) {
                    $violations[] = ['subject' => $test, 'message' => sprintf("lacks it('%s')", $title)];
                }
            }
        }

        expect(ruleUnexcused('repositories', 'tests', $violations))->toBe([]);
    });
});
