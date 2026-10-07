<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of exceptions.md — change the two together.
 *
 * Exception classes are found under app/, never listed by hand: a class there that extends
 * Throwable is one. Catch blocks, throws and array keys are read from the tokens, so a
 * comment that names a banned call never trips a check. Kinds are read through reflection.
 *
 * @return array{
 *     kit_files: list<string>,
 *     register_call: array{file: string, pattern: string},
 *     respond_call: array{directory: string, pattern: string},
 *     lang_glob: string,
 *     kit_lang_keys: list<string>,
 *     entry_points: list<string>,
 *     core: list<string>,
 *     queued: list<string>,
 *     queued_exits: string,
 *     http: string,
 *     http_kit: list<string>,
 *     forbidden_catches: list<class-string>,
 *     uncatchable: list<class-string>,
 *     domain: string,
 *     domain_base: class-string,
 *     context_bases: list<class-string>,
 *     refusal_home: string,
 *     refusal_base: string,
 *     personal_keys: list<string>,
 *     middleware: string,
 *     request_context: string,
 *     json_calls: string,
 *     refused_call: string,
 *     refused_code: string,
 *     abort_call: string,
 *     handler_home: string,
 *     handler_methods: list<string>,
 * }
 */
function exceptionsSpec(): array
{
    return [
        'kit_files' => [
            'app/Http/ExceptionResponses.php',
            'app/Http/ApiError.php',
            'app/Http/FlashToast.php',
            'app/Domain/Shared/DomainException.php',
            'app/Domain/Shared/Exceptions/DomainValueException.php',
            'app/Domain/Shared/Exceptions/EntityNotFoundException.php',
            'app/Domain/Shared/Exceptions/RepositoryException.php',
            'app/Application/ApplicationException.php',
            'resources/js/pages/error.tsx',
            'tests/Feature/Http/ExceptionResponsesTest.php',
        ],
        'register_call' => ['file' => 'bootstrap/app.php', 'pattern' => '/\bExceptionResponses::register\(\s*\$\w+\s*\)/'],
        'respond_call' => ['directory' => 'app/Providers', 'pattern' => '/\bInertia::handleExceptionsUsing\(\s*ExceptionResponses::respond\(\.\.\.\)\s*\)/'],
        'lang_glob' => 'lang/*.json',
        'kit_lang_keys' => [
            'common.forbidden',
            'common.not_found',
            'common.page_expired',
            'common.unauthenticated',
            'common.method_not_allowed',
            'common.too_many_requests',
            'common.server_error',
            'common.service_unavailable',
            'common.toast_error_title',
            'errors.title_403',
            'errors.title_404',
            'errors.title_500',
            'errors.title_503',
            'errors.description_403',
            'errors.description_404',
            'errors.description_500',
            'errors.description_503',
            'errors.back_home',
        ],
        'entry_points' => ['app/Http', 'app/Console', 'app/Jobs', 'app/Listeners'],
        'core' => ['app/Domain', 'app/Application'],
        'queued' => ['app/Jobs', 'app/Listeners'],
        'queued_exits' => '/\$this->(fail|release)\s*\(/',
        'http' => 'app/Http',
        'http_kit' => ['app/Http/ExceptionResponses.php', 'app/Http/ApiError.php'],
        'forbidden_catches' => ['Throwable', 'Exception', 'Error', 'ErrorException', 'RuntimeException', 'LogicException'],
        'uncatchable' => [
            'App\Domain\Shared\Exceptions\DomainValueException',
            'App\Domain\Shared\Exceptions\RepositoryException',
            'App\Application\Concerns\ListQueryException',
            'App\Application\Audit\AuditLogException',
        ],
        'domain' => 'app/Domain',
        'domain_base' => 'App\Domain\Shared\DomainException',
        'context_bases' => ['App\Domain\Shared\DomainException', 'App\Application\ApplicationException'],
        'refusal_home' => '#^app/Domain/(?!Shared/)([^/]+)/[^/]+/Exceptions/[^/]+\.php$#',
        'refusal_base' => 'App\Domain\%1$s\Exceptions\%1$sDomainException',
        'personal_keys' => ['name', 'username', 'email', 'phone', 'password', 'note', 'address', 'accountnumber', 'accountname', 'token', 'secret'],
        'middleware' => 'app/Http/Middleware',
        'request_context' => "/\\bContext::add\\(\\s*'actor_id'\\s*,/",
        'json_calls' => '/(response\(\)->json|new\s+JsonResponse|Response::json)\s*\(/',
        'refused_call' => '/\bApiError::refused\s*\(/',
        'refused_code' => "/^\\s*'[a-z][a-z0-9_]*'\\s*,/",
        'abort_call' => '/(?<![\w>:$\\\\])abort(_if|_unless)?\s*\(/',
        'handler_home' => 'app/Exceptions',
        'handler_methods' => ['render', 'report'],
    ];
}

