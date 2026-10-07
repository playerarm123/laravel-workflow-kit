<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of list-queries.md — change the two together.
 *
 * Every list read port (`List{Name}Query` under app/Application/{Context}/UseCases) is paired
 * with `EloquentList{Name}Query` in the infrastructure, built on EloquentListQuery and proven
 * by a test file that runs the shared listQueryContract().
 *
 * @return array{
 *     kit_files: list<string>,
 *     port_glob: string,
 *     adapter_path: string,
 *     adapter_namespace: string,
 *     test_path: string,
 *     contract_call: string,
 *     required_call: string,
 *     forbidden_code: array<string, string>,
 *     row_glob: string,
 * }
 */
function listQueriesSpec(): array
{
    return [
        'kit_files' => [
            'app/Infra/Persistence/Eloquent/Queries/EloquentListQuery.php',
            'app/Application/Concerns/ListQueryException.php',
            'app/Application/Concerns/PageSize.php',
            'app/Application/Concerns/DateRange.php',
            'app/Application/Concerns/SortsAList.php',
            'tests/Feature/Infra/Persistence/Eloquent/ListQueryContract.php',
        ],
        'port_glob' => 'app/Application/*/UseCases/List*/List*Query.php',
        'adapter_path' => 'app/Infra/Persistence/Eloquent/Queries',
        'adapter_namespace' => 'App\Infra\Persistence\Eloquent\Queries',
        'test_path' => 'tests/Feature/Infra/Persistence/Eloquent/Queries',
        'contract_call' => 'listQueryContract(',
        'required_call' => '$this->paginateRows(',
        'forbidden_code' => [
            '#->paginate\(#' => '->paginate() — page through paginateRows(), which adds the tie-breaker and the error wrap',
            '#\b(I?LIKE)\b#' => 'a hand-written LIKE/ILIKE — search through applySearch(), which keeps it inside the scope and works on every driver',
            '#/\*\*\s*@var\b#' => 'an inline @var',
        ],
        'row_glob' => 'app/Application/*/UseCases/List*/*ListRow.php',
    ];
}

/**
 * @return list<class-string>
 */
function listQueriesPorts(): array
{
    $ports = [];

    foreach (ruleGlob(ruleProjectPath(listQueriesSpec()['port_glob'])) as $path) {
        $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
        $name = ruleClassOf($file);

        if (interface_exists($name)) {
            $ports[] = $name;
        }
    }

    sort($ports);

    return $ports;
}

/**
 * @param  class-string  $port
 */
function listQueriesAdapterOf(string $port): string
{
    return listQueriesSpec()['adapter_namespace'].'\\Eloquent'.substr(strrchr($port, '\\') ?: $port, 1);
}

function listQueriesShort(string $class): string
{
    return substr(strrchr($class, '\\') ?: $class, 1);
}

