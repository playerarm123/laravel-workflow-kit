<?php

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Foundation\Http\FormRequest;

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of authorization.md — change the two together.
 *
 * Controllers, requests and policies are found under their folders, never listed by hand.
 * An action is a public method a controller declares. What a method calls is read from its
 * code with comments removed; what it takes, and which policy a model names, through
 * reflection. The container is never booted, so routes are reached through their actions.
 *
 * @return array{
 *     kit_files: list<string>,
 *     controllers_path: string,
 *     requests_path: string,
 *     self_service: list<string>,
 *     policies_path: string,
 *     models_namespace: string,
 *     user_model: class-string,
 *     ability_returns: list<string>,
 *     before_method: string,
 *     tests_path: string,
 *     authorize_call: string,
 *     allows_call: string,
 *     user_reads: string,
 *     idiom_paths: list<string>,
 *     idioms: string,
 *     gate_home: string,
 *     gate_call: string,
 *     ability_call: string,
 *     ask_call: string,
 *     can_prop: string,
 * }
 */
function authorizationSpec(): array
{
    return [
        'kit_files' => [
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/MakePolicyCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/stubs/policy.stub',
            'vendor/playerarm123/laravel-workflow-kit/stubs/policy-test.stub',
        ],
        'controllers_path' => 'app/Http/Controllers',
        'requests_path' => 'app/Http/Requests',
        'self_service' => ['App\Http\Controllers\Settings', 'App\Http\Requests\Settings'],
        'policies_path' => 'app/Policies',
        'models_namespace' => 'App\Models',
        'user_model' => 'App\Models\User',
        'ability_returns' => ['bool', 'Illuminate\Auth\Access\Response'],
        'before_method' => 'before',
        'tests_path' => 'tests/Feature/Policies',
        'authorize_call' => '/\bGate::authorize\s*\(/',
        'allows_call' => '/\bGate::allows\s*\(/',
        'user_reads' => '/->\s*user\s*\(|\bAuth::(user|id|check|guest)\s*\(|\bauth\(\)\s*->\s*(user|id|check|guest)\s*\(/',
        'idiom_paths' => ['app', 'routes', 'bootstrap', 'resources/views'],
        'idioms' => '/\$this->authorize(Resource)?\s*\(|\bGate::(define|before|after|policy|guessPolicyNamesUsing|inspect|denies|check|any|none|resource)\s*\(|->\s*(can|cannot|canAny)\s*\(|[\'"]can:|@can(not|any)?\b/',
        'gate_home' => 'app/Http',
        'gate_call' => '/\bGate::/',
        'ability_call' => '/\bGate::(?:authorize|allows)\s*\(\s*([\'"])(\w+)\1\s*(?:,\s*([^,)]+?)\s*)?[,)]/',
        'ask_call' => '/(?:\bGate::(?:authorize|allows)|->\s*allows|\$\w+)\s*\(\s*([\'"])(\w+)\1\s*,\s*\[?\s*(\$this->\w+\(|[\w$]+(?:::class)?)/',
        'can_prop' => 'can',
    ];
}

/**
 * The code of one method with its comments removed.
 */
function authorizationMethodCode(ReflectionMethod $method): string
{
    $lines = file((string) $method->getFileName()) ?: [];
    $source = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));

    return implode('', array_map(
        fn (array|string $token): string => match (true) {
            is_string($token) => $token,
            in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true) => '',
            default => $token[1],
        },
        token_get_all('<?php '.$source),
    ));
}

/**
 * The FormRequest an action takes, or null.
 *
 * @return class-string<FormRequest>|null
 */
function authorizationRequestOf(ReflectionMethod $action): ?string
{
    foreach ($action->getParameters() as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
            return $type->getName();
        }
    }

    return null;
}

/**
 * Whether a FormRequest asks the Gate in its authorize().
 *
 * @param  class-string<FormRequest>  $request
 */
function authorizationRequestAsksGate(string $request): bool
{
    return method_exists($request, 'authorize')
        && preg_match(authorizationSpec()['allows_call'], authorizationMethodCode(new ReflectionMethod($request, 'authorize'))) === 1;
}

/**
 * The full name a short class name in a file stands for: its import, or the file's own namespace.
 */