/**
 * Every exception class under app/, read from its file and loaded through the autoloader.
 *
 * @return array<class-string, string> class => file
 */
function exceptionsClasses(): array
{
    static $classes = null;

    if ($classes !== null) {
        return $classes;
    }

    $classes = [];

    foreach (ruleSourceFiles('app', ['php']) as $file) {
        $class = ruleClassOf($file);

        if (class_exists($class) && is_subclass_of($class, Throwable::class)) {
            $classes[$class] = $file;
        }
    }

    return $classes;
}

/**
 * The tokens of a file without whitespace and comments.
 *
 * @return list<array{0: int, 1: string, 2: int}|string>
 */
function exceptionsTokens(string $file): array
{
    return array_values(array_filter(
        token_get_all((string) file_get_contents(ruleProjectPath($file))),
        fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
}

/**
 * The names a file imports, by the alias it uses them under.
 *
 * @return array<string, string> alias => fully qualified name
 */
function exceptionsImports(string $file): array
{
    $imports = [];

    foreach (ruleDependenciesOf($file) as $name) {
        $imports[substr(strrchr('\\'.$name, '\\') ?: '', 1)] = $name;
    }

    $source = ruleCodeWithoutComments($file);

    preg_match_all('/^use\s+([\w\\\\]+)\s+as\s+(\w+)\s*;/m', $source, $aliased, PREG_SET_ORDER);

    foreach ($aliased as [, $name, $alias]) {
        $imports[$alias] = ltrim($name, '\\');
    }

    return $imports;
}

/**
 * The fully qualified name a class reference in a file stands for.
 */
function exceptionsResolve(string $file, string $name, ?string $self = null): string
{
    if (in_array(strtolower($name), ['self', 'static'], true)) {
        return $self ?? $name;
    }

    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }

    $imports = exceptionsImports($file);
    $first = explode('\\', $name)[0];

    if (isset($imports[$first])) {
        return $imports[$first].substr($name, strlen($first));
    }

    $namespace = ruleNamespaceOf($file);

    return $namespace === '' ? $name : $namespace.'\\'.$name;
}

/**
 * Every catch block of a file: the types it names, the variable it binds and its body.
 *
 * @return list<array{types: list<string>, variable: ?string, body: string, throws: list<string>}>
 */
function exceptionsCatches(string $file): array
{
    $tokens = exceptionsTokens($file);
    $count = count($tokens);
    $catches = [];

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
            continue;
        }

        $types = [];
        $variable = null;

        for ($i += 2; $i < $count && $tokens[$i] !== ')'; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $types[] = exceptionsResolve($file, $tokens[$i][1]);
            } elseif (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                $variable = $tokens[$i][1];
            }
        }

        $body = '';
        $throws = [];
        $statement = null;
        $depth = 0;

        for ($i++; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}' && --$depth === 0) {
                break;
            }

            if (is_array($token) && $token[0] === T_THROW) {
                $statement = '';

                continue;
            }

            if ($statement !== null) {
                if ($token === ';') {
                    $throws[] = trim($statement);
                    $statement = null;
                } else {
                    $statement .= $text;
                }
            }

            $body .= $text.' ';
        }

        $catches[] = ['types' => $types, 'variable' => $variable, 'body' => $body, 'throws' => $throws];
    }

    return $catches;
}

/**
 * The text of every call's argument list that matches a pattern, in a file's code.
 *
 * @return list<string>
 */
