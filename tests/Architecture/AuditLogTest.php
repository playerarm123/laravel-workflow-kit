<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of audit-log.md — change the two together.
 *
 * A handler writes when it injects a domain repository, or a domain service that injects one
 * (the same test handlers.md uses for transactions). Its `record()` calls are read from its code
 * with comments removed, so an event or a data key built at runtime cannot be checked and is
 * review only.
 *
 * @return array{
 *     kit_files: list<string>,
 *     migration: string,
 *     port: class-string,
 *     port_method: string,
 *     use_cases_glob: string,
 *     adapter_homes: list<string>,
 *     transaction_call: string,
 *     event: string,
 *     personal_keys: list<string>,
 *     actor_keys: string,
 *     lang_files: string,
 *     labels: array{event: string, subject: string, data: string},
 *     page_files: list<string>,
 *     policy: string,
 *     routes: string,
 *     route: string,
 *     test_helpers_file: string,
 *     test_helpers: list<string>,
 *     page_lang_keys: list<string>,
 *     page_lang_prefix: string,
 * }
 */
function auditLogSpec(): array
{
    return [
        'kit_files' => [
            'app/Application/Audit/AuditLog.php',
            'app/Application/Audit/AuditLogException.php',
            'app/Infra/Audit/DatabaseAuditLog.php',
            'app/Infra/Audit/AuditServiceProvider.php',
            'app/Models/AuditEntry.php',
            'tests/Feature/Infra/Audit/DatabaseAuditLogTest.php',
        ],
        'page_files' => [
            'app/Application/Audit/AuditEntryListSort.php',
            'app/Application/Audit/UseCases/ListAuditEntries/AuditEntryListRow.php',
            'app/Application/Audit/UseCases/ListAuditEntries/ListAuditEntriesCommand.php',
            'app/Application/Audit/UseCases/ListAuditEntries/ListAuditEntriesCriteria.php',
            'app/Application/Audit/UseCases/ListAuditEntries/ListAuditEntriesHandler.php',
            'app/Application/Audit/UseCases/ListAuditEntries/ListAuditEntriesQuery.php',
            'app/Application/Audit/UseCases/ListAuditEntries/ListAuditEntriesResult.php',
            'app/Infra/Persistence/Eloquent/Queries/EloquentListAuditEntriesQuery.php',
            'app/Http/Controllers/AuditEntryController.php',
            'database/factories/AuditEntryFactory.php',
            'resources/js/pages/audit-entries/index.tsx',
            'resources/js/components/audit-entry/detail-dialog.tsx',
            'resources/js/components/audit-entry/table-toolbar.tsx',
            'resources/js/types/audit-entry.ts',
            'tests/Feature/Application/Audit/UseCases/ListAuditEntries/ListAuditEntriesHandlerTest.php',
            'tests/Feature/Infra/Persistence/Eloquent/Queries/EloquentListAuditEntriesQueryTest.php',
            'tests/Feature/Http/Controllers/AuditEntryController/IndexTest.php',
            'tests/Browser/AuditEntries/IndexTest.php',
        ],
        'policy' => 'app/Policies/AuditEntryPolicy.php',
        'routes' => 'routes/*.php',
        'route' => '/Route::resource\(\s*[\'"]audit-entries[\'"]\s*,\s*AuditEntryController::class\s*\)\s*->only\(\s*\[\s*[\'"]index[\'"]\s*\]\s*\)/',
        'test_helpers_file' => 'tests/Pest.php',
        'test_helpers' => ['auditLogReader', 'auditLogOutsider'],
        'page_lang_keys' => [
            'audit-entries.title',
            'audit-entries.description',
            'audit-entries.search_placeholder',
            'audit-entries.filter_subject_type',
            'audit-entries.occurred_at',
            'audit-entries.actor',
            'audit-entries.system',
            'audit-entries.event',
            'audit-entries.subject_type',
            'audit-entries.subject_id',
            'audit-entries.data',
            'audit-entries.action_view',
            'audit-entries.detail_title',
            'audit-entries.empty_title',
            'audit-entries.empty_description',
            'audit-entries.no_results_title',
            'audit-entries.no_results_description',
        ],
        'page_lang_prefix' => 'audit-entries.',
        'migration' => 'database/migrations/*_create_audit_entries_table.php',
        'port' => 'App\Application\Audit\AuditLog',
        'port_method' => 'record',
        'use_cases_glob' => 'app/Application/*/UseCases',
        'adapter_homes' => ['app/Infra', 'app/Providers'],
        'transaction_call' => '#\bDB::(transaction|beginTransaction)\s*\(#',
        'event' => '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
        'personal_keys' => ['name', 'username', 'email', 'phone', 'password', 'note', 'address', 'accountnumber', 'accountname', 'token', 'secret'],
        'actor_keys' => '/^(actor|actorid|actortype|[a-z]+by)$/',
        'lang_files' => 'lang/*.json',
        'labels' => [
            'event' => 'audit-entries.events.%s',
            'subject' => 'audit-entries.subject_types.%s',
            'data' => 'audit-entries.data_keys.%s',
        ],
    ];
}