describe('list queries', function () {
    it('ships the list read kit at its fixed home', function () {
        $violations = [];

        foreach (listQueriesSpec()['kit_files'] as $file) {
            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => 'is missing — copy it from the kit'];
            }
        }

        expect(ruleUnexcused('list-queries', 'kit-files', $violations))->toBe([]);
    });

    it('gives every list read port an Eloquent adapter', function () {
        $violations = [];

        foreach (listQueriesPorts() as $port) {
            $adapter = listQueriesAdapterOf($port);

            if (! class_exists($adapter) || ! in_array($port, class_implements($adapter), true)) {
                $violations[] = ['subject' => $port, 'message' => sprintf('needs %s implementing it', $adapter)];
            }
        }

        expect(ruleUnexcused('list-queries', 'implementation', $violations))->toBe([]);
    });

    it('binds every list read port to its adapter in a provider', function () {
        $providers = implode("\n", array_map(
            fn (string $file) => ruleCodeWithoutComments($file),
            array_values(array_filter(ruleSourceFiles('app', ['php']), fn (string $file) => str_ends_with($file, 'ServiceProvider.php'))),
        ));
        $violations = [];

        foreach (listQueriesPorts() as $port) {
            $short = listQueriesShort($port);

            if (preg_match(sprintf('/\b%s::class\s*=>\s*\\\\?(?:\w+\\\\)*Eloquent%s::class/', preg_quote($short, '/'), preg_quote($short, '/')), $providers) !== 1) {
                $violations[] = ['subject' => $port, 'message' => sprintf('is not bound to Eloquent%s in any *ServiceProvider', $short)];
            }
        }

        expect(ruleUnexcused('list-queries', 'binding', $violations))->toBe([]);
    });

    it('writes every adapter in the one locked shape', function () {
        $violations = [];

        foreach (listQueriesPorts() as $port) {
            $adapter = listQueriesAdapterOf($port);

            if (! class_exists($adapter)) {
                continue;
            }

            if (! is_subclass_of($adapter, listQueriesSpec()['adapter_namespace'].'\\EloquentListQuery')) {
                $violations[] = ['subject' => $adapter, 'message' => 'must extend EloquentListQuery'];
            }

            $code = ruleCodeWithoutComments(listQueriesSpec()['adapter_path'].'/'.listQueriesShort($adapter).'.php');

            if (! str_contains($code, listQueriesSpec()['required_call'])) {
                $violations[] = ['subject' => $adapter, 'message' => 'must page through $this->paginateRows()'];
            }

            foreach (listQueriesSpec()['forbidden_code'] as $pattern => $what) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = ['subject' => $adapter, 'message' => 'contains '.$what];
                }
            }
        }

        expect(ruleUnexcused('list-queries', 'shape', $violations))->toBe([]);
    });

    it('exposes only what the port declares, every override marked', function () {
        $violations = [];

        foreach (listQueriesPorts() as $port) {
            $adapter = listQueriesAdapterOf($port);

            if (! class_exists($adapter)) {
                continue;
            }

            $declared = array_map(fn (ReflectionMethod $method) => $method->getName(), (new ReflectionClass($port))->getMethods());

            foreach ((new ReflectionClass($adapter))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $adapter || $method->isConstructor()) {
                    continue;
                }

                if (! in_array($method->getName(), $declared, true)) {
                    $violations[] = ['subject' => $adapter, 'message' => sprintf('%s() is public but not on %s', $method->getName(), $port)];
                } elseif ($method->getAttributes(Override::class) === []) {
                    $violations[] = ['subject' => $adapter, 'message' => sprintf('%s() needs #[Override]', $method->getName())];
                }
            }
        }

        expect(ruleUnexcused('list-queries', 'public-surface', $violations))->toBe([]);
    });

    it('makes every list row a final Arrayable wire contract', function () {
        $violations = [];

        foreach (ruleGlob(ruleProjectPath(listQueriesSpec()['row_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $row = ruleClassOf($file);

            if (! class_exists($row)) {
                continue;
            }

            $reflection = new ReflectionClass($row);

            if (! $reflection->isFinal() || ! $reflection->implementsInterface('Illuminate\Contracts\Support\Arrayable')) {
                $violations[] = ['subject' => $row, 'message' => 'must be final and implement Arrayable'];
            }
        }

        expect(ruleUnexcused('list-queries', 'rows', $violations))->toBe([]);
    });

    it('proves every adapter with the list query contract', function () {
        $violations = [];

        foreach (listQueriesPorts() as $port) {
            $test = listQueriesSpec()['test_path'].'/'.listQueriesShort(listQueriesAdapterOf($port)).'Test.php';

            if (! is_file(ruleProjectPath($test))) {
                $violations[] = ['subject' => $test, 'message' => 'is missing'];
            } elseif (! str_contains((string) file_get_contents(ruleProjectPath($test)), listQueriesSpec()['contract_call'])) {
                $violations[] = ['subject' => $test, 'message' => 'must call listQueryContract()'];
            }
        }

        expect(ruleUnexcused('list-queries', 'tests', $violations))->toBe([]);
    });
});