function exceptionsCallArguments(string $code, string $pattern): array
{
    preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);
    $arguments = [];

    foreach ($matches[0] as [$call, $offset]) {
        $depth = 1;
        $text = '';
        $length = strlen($code);

        for ($i = $offset + strlen($call); $i < $length && $depth > 0; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')' && --$depth === 0) {
                break;
            }

            $text .= $code[$i];
        }

        $arguments[] = $text;
    }

    return $arguments;
}

/**
 * Every string key of every array literal in a file (`'key' => …`), nested ones included.
 *
 * @return list<string>
 */
function exceptionsArrayKeys(string $file): array
{
    $tokens = exceptionsTokens($file);
    $keys = [];

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && is_array($tokens[$index + 1] ?? null) && $tokens[$index + 1][0] === T_DOUBLE_ARROW) {
            $keys[] = substr($token[1], 1, -1);
        }
    }

    return $keys;
}

describe('exceptions', function () {
    it('ships the exception kit and wires both of its halves', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach ($spec['kit_files'] as $path) {
            if (! is_file(ruleProjectPath($path))) {
                $violations[] = ['subject' => $path, 'message' => 'is missing — copy it from the kit'];
            }
        }

        ['file' => $bootstrap, 'pattern' => $register] = $spec['register_call'];

        if (! is_file(ruleProjectPath($bootstrap)) || preg_match($register, ruleCodeWithoutComments($bootstrap)) !== 1) {
            $violations[] = ['subject' => $bootstrap, 'message' => 'never calls ExceptionResponses::register($exceptions) inside withExceptions()'];
        }

        $providers = implode("\n", array_map(ruleCodeWithoutComments(...), ruleSourceFiles($spec['respond_call']['directory'], ['php'])));

        if (preg_match($spec['respond_call']['pattern'], $providers) !== 1) {
            $violations[] = ['subject' => $spec['respond_call']['directory'], 'message' => 'no provider calls Inertia::handleExceptionsUsing(ExceptionResponses::respond(...))'];
        }

        expect(ruleUnexcused('exceptions', 'kit-files', $violations))->toBe([]);
    });

    it('translates every kit key in every locale', function () {
        $violations = [];

        foreach (ruleGlob(ruleProjectPath(exceptionsSpec()['lang_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $messages = ruleReadJson($file);

            foreach (exceptionsSpec()['kit_lang_keys'] as $key) {
                if (! is_string($messages[$key] ?? null)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('has no "%s"', $key)];
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'lang-keys', $violations))->toBe([]);
    });

    it('catches only a leaf exception in an entry point', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach ($spec['entry_points'] as $directory) {
            foreach (ruleSourceFiles($directory, ['php']) as $file) {
                foreach (exceptionsCatches($file) as $catch) {
                    foreach ($catch['types'] as $type) {
                        $reason = match (true) {
                            in_array($type, $spec['forbidden_catches'], true) => 'is too broad — catch the exception the user can act on by name',
                            ! class_exists($type) && ! interface_exists($type) => 'does not resolve to a class — import it',
                            interface_exists($type) || (new ReflectionClass($type))->isAbstract() => 'is a base, not a reason — catch the concrete exception by name',
                            default => null,
                        };

                        if ($reason === null) {
                            foreach (exceptionsClasses() as $class => $classFile) {
                                if (is_subclass_of($class, $type)) {
                                    $reason = sprintf('has subclasses (%s) — catch the one the user can act on by name', $class);

                                    break;
                                }
                            }
                        }

                        if ($reason !== null) {
                            $violations[] = ['subject' => $file, 'message' => sprintf('catch (%s) %s', $type, $reason)];
                        }
                    }
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'broad-catch', $violations))->toBe([]);
    });

    it('never catches an invalid value or a failure in an entry point', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach ($spec['entry_points'] as $directory) {
            foreach (ruleSourceFiles($directory, ['php']) as $file) {
                foreach (exceptionsCatches($file) as $catch) {
                    foreach ($catch['types'] as $type) {
                        foreach ($spec['uncatchable'] as $kind) {
                            if (is_a($type, $kind, true)) {
                                $violations[] = ['subject' => $file, 'message' => sprintf('catch (%s) — a %s is a bug or a failure; let it reach the handler as a reported 500', $type, $kind)];
                            }
                        }
                    }
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'catch-kind', $violations))->toBe([]);
    });

    it('keeps an exception message and its context out of every response', function () {
        $spec = exceptionsSpec();
        $files = ruleSourceFiles($spec['http'], ['php']);
        $files = array_values(array_filter($files, fn (string $file) => ! in_array($file, $spec['http_kit'], true)));

        $violations = [
            ...ruleCodeMatches($files, '/->getMessage\s*\(/', 'reads an exception message — it is written for the log; pick a translated message'),
            ...ruleCodeMatches($files, '/->context\b/', 'reads an exception context — it is written for the log; read a typed getter'),
        ];

        expect(ruleUnexcused('exceptions', 'message-leak', $violations))->toBe([]);
    });

    it('builds the context once in a named constructor', function () {
        $bases = exceptionsSpec()['context_bases'];
        $violations = [];

        foreach (exceptionsClasses() as $class => $file) {
            $reflection = new ReflectionClass($class);

            if (in_array($class, $bases, true) || ! $reflection->hasMethod('context')) {
                continue;
            }

            if ($reflection->getMethod('context')->getDeclaringClass()->getName() === $class) {
                $violations[] = ['subject' => $class, 'message' => 'overrides context() — pass `context:` from its named constructor, so reporting it can never throw'];
            }
        }

        expect(ruleUnexcused('exceptions', 'context-override', $violations))->toBe([]);
    });

    it('keeps personal data out of every exception context', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach (exceptionsClasses() as $class => $file) {
            foreach (exceptionsArrayKeys($file) as $key) {
                if (in_array(strtolower(str_replace(['_', '-'], '', $key)), $spec['personal_keys'], true)) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('logs "%s" — a context carries ids, enums, amounts and codes, never personal data', $key)];
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'context-keys', $violations))->toBe([]);
    });

    it('passes the caught exception on as previous when it throws a new one', function () {
        $violations = [];

        foreach (ruleSourceFiles('app', ['php']) as $file) {
            foreach (exceptionsCatches($file) as $catch) {
                foreach ($catch['throws'] as $throw) {
                    $variable = $catch['variable'];

                    if ($variable !== null && $throw === $variable) {
                        continue;
                    }

                    if ($variable === null || preg_match('/'.preg_quote($variable, '/').'\b/', $throw) !== 1) {
                        $violations[] = ['subject' => $file, 'message' => sprintf('throws `%s` from catch (%s) without the caught exception — bind it and pass it as previous', $throw, implode('|', $catch['types']))];
                    }
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'previous', $violations))->toBe([]);
    });

    it('adds the actor to the context of every log once per request', function () {
        $spec = exceptionsSpec();
        $code = implode("\n", array_map(ruleCodeWithoutComments(...), ruleSourceFiles($spec['middleware'], ['php'])));
        $violations = preg_match($spec['request_context'], $code) === 1
            ? []
            : [['subject' => $spec['middleware'], 'message' => "no middleware calls Context::add('actor_id', …) — add it where UserContext is bound"]];

        expect(ruleUnexcused('exceptions', 'request-context', $violations))->toBe([]);
    });

    it('builds every JSON error through ApiError', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach (ruleSourceFiles($spec['http'], ['php'], []) as $file) {
            if (in_array($file, $spec['http_kit'], true)) {
                continue;
            }

            foreach (exceptionsCallArguments(ruleCodeWithoutComments($file), $spec['json_calls']) as $arguments) {
                if (preg_match('/(?<![\w.])[45]\d\d(?![\w.])/', $arguments) === 1) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('builds a JSON error by hand (%s) — answer with ApiError::refused()', trim($arguments))];
                }
            }

            foreach (exceptionsCatches($file) as $catch) {
                if (preg_match($spec['json_calls'], str_replace(' ', '', $catch['body'])) === 1
                    || preg_match($spec['json_calls'], $catch['body']) === 1) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('answers catch (%s) with a hand-built JSON response — use ApiError::refused()', implode('|', $catch['types']))];
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'api-error', $violations))->toBe([]);
    });

    it('gives every API refusal a literal snake_case code', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach (ruleSourceFiles('app', ['php']) as $file) {
            foreach (exceptionsCallArguments(ruleCodeWithoutComments($file), $spec['refused_call']) as $arguments) {
                if (preg_match($spec['refused_code'], $arguments) !== 1) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('ApiError::refused(%s) — the code is a snake_case string literal, the contract a client branches on', trim($arguments))];
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'api-code', $violations))->toBe([]);
    });

    it('never aborts — a refusal is a policy or a domain exception', function () {
        $violations = ruleCodeMatches(ruleSourceFiles('app', ['php']), exceptionsSpec()['abort_call'], 'aborts — deny in a policy or throw a domain exception');

        expect(ruleUnexcused('exceptions', 'abort', $violations))->toBe([]);
    });

    it('never swallows an exception inside the core or a queued job', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach ([...$spec['core'], ...$spec['queued']] as $directory) {
            $queued = in_array($directory, $spec['queued'], true);

            foreach (ruleSourceFiles($directory, ['php']) as $file) {
                foreach (exceptionsCatches($file) as $catch) {
                    if ($catch['throws'] !== [] || ($queued && preg_match($spec['queued_exits'], $catch['body']) === 1)) {
                        continue;
                    }

                    $violations[] = ['subject' => $file, 'message' => sprintf(
                        'catch (%s) ends without throwing — clean up and rethrow, or throw a domain exception%s',
                        implode('|', $catch['types']),
                        $queued ? ', or $this->fail()/release() so the queue sees it' : '',
                    )];
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'swallow', $violations))->toBe([]);
    });

    it('throws only its own exceptions from the domain', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach (ruleSourceFiles($spec['domain'], ['php']) as $file) {
            $tokens = exceptionsTokens($file);
            $self = ruleClassOf($file);

            foreach ($tokens as $index => $token) {
                if (! is_array($token) || $token[0] !== T_THROW) {
                    continue;
                }

                $next = $tokens[$index + 1] ?? null;
                $name = null;

                if (is_array($next) && $next[0] === T_NEW) {
                    $name = $tokens[$index + 2] ?? null;
                } elseif (($tokens[$index + 2] ?? null) !== null && is_array($tokens[$index + 2]) && $tokens[$index + 2][0] === T_DOUBLE_COLON) {
                    $name = $next;
                }

                if (! is_array($name) || ! in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)) {
                    continue;
                }

                $class = exceptionsResolve($file, $name[1], $self);

                if (! is_subclass_of($class, $spec['domain_base'])) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('throws %s — the domain throws a subclass of %s (make:domain-exception)', $class, $spec['domain_base'])];
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'domain-throws', $violations))->toBe([]);
    });

    it('roots every refusal of an aggregate in its context\'s base', function () {
        $spec = exceptionsSpec();
        $violations = [];

        foreach (ruleSourceFiles($spec['domain'], ['php']) as $file) {
            if (preg_match($spec['refusal_home'], $file, $match) !== 1) {
                continue;
            }

            $class = ruleClassOf($file);

            if (! class_exists($class) || get_parent_class($class) !== $spec['domain_base']) {
                continue;
            }

            $violations[] = ['subject' => $class, 'message' => sprintf(
                'extends %s directly — a refusal extends %s, which make:domain-exception --kind=refusal writes the first time',
                $spec['domain_base'],
                sprintf($spec['refusal_base'], $match[1]),
            )];
        }

        expect(ruleUnexcused('exceptions', 'refusal-base', $violations))->toBe([]);
    });

    it('answers in ExceptionResponses only', function () {
        $spec = exceptionsSpec();
        $violations = is_dir(ruleProjectPath($spec['handler_home']))
            ? [['subject' => $spec['handler_home'], 'message' => 'exists — make:exception writes here; scaffold with make:domain-exception instead']]
            : [];

        foreach (exceptionsClasses() as $class => $file) {
            $reflection = new ReflectionClass($class);

            foreach ($spec['handler_methods'] as $method) {
                if ($reflection->hasMethod($method) && $reflection->getMethod($method)->getDeclaringClass()->getName() === $class) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('declares %s() — an exception carries a reason; ExceptionResponses and the controller answer it', $method)];
                }
            }
        }

        expect(ruleUnexcused('exceptions', 'home', $violations))->toBe([]);
    });
});