/**
 * The names of the constructor parameters through which a class receives the audit port.
 *
 * @param  class-string  $class
 * @return list<string>
 */
function auditLogPorts(string $class): array
{
    $names = [];

    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        if (in_array(auditLogSpec()['port'], ruleTypeNames($parameter->getType()), true)) {
            $names[] = $parameter->getName();
        }
    }

    return $names;
}

/**
 * The argument text of every `$this->{port}->record(…)` call in a piece of code, read with
 * brackets and quotes balanced so an array literal stays whole.
 *
 * @param  list<string>  $ports
 * @return list<string>
 */
function auditLogRecordCalls(string $code, array $ports): array
{
    if ($ports === []) {
        return [];
    }

    $pattern = sprintf(
        '/\$this->(%s)->%s\s*\(/',
        implode('|', array_map(fn (string $port) => preg_quote($port, '/'), $ports)),
        preg_quote(auditLogSpec()['port_method'], '/'),
    );
    preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);
    $calls = [];

    foreach ($matches[0] as [$match, $offset]) {
        $start = $offset + strlen($match);
        $depth = 1;
        $quote = null;

        for ($i = $start; $i < strlen($code) && $depth > 0; $i++) {
            $char = $code[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            match ($char) {
                '\'', '"' => $quote = $char,
                '(', '[' => $depth++,
                ')', ']' => $depth--,
                default => null,
            };
        }

        $calls[] = substr($code, $start, $i - $start - 1);
    }

    return $calls;
}

/**
 * The literal event of a record() call's argument text, or null when it is built at runtime.
 */
function auditLogEventOf(string $call): ?string
{
    return preg_match("/^\s*'([^'\\\\]*)'\s*,/", $call, $matches) === 1 ? $matches[1] : null;
}

/**
 * The literal keys of the data array in a record() call's argument text.
 *
 * @return list<string>
 */
function auditLogDataKeysOf(string $call): array
{
    preg_match_all("/'([^'\\\\]+)'\s*=>/", $call, $matches);

    return $matches[1];
}

/**
 * Every handler that injects the audit port, with its file, ports and calls.
 *
 * @return list<array{class: class-string, file: string, calls: list<string>}>
 */
function auditLogRecorders(): array
{
    $recorders = [];

    foreach (ruleHandlers(auditLogSpec()['use_cases_glob']) as $handler) {
        if (! class_exists($handler['class'])) {
            continue;
        }

        $ports = auditLogPorts($handler['class']);

        $recorders[] = [
            'class' => $handler['class'],
            'file' => $handler['file'],
            'calls' => auditLogRecordCalls(ruleCodeWithoutComments($handler['file']), $ports),
        ];
    }

    return $recorders;
}

