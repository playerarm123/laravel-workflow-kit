<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of testing.md — change the two together.
 *
 * Every subject is found under its folder, never listed by hand. A subject's test lives at
 * its own path mirrored from `app/` into `tests/{Suite}/`, with `Test` appended. A domain
 * service's suite is read from its constructor through reflection: it is Feature when it
 * injects a repository, directly or through another service, and Unit otherwise. A controller
 * gets one test per action, inside a folder named after it, and `__invoke` is tested by the
 * mirror of the controller itself. Repositories, list queries and policies are left to the
 * `tests` checks of their own rules.
 *
 * A page under `pages_path` is a subject too: its Browser test mirrors its path, with every
 * kebab-case segment in StudlyCase (`audit-entries/index.tsx` → `AuditEntries/IndexTest.php`).
 * The starter kit's pages, and the kit's error page, are left out.
 *
 * The other way round, every test under `stray_paths` must point at a subject: a file under
 * one of `mirrors` (read back through the test's own path), a folder in `folders` (a test
 * about a whole folder, such as the migrated schema), or a controller action. The starter
 * kit's own tests are left where the kit ships them.
 *
 * @return array{
 *     kinds: array<string, array{path: string, match: string, suite: 'Unit'|'Feature'|'by-repository'}>,
 *     controllers_path: string,
 *     starter_kit: list<string>,
 *     pages_path: string,
 *     starter_kit_pages: list<string>,
 *     repository_suffix: string,
 *     service_suffix: string,
 *     pest_file: string,
 *     booted_suites: list<string>,
 *     unit_path: string,
 *     unit_forbidden: array<string, string>,
 *     doubles_path: string,
 *     fake_repository: list<string>,
 *     stray_paths: list<string>,
 *     mirrors: list<array{source: string, tests: list<string>, case: 'studly'|'snake'|'kebab', extension: string}>,
 *     folders: array<string, string>,
 *     starter_kit_tests: list<string>,
 * }
 */
function testingSpec(): array
{
    return [
        'kinds' => [
            'entity' => ['path' => 'app/Domain', 'match' => '#Entity\.php$#', 'suite' => 'Unit'],
            'value object' => ['path' => 'app/Domain', 'match' => '#/ValueObjects/[^/]+\.php$#', 'suite' => 'Unit'],
            'domain service' => ['path' => 'app/Domain', 'match' => '#^app/Domain/[^/]+/Services/[^/]+/[^/]+Service\.php$#', 'suite' => 'by-repository'],
            'handler' => ['path' => 'app/Application', 'match' => '#^app/Application/[^/]+/UseCases/.+Handler\.php$#', 'suite' => 'Feature'],
            'console command' => ['path' => 'app/Console/Commands', 'match' => '#Command\.php$#', 'suite' => 'Feature'],
            'middleware' => ['path' => 'app/Http/Middleware', 'match' => '#\.php$#', 'suite' => 'Feature'],
        ],
        'controllers_path' => 'app/Http/Controllers',
        'starter_kit' => ['App\Http\Controllers\Settings'],
        'pages_path' => 'resources/js/pages',
        'starter_kit_pages' => [
            'resources/js/pages/auth',
            'resources/js/pages/settings',
            'resources/js/pages/dashboard.tsx',
            'resources/js/pages/welcome.tsx',
            'resources/js/pages/error.tsx',
        ],
        'repository_suffix' => 'Repository',
        'service_suffix' => 'Service',
        'pest_file' => 'tests/Pest.php',
        'booted_suites' => ['Feature', 'Browser'],
        'unit_path' => 'tests/Unit',
        'unit_forbidden' => [
            '/(?<![\w>:$])app\s*\(/' => 'resolves from the container',
            '/\bRefreshDatabase\b/' => 'touches the database',
            '/::factory\s*\(/' => 'builds a model through a factory',
            '/\bIlluminate\\\\Support\\\\Facades\\\\/' => 'calls a facade',
            '/\bDB::/' => 'touches the database',
            '/\$this->(mock|partialMock|spy|actingAs|get|post|put|patch|delete|getJson|postJson)\s*\(/' => 'uses the Laravel TestCase',
            '/->extend\s*\(|\bTests\\\\TestCase\b/' => 'binds the Laravel TestCase',
        ],
        'doubles_path' => 'tests',
        'fake_repository' => [
            '/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+\w+[^{\n]*\bimplements\b[^{\n]*Repository\b/m',
            '/\bnew\s+class\b[^{]*\bimplements\b[^{]*Repository\b/',
        ],
        'stray_paths' => ['tests/Unit', 'tests/Feature', 'tests/Browser'],
        'mirrors' => [
            ['source' => 'app', 'tests' => ['tests/Unit', 'tests/Feature'], 'case' => 'studly', 'extension' => 'php'],
            ['source' => 'database/seeders', 'tests' => ['tests/Feature/Database/Seeders'], 'case' => 'studly', 'extension' => 'php'],
            ['source' => 'database/factories', 'tests' => ['tests/Feature/Database/Factories'], 'case' => 'studly', 'extension' => 'php'],
            ['source' => 'config', 'tests' => ['tests/Feature/Config'], 'case' => 'snake', 'extension' => 'php'],
            ['source' => 'resources/js/pages', 'tests' => ['tests/Browser'], 'case' => 'kebab', 'extension' => 'tsx'],
        ],
        'folders' => [
            'tests/Feature/Database/MigrationsTest.php' => 'database/migrations',
            'tests/Feature/ModelsTest.php' => 'app/Models',
        ],
        'starter_kit_tests' => ['tests/Feature/Auth', 'tests/Feature/Settings', 'tests/Feature/DashboardTest.php'],
    ];
}