function authorizationResolveClass(string $file, string $short): string
{
    foreach (ruleDependenciesOf($file) as $name) {
        if ($name === $short || str_ends_with($name, '\\'.$short)) {
            return $name;
        }
    }

    return ruleNamespaceOf($file).'\\'.$short;
}

/**
 * The policy a model names through #[UsePolicy], or null.
 *
 * @return class-string|null
 */
function authorizationPolicyOf(string $model): ?string
{
    if (! class_exists($model)) {
        return null;
    }

    $attributes = (new ReflectionClass($model))->getAttributes(UsePolicy::class);

    return $attributes === [] ? null : $attributes[0]->getArguments()[0] ?? null;
}

/**
 * Every file under the policies folder, with the class its name says it declares.
 *
 * @return list<array{file: string, class: string}>
 */
function authorizationPolicies(): array
{
    return array_map(
        fn (string $file): array => ['file' => $file, 'class' => ruleClassOf($file)],
        ruleSourceFiles(authorizationSpec()['policies_path'], ['php']),
    );
}

/**
 * The abilities a policy answers: its own public instance methods, `before()` aside.
 *
 * @param  class-string  $policy
 * @return list<ReflectionMethod>
 */
function authorizationAbilitiesOf(string $policy): array
{
    return array_values(array_filter(
        (new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $policy
            && ! $method->isStatic()
            && ! str_starts_with($method->getName(), '__')
            && $method->getName() !== authorizationSpec()['before_method'],
    ));
}

/**
 * The type names a parameter or return type names, `null` included.
 *
 * @return list<string>
 */
function authorizationTypeNames(?ReflectionType $type): array
{
    if ($type instanceof ReflectionNamedType) {
        return $type->allowsNull() && $type->getName() !== 'null' ? [$type->getName(), 'null'] : [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType) {
        return array_merge([], ...array_map(authorizationTypeNames(...), $type->getTypes()));
    }

    return [];
}

/**
 * The top-level entries of every `'{prop}' => [ … ]` literal in some code, each as its source
 * text. A `'{prop}' =>` that is not followed by an array literal comes back as null.
 *
 * @return list<list<string>|null>
 */
function authorizationArrayProps(string $code, string $prop): array
{
    $found = [];

    preg_match_all('/([\'"])'.preg_quote($prop, '/').'\1\s*=>\s*/', $code, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[0] as [$match, $offset]) {
        $start = $offset + strlen($match);

        if (($code[$start] ?? '') !== '[') {
            $found[] = null;

            continue;
        }

        $entries = [];
        $current = '';
        $depth = 0;
        $quote = null;

        for ($i = $start + 1, $length = strlen($code); $i < $length; $i++) {
            $char = $code[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\') {
                    $current .= $code[++$i] ?? '';
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif (in_array($char, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [']', ')', '}'], true)) {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $entries[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $entries[] = trim($current);
        $found[] = array_values(array_filter($entries, fn (string $entry): bool => $entry !== ''));
    }

    return $found;
}

/**
 * The model a subject passed to the Gate stands for in a file: `X::class`, a parameter typed
 * `X $x`, or `$this->x()` declared `: X`. Null when the code does not say.
 */
function authorizationSubjectModel(string $file, string $code, string $subject): ?string
{
    $short = match (true) {
        preg_match('/^(\w+)::class$/', $subject, $match) === 1 => $match[1],
        preg_match('/^\$this->(\w+)\($/', $subject, $match) === 1 => preg_match('/function\s+'.$match[1].'\s*\(\s*\)\s*:\s*\??(\w+)/', $code, $type) === 1 ? $type[1] : null,
        preg_match('/^\$(\w+)$/', $subject, $match) === 1 => preg_match('/\b([A-Z]\w*)\s+\$'.$match[1].'\b/', $code, $type) === 1 ? $type[1] : null,
        default => null,
    };

    return $short === null ? null : authorizationResolveClass($file, $short);
}

/**
 * What the HTTP layer asks the Gate: model → abilities, with `*` for an ability asked of a
 * subject the code does not name, which then counts for every policy.
 *
 * It reads `Gate::authorize()`, `Gate::allows()`, `Gate::forUser(…)->allows()` and a closure
 * that wraps one (`$can('viewAny', Agent::class)`), each with the ability as a literal.
 *
 * @return array<string, array<string, true>>
 */
function authorizationAskedAbilities(): array
{
    $asked = [];

    foreach (ruleSourceFiles(authorizationSpec()['gate_home'], ['php']) as $file) {
        $code = ruleCodeWithoutComments($file);
        preg_match_all(authorizationSpec()['ask_call'], $code, $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            $model = authorizationSubjectModel($file, $code, $call[3]) ?? '*';
            $asked[$model][$call[2]] = true;
        }
    }

    return $asked;
}

describe('authorization', function () {
    it('ships the authorization kit at its fixed home', function () {
        $missing = array_values(array_filter(
            authorizationSpec()['kit_files'],
            fn (string $file): bool => ! is_file(ruleProjectPath($file)),
        ));

        expect(ruleUnexcused('authorization', 'kit-files', array_map(
            fn (string $file): array => ['subject' => $file, 'message' => 'kit file is missing — copy it from the kit'],
            $missing,
        )))->toBe([]);
    });

    it('authorizes every controller action through the Gate', function () {
        $violations = [];

        foreach (ruleConcreteClassesIn(authorizationSpec()['controllers_path'], authorizationSpec()['self_service']) as $controller) {
            foreach (ruleControllerActions($controller) as $action) {
                $request = authorizationRequestOf($action);

                if ($request !== null && authorizationRequestAsksGate($request)) {
                    continue;
                }

                if (preg_match(authorizationSpec()['authorize_call'], authorizationMethodCode($action)) === 1) {
                    continue;
                }

                $violations[] = ['subject' => $controller, 'message' => sprintf(
                    '%s() asks no policy — call Gate::authorize() first, or take a FormRequest whose authorize() returns Gate::allows()',
                    $action->getName(),
                )];
            }
        }

        expect(ruleUnexcused('authorization', 'actions', $violations))->toBe([]);
    });

    it('authorizes each action once', function () {
        $violations = [];

        foreach (ruleConcreteClassesIn(authorizationSpec()['controllers_path'], authorizationSpec()['self_service']) as $controller) {
            foreach (ruleControllerActions($controller) as $action) {
                $request = authorizationRequestOf($action);

                if ($request === null || ! authorizationRequestAsksGate($request)) {
                    continue;
                }

                if (preg_match(authorizationSpec()['authorize_call'], authorizationMethodCode($action)) === 1) {
                    $violations[] = ['subject' => $controller, 'message' => sprintf(
                        '%s() calls Gate::authorize() although %s::authorize() already asks the policy, before validation',
                        $action->getName(),
                        $request,
                    )];
                }
            }
        }

        expect(ruleUnexcused('authorization', 'once', $violations))->toBe([]);
    });

    it('authorizes every FormRequest through the Gate', function () {
        $violations = [];

        foreach (ruleConcreteClassesIn(authorizationSpec()['requests_path'], authorizationSpec()['self_service']) as $request) {
            if (! is_subclass_of($request, FormRequest::class)) {
                continue;
            }

            if (! method_exists($request, 'authorize')) {
                $violations[] = ['subject' => $request, 'message' => 'declares no authorize() — Laravel lets every user through'];

                continue;
            }

            $code = authorizationMethodCode(new ReflectionMethod($request, 'authorize'));
            preg_match_all('/\breturn\b\s*([^;]*);/', $code, $returns);

            if ($returns[1] === [] || array_filter($returns[1], fn (string $value): bool => preg_match('/^Gate::allows\s*\(/', $value) !== 1) !== []) {
                $violations[] = ['subject' => $request, 'message' => 'authorize() must return Gate::allows(…) and nothing else'];
            }
        }

        expect(ruleUnexcused('authorization', 'requests', $violations))->toBe([]);
    });

    it('never reads the signed-in user in a controller or a FormRequest', function () {
        $violations = [];

        foreach ([authorizationSpec()['controllers_path'], authorizationSpec()['requests_path']] as $directory) {
            foreach (ruleSourceFiles($directory, ['php']) as $file) {
                foreach (authorizationSpec()['self_service'] as $namespace) {
                    if (ruleIsIn(ruleClassOf($file), $namespace)) {
                        continue 2;
                    }
                }

                $violations = [...$violations, ...ruleCodeMatches(
                    [$file],
                    authorizationSpec()['user_reads'],
                    'reads the user — ask a policy through the Gate, and let the handler read the actor from its port',
                )];
            }
        }

        expect(ruleUnexcused('authorization', 'user-read', $violations))->toBe([]);
    });

    it('asks the Gate in one idiom and only from the HTTP layer', function () {
        $violations = [];

        foreach (authorizationSpec()['idiom_paths'] as $directory) {
            $files = ruleSourceFiles($directory, ['php']);

            $violations = [...$violations, ...ruleCodeMatches(
                $files,
                authorizationSpec()['idioms'],
                'authorizes another way — use Gate::authorize() in a controller or Gate::allows() in a FormRequest and a `can` prop, against a policy',
            )];

            $violations = [...$violations, ...ruleCodeMatches(
                array_values(array_filter($files, fn (string $file): bool => ! str_starts_with($file, authorizationSpec()['gate_home'].'/'))),
                authorizationSpec()['gate_call'],
                sprintf('asks the Gate outside %s — only an entry point of the HTTP layer authorizes', authorizationSpec()['gate_home']),
            )];
        }

        expect(ruleUnexcused('authorization', 'idiom', $violations))->toBe([]);
    });

    it('attaches every policy to its model and gives every ability one shape', function () {
        $spec = authorizationSpec();
        $violations = [];

        foreach (authorizationPolicies() as ['file' => $file, 'class' => $policy]) {
            if (! str_ends_with($policy, 'Policy') || ! class_exists($policy)) {
                $violations[] = ['subject' => $file, 'message' => 'a policy is a class named {Model}Policy'];

                continue;
            }

            $model = $spec['models_namespace'].'\\'.substr(class_basename($policy), 0, -strlen('Policy'));

            if (authorizationPolicyOf($model) !== $policy) {
                $violations[] = ['subject' => $policy, 'message' => sprintf('%s does not carry #[UsePolicy(%s::class)]', $model, class_basename($policy))];
            }

            $methods = authorizationAbilitiesOf($policy);

            if (method_exists($policy, $spec['before_method'])) {
                $methods[] = new ReflectionMethod($policy, $spec['before_method']);
            }

            foreach ($methods as $method) {
                $first = $method->getParameters()[0] ?? null;
                $returns = authorizationTypeNames($method->getReturnType());
                $allowed = $method->getName() === $spec['before_method'] ? ['bool', 'null'] : $spec['ability_returns'];

                if ($first === null || ! in_array($spec['user_model'], authorizationTypeNames($first->getType()), true)) {
                    $violations[] = ['subject' => $policy, 'message' => sprintf('%s() must take the %s as its first parameter', $method->getName(), class_basename($spec['user_model']))];
                }

                if ($returns === [] || array_diff($returns, $allowed) !== [] || ($method->getName() === $spec['before_method'] && $returns !== ['bool', 'null'])) {
                    $violations[] = ['subject' => $policy, 'message' => sprintf(
                        '%s() must return %s',
                        $method->getName(),
                        $method->getName() === $spec['before_method'] ? '?bool' : implode('|', array_map(class_basename(...), $spec['ability_returns'])),
                    )];
                }
            }
        }

        expect(ruleUnexcused('authorization', 'policies', $violations))->toBe([]);
    });

    it('asks only abilities a policy answers', function () {
        $answered = [];

        foreach (authorizationPolicies() as ['class' => $policy]) {
            if (class_exists($policy)) {
                foreach (authorizationAbilitiesOf($policy) as $method) {
                    $answered[$method->getName()] = true;
                }
            }
        }

        $violations = [];

        foreach (ruleSourceFiles(authorizationSpec()['gate_home'], ['php']) as $file) {
            preg_match_all(authorizationSpec()['ability_call'], ruleCodeWithoutComments($file), $calls, PREG_SET_ORDER);

            foreach ($calls as $call) {
                $ability = $call[2];
                $subject = trim($call[3] ?? '');

                if ($subject === '') {
                    $violations[] = ['subject' => $file, 'message' => sprintf('asks `%s` with no model — every ability lives in a policy', $ability)];

                    continue;
                }

                if (preg_match('/^(\w+)::class$/', $subject, $class) !== 1) {
                    if (! isset($answered[$ability])) {
                        $violations[] = ['subject' => $file, 'message' => sprintf('asks `%s`, which no policy answers, so the Gate denies it', $ability)];
                    }

                    continue;
                }

                $model = authorizationResolveClass($file, $class[1]);
                $policy = authorizationPolicyOf($model);

                if ($policy === null) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('asks `%s` of %s, which names no policy through #[UsePolicy]', $ability, $model)];
                } elseif (! method_exists($policy, $ability)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('asks `%s`, which %s does not answer, so the Gate denies it', $ability, $policy)];
                }
            }
        }

        expect(ruleUnexcused('authorization', 'abilities', $violations))->toBe([]);
    });

    it('declares only abilities the HTTP layer asks', function () {
        $asked = authorizationAskedAbilities();
        $models = authorizationSpec()['models_namespace'];
        $violations = [];

        foreach (authorizationPolicies() as ['class' => $policy]) {
            if (! str_ends_with($policy, 'Policy') || ! class_exists($policy)) {
                continue;
            }

            $model = $models.'\\'.substr(class_basename($policy), 0, -strlen('Policy'));

            foreach (authorizationAbilitiesOf($policy) as $method) {
                if (isset($asked[$model][$method->getName()]) || isset($asked['*'][$method->getName()])) {
                    continue;
                }

                $violations[] = ['subject' => $policy, 'message' => sprintf(
                    'declares %s(), which nothing in %s asks — delete it, or make it private when another ability calls it',
                    $method->getName(),
                    authorizationSpec()['gate_home'],
                )];
            }
        }

        expect(ruleUnexcused('authorization', 'unused-ability', $violations))->toBe([]);
    });

    it('names every can prop after the ability it asks', function () {
        $prop = authorizationSpec()['can_prop'];
        $violations = [];

        foreach (ruleSourceFiles(authorizationSpec()['controllers_path'], ['php']) as $file) {
            foreach (authorizationArrayProps(ruleCodeWithoutComments($file), $prop) as $entries) {
                if ($entries === null) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('builds `%s` elsewhere — write it inline, one Gate::allows() per key', $prop)];

                    continue;
                }

                foreach ($entries as $entry) {
                    if (preg_match('/^([\'"])(\w+)\1\s*=>\s*Gate::allows\(\s*([\'"])(\w+)\3\s*,\s*(.+)\)$/s', $entry, $match) !== 1) {
                        $violations[] = ['subject' => $file, 'message' => sprintf('`%s.…` must be one Gate::allows(\'ability\', subject): `%s`', $prop, $entry)];

                        continue;
                    }

                    [, , $key, , $ability, $subject] = $match;
                    $names = array_unique([$ability, (string) preg_replace('/Any$/', '', $ability)]);

                    if (preg_match('/^(\w+)::class$/', trim($subject), $class) === 1) {
                        $names = [...$names, ...array_map(fn (string $name): string => $name.$class[1], $names)];
                    }

                    if (! in_array($key, $names, true)) {
                        $violations[] = ['subject' => $file, 'message' => sprintf('`%s.%s` asks `%s` — name the key %s', $prop, $key, $ability, implode(' or ', array_map(fn (string $name): string => "`{$name}`", $names)))];
                    }
                }
            }
        }

        expect(ruleUnexcused('authorization', 'can-props', $violations))->toBe([]);
    });

    it('tests every policy', function () {
        $policiesPath = authorizationSpec()['policies_path'];
        $violations = [];

        foreach (authorizationPolicies() as ['file' => $file, 'class' => $policy]) {
            $test = authorizationSpec()['tests_path'].'/'.substr($file, strlen($policiesPath) + 1, -strlen('.php')).'Test.php';

            if (! is_file(ruleProjectPath($test))) {
                $violations[] = ['subject' => $policy, 'message' => sprintf('has no %s', $test)];
            }
        }

        expect(ruleUnexcused('authorization', 'tests', $violations))->toBe([]);
    });
});