describe('audit log', function () {
    it('ships the audit log kit, its read-only page included, at its fixed home', function () {
        $spec = auditLogSpec();
        $violations = ruleKitFileViolations([...$spec['kit_files'], ...$spec['page_files'], $spec['migration']]);

        expect(ruleUnexcused('audit-log', 'kit-files', $violations))->toBe([]);
    });

    it('binds the audit port to an adapter in a provider', function () {
        $port = auditLogSpec()['port'];
        $short = substr(strrchr($port, '\\') ?: '', 1);
        $providers = implode("\n", array_map(
            fn (string $file) => ruleCodeWithoutComments($file),
            array_values(array_filter(ruleSourceFiles('app', ['php']), fn (string $file) => str_ends_with($file, 'ServiceProvider.php'))),
        ));
        $violations = preg_match('/\b'.preg_quote($short, '/').'::class\s*(=>|,)/', $providers) === 1
            ? []
            : [['subject' => $port, 'message' => 'is not bound to an adapter in any *ServiceProvider']];

        expect(ruleUnexcused('audit-log', 'binding', $violations))->toBe([]);
    });

    it('declares record() and nothing else, so no entry can be changed or removed', function () {
        $spec = auditLogSpec();
        $violations = [];

        if (interface_exists($spec['port'])) {
            $methods = array_map(fn (ReflectionMethod $method) => $method->getName(), (new ReflectionClass($spec['port']))->getMethods());

            if ($methods !== [$spec['port_method']]) {
                $violations[] = ['subject' => $spec['port'], 'message' => sprintf('declares %s — the audit log is append only: %s() and nothing else', implode(', ', $methods) ?: 'nothing', $spec['port_method'])];
            }
        }

        expect(ruleUnexcused('audit-log', 'port', $violations))->toBe([]);
    });

    it('lets only a handler record, and only the infrastructure implement the port', function () {
        $spec = auditLogSpec();
        $handlers = array_column(ruleHandlers($spec['use_cases_glob']), 'file');
        $violations = [];

        foreach (ruleSourceFiles('app', ['php']) as $file) {
            if (in_array($file, $handlers, true) || $file === 'app/Application/Audit/AuditLog.php') {
                continue;
            }

            foreach ($spec['adapter_homes'] as $home) {
                if (str_starts_with($file, $home.'/')) {
                    continue 2;
                }
            }

            if (in_array($spec['port'], ruleDependenciesOf($file), true)) {
                $violations[] = ['subject' => $file, 'message' => 'uses the audit log — only a use-case handler records, inside its own transaction'];
            }
        }

        expect(ruleUnexcused('audit-log', 'home', $violations))->toBe([]);
    });

    it('records from every handler that writes, inside a transaction', function () {
        $spec = auditLogSpec();
        $violations = [];

        foreach (auditLogRecorders() as $recorder) {
            if (ruleHandlerWriters($recorder['class']) === [] && $recorder['calls'] === []) {
                continue;
            }

            if ($recorder['calls'] === []) {
                $violations[] = ['subject' => $recorder['class'], 'message' => sprintf('writes but never calls %s::%s() — inject the port and record what changed', $spec['port'], $spec['port_method'])];

                continue;
            }

            if (preg_match($spec['transaction_call'], ruleCodeWithoutComments($recorder['file'])) !== 1) {
                $violations[] = ['subject' => $recorder['class'], 'message' => 'records with no DB::transaction() — the entry and the change it describes land together or not at all'];
            }
        }

        expect(ruleUnexcused('audit-log', 'record', $violations))->toBe([]);
    });

    it('names every event with a literal {subject}.{verb}', function () {
        $spec = auditLogSpec();
        $violations = [];

        foreach (auditLogRecorders() as $recorder) {
            foreach ($recorder['calls'] as $call) {
                $event = auditLogEventOf($call);

                if ($event === null || preg_match($spec['event'], $event) !== 1) {
                    $violations[] = ['subject' => $recorder['class'], 'message' => sprintf('records `%s` — the event is a string literal like \'customer.created\'', trim(strtok($call, ',') ?: $call))];
                }
            }
        }

        expect(ruleUnexcused('audit-log', 'event', $violations))->toBe([]);
    });

    it('keeps personal data and the actor out of every entry', function () {
        $spec = auditLogSpec();
        $violations = [];

        foreach (auditLogRecorders() as $recorder) {
            foreach ($recorder['calls'] as $call) {
                foreach (auditLogDataKeysOf($call) as $key) {
                    $normalized = strtolower(str_replace(['_', '-'], '', $key));

                    if (in_array($normalized, $spec['personal_keys'], true)) {
                        $violations[] = ['subject' => $recorder['class'], 'message' => sprintf('records "%s" — an entry carries ids, enum values, amounts and codes, never personal data', $key)];
                    } elseif (preg_match($spec['actor_keys'], $normalized) === 1) {
                        $violations[] = ['subject' => $recorder['class'], 'message' => sprintf('records "%s" — the adapter records who acted, a handler never passes it', $key)];
                    }
                }
            }
        }

        expect(ruleUnexcused('audit-log', 'data-keys', $violations))->toBe([]);
    });

    it('gives every event, subject type and data key a label in every language', function () {
        $spec = auditLogSpec();
        $needed = [];

        foreach (auditLogRecorders() as $recorder) {
            foreach ($recorder['calls'] as $call) {
                $event = auditLogEventOf($call);

                if ($event !== null && preg_match($spec['event'], $event) === 1) {
                    $needed[sprintf($spec['labels']['event'], $event)] = true;
                    $needed[sprintf($spec['labels']['subject'], strtok($event, '.'))] = true;
                }

                foreach (auditLogDataKeysOf($call) as $key) {
                    $needed[sprintf($spec['labels']['data'], $key)] = true;
                }
            }
        }

        $violations = [];

        foreach (ruleGlob(ruleProjectPath($spec['lang_files'])) as $path) {
            $lang = 'lang/'.basename($path);
            $translations = ruleReadJson($lang);

            foreach (array_keys($needed) as $key) {
                if (! array_key_exists($key, $translations)) {
                    $violations[] = ['subject' => $lang, 'message' => sprintf('has no "%s" — the audit log page shows the label, never the code alone', $key)];
                }
            }
        }

        expect(ruleUnexcused('audit-log', 'labels', $violations))->toBe([]);
    });

    it('gives the audit log page a policy, a route, the reader helpers and its words', function () {
        $spec = auditLogSpec();
        $violations = [];

        if (! is_file(ruleProjectPath($spec['policy']))) {
            $violations[] = ['subject' => $spec['policy'], 'message' => 'is missing — run `php artisan make:policy AuditEntry` and answer viewAny for the users who may read the log'];
        }

        $routes = implode("\n", array_map(
            fn (string $path): string => (string) file_get_contents($path),
            ruleGlob(ruleProjectPath($spec['routes'])),
        ));

        if (preg_match($spec['route'], $routes) !== 1) {
            $violations[] = ['subject' => $spec['routes'], 'message' => "registers no Route::resource('audit-entries', AuditEntryController::class)->only(['index'])"];
        }

        $pest = is_file(ruleProjectPath($spec['test_helpers_file'])) ? (string) file_get_contents(ruleProjectPath($spec['test_helpers_file'])) : '';

        foreach ($spec['test_helpers'] as $helper) {
            if (preg_match('/\bfunction\s+'.$helper.'\s*\(\s*\)\s*:\s*User\b/', $pest) !== 1) {
                $violations[] = ['subject' => $spec['test_helpers_file'], 'message' => sprintf('declares no %s(): User — the kit\'s page tests sign in through it', $helper)];
            }
        }

        foreach (ruleGlob(ruleProjectPath($spec['lang_files'])) as $path) {
            $lang = 'lang/'.basename($path);
            $translations = ruleReadJson($lang);

            foreach ($spec['page_lang_keys'] as $key) {
                if (! array_key_exists($key, $translations)) {
                    $violations[] = ['subject' => $lang, 'message' => sprintf('has no "%s", a word the audit log page shows', $key)];
                }
            }
        }

        $labels = array_map(fn (string $label): string => strstr($label, '%s', true) ?: $label, $spec['labels']);

        foreach (array_filter($spec['page_files'], fn (string $path): bool => str_starts_with($path, 'resources/js/')) as $path) {
            preg_match_all('/[\'"`]('.preg_quote($spec['page_lang_prefix'], '/').'[a-z_.]+)[\'"`]/', (string) file_get_contents(ruleProjectPath($path)), $keys);

            foreach (array_unique($keys[1]) as $key) {
                if (! in_array($key, $spec['page_lang_keys'], true) && array_filter($labels, fn (string $prefix): bool => str_starts_with($key, $prefix)) === []) {
                    $violations[] = ['subject' => $path, 'message' => sprintf('speaks "%s", which page_lang_keys in auditLogSpec() does not list', $key)];
                }
            }
        }

        expect(ruleUnexcused('audit-log', 'page', $violations))->toBe([]);
    });
});