/**
 * The test a file under `app/` mirrors into a suite.
 */
function testingMirrorOf(string $file, string $suite): string
{
    return 'tests/'.$suite.'/'.substr($file, strlen('app/'), -strlen('.php')).'Test.php';
}

/**
 * The Browser test a page mirrors: every kebab-case segment of its path in StudlyCase.
 */
function testingPageTestOf(string $page): string
{
    $path = substr($page, strlen(testingSpec()['pages_path']) + 1, -strlen('.tsx'));

    return 'tests/Browser/'.implode('/', array_map(
        fn (string $segment): string => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $segment))),
        explode('/', $path),
    )).'Test.php';
}

/**
 * The file a test mirrors, or the controller action it tests, or null when it points at nothing.
 */
function testingSubjectOfTest(string $test): ?string
{
    $folder = testingSpec()['folders'][$test] ?? null;

    if ($folder !== null && is_dir(ruleProjectPath($folder))) {
        return $folder;
    }

    foreach (testingSpec()['mirrors'] as ['source' => $source, 'tests' => $roots, 'case' => $case, 'extension' => $extension]) {
        foreach ($roots as $root) {
            if (! str_starts_with($test, $root.'/')) {
                continue;
            }

            $path = substr($test, strlen($root) + 1, -strlen('Test.php'));

            if ($case !== 'studly') {
                $path = strtolower((string) preg_replace('/(?<!^|\/)[A-Z]/', ($case === 'snake' ? '_' : '-').'$0', $path));
            }

            if (is_file(ruleProjectPath($source.'/'.$path.'.'.$extension))) {
                return $source.'/'.$path.'.'.$extension;
            }
        }
    }

    $prefix = 'tests/Feature/'.substr(testingSpec()['controllers_path'], strlen('app/')).'/';

    if (! str_starts_with($test, $prefix)) {
        return null;
    }

    $controllerFile = 'app/'.substr(dirname($test), strlen('tests/Feature/')).'.php';

    if (! is_file(ruleProjectPath($controllerFile)) || ! class_exists($controller = ruleClassOf($controllerFile))) {
        return null;
    }

    $action = lcfirst(basename($test, 'Test.php'));

    foreach (ruleControllerActions($controller) as $method) {
        if ($method->getName() === $action) {
            return $controller.'::'.$action;
        }
    }

    return null;
}

/**
 * Whether a class takes a repository in its constructor, directly or through a service it takes.
 *
 * @param  class-string  $class
 * @param  list<string>  $seen
 */
function testingInjectsRepository(string $class, array $seen = []): bool
{
    $constructor = (new ReflectionClass($class))->getConstructor();

    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        $name = $type->getName();

        if (str_ends_with($name, testingSpec()['repository_suffix'])) {
            return true;
        }

        if (str_ends_with($name, testingSpec()['service_suffix'])
            && class_exists($name)
            && ! in_array($name, $seen, true)
            && testingInjectsRepository($name, [...$seen, $class])) {
            return true;
        }
    }

    return false;
}

/**
 * Every subject that must have a test of its own: its name, the test it needs, and the
 * test it would be in the other suite.
 *
 * @return list<array{subject: string, test: string, elsewhere: ?string}>
 */
function testingSubjects(): array
{
    $subjects = [];

    foreach (testingSpec()['kinds'] as ['path' => $path, 'match' => $match, 'suite' => $suite]) {
        foreach (ruleSourceFiles($path, ['php']) as $file) {
            if (preg_match($match, $file) !== 1) {
                continue;
            }

            $class = ruleClassOf($file);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isEnum()) {
                continue;
            }

            $resolved = $suite === 'by-repository' ? (testingInjectsRepository($class) ? 'Feature' : 'Unit') : $suite;
            $other = $resolved === 'Unit' ? 'Feature' : 'Unit';

            $subjects[] = ['subject' => $class, 'test' => testingMirrorOf($file, $resolved), 'elsewhere' => testingMirrorOf($file, $other)];
        }
    }

    foreach (ruleConcreteClassesIn(testingSpec()['controllers_path'], testingSpec()['starter_kit']) as $controller) {
        $file = substr((string) (new ReflectionClass($controller))->getFileName(), strlen(ruleProjectPath()) + 1);

        foreach (ruleControllerActions($controller) as $action) {
            $subjects[] = [
                'subject' => $controller.'::'.$action->getName(),
                'test' => $action->getName() === '__invoke'
                    ? testingMirrorOf($file, 'Feature')
                    : 'tests/Feature/'.substr($file, strlen('app/'), -strlen('.php')).'/'.ucfirst($action->getName()).'Test.php',
                'elsewhere' => null,
            ];
        }
    }

    foreach (ruleSourceFiles(testingSpec()['pages_path'], ['tsx'], testingSpec()['starter_kit_pages']) as $page) {
        if (in_array($page, testingSpec()['starter_kit_pages'], true)) {
            continue;
        }

        $subjects[] = ['subject' => $page, 'test' => testingPageTestOf($page), 'elsewhere' => null];
    }

    return $subjects;
}

describe('testing', function () {
    it('gives every subject a test at the path mirrored from its own', function () {
        $violations = [];

        foreach (testingSubjects() as ['subject' => $subject, 'test' => $test, 'elsewhere' => $elsewhere]) {
            if (is_file(ruleProjectPath($test))) {
                continue;
            }

            $reachesRepository = str_ends_with($subject, testingSpec()['service_suffix']) && str_starts_with($test, 'tests/Feature/');

            $violations[] = ['subject' => $subject, 'message' => $elsewhere !== null && is_file(ruleProjectPath($elsewhere))
                ? sprintf('is tested in %s — move it to %s%s', $elsewhere, $test, $reachesRepository ? ', because the service now reaches a repository, directly or through another service, and resolve it from the container there' : '')
                : sprintf('has no %s', $test)];
        }

        expect(ruleUnexcused('testing', 'mirror', $violations))->toBe([]);
    });

    it('keeps no test that mirrors nothing', function () {
        $violations = [];

        foreach (testingSpec()['stray_paths'] as $path) {
            foreach (ruleSourceFiles($path, ['php'], testingSpec()['starter_kit_tests']) as $test) {
                if (! str_ends_with($test, 'Test.php') || in_array($test, testingSpec()['starter_kit_tests'], true)) {
                    continue;
                }

                if (testingSubjectOfTest($test) === null) {
                    $violations[] = ['subject' => $test, 'message' => 'mirrors no file in app/, database/seeders, database/factories, config/ or resources/js/pages, and no controller action — move it to its subject\'s mirror, or merge it into the test already there'];
                }
            }
        }

        expect(ruleUnexcused('testing', 'stray', $violations))->toBe([]);
    });

    it('boots Laravel for Feature and Browser only', function () {
        $file = testingSpec()['pest_file'];
        $code = ruleCodeWithoutComments($file);
        $violations = [];

        if (preg_match('/pest\(\)\s*->\s*extend\(\s*(?:\\\\?Tests\\\\)?TestCase::class\s*\)(.*?)->\s*in\(([^)]*)\)/s', $code, $matches) !== 1) {
            $violations[] = ['subject' => $file, 'message' => 'binds no TestCase — add pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(…)'];
        } else {
            if (! str_contains($matches[1], 'RefreshDatabase')) {
                $violations[] = ['subject' => $file, 'message' => 'the TestCase binding must ->use(RefreshDatabase::class)'];
            }

            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $matches[2], $suites);

            if (array_diff(testingSpec()['booted_suites'], $suites[1]) !== []) {
                $violations[] = ['subject' => $file, 'message' => sprintf('the TestCase binding must cover %s', implode(' and ', testingSpec()['booted_suites']))];
            }
        }

        preg_match_all('/->\s*in\(([^)]*)\)/', $code, $bindings);

        foreach ($bindings[1] as $binding) {
            if (preg_match('/[\'"]Unit[\'"]/', $binding) === 1) {
                $violations[] = ['subject' => $file, 'message' => 'binds something to Unit — Unit tests run on the bare PHPUnit TestCase'];
            }
        }

        expect(ruleUnexcused('testing', 'pest-config', $violations))->toBe([]);
    });

    it('keeps Unit tests away from Laravel', function () {
        $violations = [];
        $files = ruleSourceFiles(testingSpec()['unit_path'], ['php']);

        foreach (testingSpec()['unit_forbidden'] as $pattern => $reason) {
            $violations = [...$violations, ...ruleCodeMatches($files, $pattern, $reason.' — a Unit test builds its subject by hand; move the test to Feature if it needs Laravel')];
        }

        expect(ruleUnexcused('testing', 'unit-pure', $violations))->toBe([]);
    });

    it('never fakes a repository', function () {
        $violations = [];

        foreach (ruleSourceFiles(testingSpec()['doubles_path'], ['php']) as $file) {
            $code = ruleCodeWithoutComments($file);

            foreach (testingSpec()['fake_repository'] as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = ['subject' => $file, 'message' => 'implements a repository — test against the real one and the database; force a failure with $this->mock() instead'];

                    break;
                }
            }
        }

        expect(ruleUnexcused('testing', 'doubles', $violations))->toBe([]);
    });
});
