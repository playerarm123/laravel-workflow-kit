<?php

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Http\FormRequest;

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of form-pages.md — change the two together.
 *
 * The in-file rules (what a page shell and a form component may import and render) are
 * ESLint's, in tests/ESLint/form-pages.js, proven by FormPagesEslintTest. This file checks the
 * server half and what spans files: the kit, store/update and the FormRequest's toCommand(),
 * flash toasts, file handling, and that every set of form values matches its request's rules
 * and its TypeScript twin key for key.
 *
 * @return array{
 *     kit_files: list<string>,
 *     shadcn_components: list<string>,
 *     app_entry: string,
 *     app_wiring: list<string>,
 *     eslint_config: string,
 *     eslint_rules: string,
 *     kit_lang_keys: list<string>,
 *     lang_glob: string,
 *     http_path: string,
 *     controllers_path: string,
 *     requests_path: string,
 *     write_methods: list<string>,
 *     starter_kit: list<string>,
 *     list_command_prefix: string,
 *     form_values_glob: string,
 *     types_path: string,
 *     pages_path: string,
 *     form_pages: list<string>,
 *     form_component_import: string,
 *     defaults_prop: string,
 *     file_calls: string,
 * }
 */
function formPagesSpec(): array
{
    return [
        'kit_files' => [
            'app/Http/FlashToast.php',
            'resources/js/hooks/use-flash-toast.ts',
            'resources/js/components/flash-toast.tsx',
            'resources/js/components/input-error.tsx',
            'resources/js/types/ui.ts',
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/form-pages.js',
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/Support/rules.js',
        ],
        'shadcn_components' => ['button', 'card', 'checkbox', 'input', 'label', 'select', 'spinner'],
        'app_entry' => 'resources/js/app.tsx',
        'app_wiring' => ['<FlashToast', '<Toaster'],
        'eslint_config' => 'eslint.config.js',
        'eslint_rules' => './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/form-pages.js',
        'kit_lang_keys' => [
            'common.back',
            'common.cancel',
            'common.save',
            'common.toast_error_title',
            'common.toast_success_title',
        ],
        'lang_glob' => 'lang/*.json',
        'http_path' => 'app/Http',
        'controllers_path' => 'app/Http/Controllers',
        'requests_path' => 'app/Http/Requests',
        'write_methods' => ['store', 'update'],
        'starter_kit' => ['App\Http\Controllers\Settings'],
        'list_command_prefix' => 'List',
        'form_values_glob' => 'app/Http/Requests/*/*FormValues.php',
        'types_path' => 'resources/js/types',
        'pages_path' => 'resources/js/pages',
        'form_pages' => ['create.tsx', 'edit.tsx'],
        'form_component_import' => '#^import\s+.*\s+from\s+[\'"]@/components/[^/\'"]+/form[\'"]#m',
        'defaults_prop' => '/(?<!const\s)(?<!let\s)\bdefaults\s*:\s*\w+FormValues\b/',
        'file_calls' => '/->\s*(store|storeAs|storePublicly|storePubliclyAs|move)\s*\(|\bStorage::/',
    ];
}

/**
 * @return list<class-string> every concrete class declared under a directory of app/Http
 */
function formPagesClassesIn(string $directory): array
{
    $classes = [];

    foreach (ruleSourceFiles($directory, ['php']) as $file) {
        $class = ruleClassOf($file);

        if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * The code of one method with its comments removed.
 */
function formPagesMethodCode(ReflectionMethod $method): string
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
 * The top-level input keys a FormRequest validates: the keys of the `rules()` it declares, inherits
 * or takes from a trait, with `items.*.name` counted as `items`.
 *
 * @param  class-string<FormRequest>  $class
 * @return list<string>|null null when rules() returns no array literal
 */
function formPagesRuleKeys(string $class): ?array
{
    $source = (string) (new ReflectionMethod($class, 'rules'))->getFileName();

    if (! str_starts_with($source, ruleProjectPath().'/')) {
        return null;
    }

    $keys = ruleArrayKeysOf(substr($source, strlen(ruleProjectPath()) + 1), 'rules');

    return $keys === null ? null : array_values(array_unique(array_map(
        fn (string $key): string => explode('.', $key)[0],
        $keys,
    )));
}

/**
 * The docblock right above `export type {Name}` in a TypeScript file.
 */
function formPagesTypeDocblock(string $file, string $type): string
{
    return preg_match('#/\*\*((?:(?!\*/).)*)\*/\s*export\s+type\s+'.preg_quote($type, '#').'\b#s', (string) file_get_contents(ruleProjectPath($file)), $doc) === 1 ? $doc[1] : '';
}

describe('form pages', function () {
    it('ships the form page kit at its fixed home', function () {
        $violations = ruleKitFileViolations(formPagesSpec()['kit_files']);

        foreach (formPagesSpec()['shadcn_components'] as $component) {
            $file = sprintf('resources/js/components/ui/%s.tsx', $component);

            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => sprintf('is missing — npx shadcn add %s', $component)];
            }
        }

        $entry = formPagesSpec()['app_entry'];
        $code = is_file(ruleProjectPath($entry)) ? ruleCodeWithoutComments($entry) : '';

        foreach (formPagesSpec()['app_wiring'] as $marker) {
            if (! str_contains($code, $marker)) {
                $violations[] = ['subject' => $entry, 'message' => sprintf('must render %s> once, so every flash toast shows', $marker)];
            }
        }

        expect(ruleUnexcused('form-pages', 'kit-files', $violations))->toBe([]);
    });

    it('spreads the form page ESLint rules into the ESLint config', function () {
        $config = formPagesSpec()['eslint_config'];
        $code = is_file(ruleProjectPath($config)) ? ruleCodeWithoutComments($config) : '';
        $import = sprintf('/^import\s+(\w+)\s+from\s+[\'"]%s[\'"]/m', preg_quote(formPagesSpec()['eslint_rules'], '/'));
        $violations = [];

        if (preg_match($import, $code, $match) !== 1) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must import %s', formPagesSpec()['eslint_rules'])];
        } elseif (! str_contains($code, '...'.$match[1])) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must spread ...%s into the exported config', $match[1])];
        }

        expect(ruleUnexcused('form-pages', 'eslint', $violations))->toBe([]);
    });

    it('translates every kit key in every locale', function () {
        $violations = [];

        foreach (ruleGlob(ruleProjectPath(formPagesSpec()['lang_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $messages = ruleReadJson($file);

            foreach (formPagesSpec()['kit_lang_keys'] as $key) {
                if (! is_string($messages[$key] ?? null)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('has no "%s"', $key)];
                }
            }
        }

        expect(ruleUnexcused('form-pages', 'lang-keys', $violations))->toBe([]);
    });

    it('authorizes store and update in a FormRequest that hands over the Command', function () {
        $violations = [];

        foreach (formPagesClassesIn(formPagesSpec()['controllers_path']) as $controller) {
            if (array_filter(formPagesSpec()['starter_kit'], fn (string $namespace): bool => str_starts_with($controller, $namespace.'\\')) !== []) {
                continue;
            }

            foreach (formPagesSpec()['write_methods'] as $name) {
                if (! method_exists($controller, $name)) {
                    continue;
                }

                $method = new ReflectionMethod($controller, $name);

                if ($method->getDeclaringClass()->getName() !== $controller) {
                    continue;
                }

                $request = null;

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                        $request = $type->getName();

                        break;
                    }
                }

                if ($request === null) {
                    $violations[] = ['subject' => $controller, 'message' => sprintf('%s() takes no FormRequest — validate and authorize the input in one', $name)];

                    continue;
                }

                if (! method_exists($request, 'toCommand')) {
                    $violations[] = ['subject' => $controller, 'message' => sprintf('%s() takes %s, which declares no toCommand()', $name, $request)];
                }

                if (preg_match('/\bGate::authorize\s*\(|\$this->authorize\s*\(/', formPagesMethodCode($method)) === 1) {
                    $violations[] = ['subject' => $controller, 'message' => sprintf('%s() authorizes again — %s::authorize() already does, before validation', $name, $request)];
                }
            }
        }

        expect(ruleUnexcused('form-pages', 'store-update', $violations))->toBe([]);
    });

    it('builds no Command in a controller except a list command', function () {
        $violations = [];
        $prefix = formPagesSpec()['list_command_prefix'];

        foreach (ruleSourceFiles(formPagesSpec()['controllers_path'], ['php']) as $file) {
            foreach (explode("\n", ruleCodeWithoutComments($file)) as $line) {
                preg_match_all('/\bnew\s+(\w+Command)\s*\(|\b(\w+Command)::from\s*\(/', $line, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $command = $match[1] !== '' ? $match[1] : $match[2];

                    if (! str_starts_with($command, $prefix)) {
                        $violations[] = ['subject' => ruleClassOf($file), 'message' => sprintf('builds %s — move it into the FormRequest\'s toCommand(): `%s`', $command, trim($line))];
                    }
                }
            }
        }

        expect(ruleUnexcused('form-pages', 'commands', $violations))->toBe([]);
    });

    it('types every toCommand() and builds the Command with new', function () {
        $violations = [];

        foreach (formPagesClassesIn(formPagesSpec()['requests_path']) as $request) {
            if (! is_subclass_of($request, FormRequest::class) || ! method_exists($request, 'toCommand')) {
                continue;
            }

            $method = new ReflectionMethod($request, 'toCommand');

            if ($method->getDeclaringClass()->getName() !== $request) {
                continue;
            }

            $type = $method->getReturnType();

            if (! $type instanceof ReflectionNamedType || ! str_ends_with($type->getName(), 'Command') || ! class_exists($type->getName())) {
                $violations[] = ['subject' => $request, 'message' => 'toCommand() must declare the {Name}Command it returns'];
            }

            if (preg_match('/\b\w+Command::from\s*\(/', formPagesMethodCode($method)) === 1) {
                $violations[] = ['subject' => $request, 'message' => 'toCommand() builds with ::from() — use new {Name}Command(...) with named arguments, so PHPStan checks them'];
            }
        }

        expect(ruleUnexcused('form-pages', 'to-command', $violations))->toBe([]);
    });

    it('flashes only through FlashToast', function () {
        $violations = [];

        foreach (ruleSourceFiles(formPagesSpec()['http_path'], ['php']) as $file) {
            $code = ruleCodeWithoutComments($file);

            foreach (explode(';', $code) as $statement) {
                $redirects = preg_match('/\b(back|to_route|redirect)\s*\(|->\s*back\s*\(/', $statement) === 1;

                if (($redirects && preg_match('/->\s*with\s*\(/', $statement) === 1)
                    || preg_match('/session\s*\(\s*\)\s*->\s*flash\s*\(|\bSession::flash\s*\(|->\s*session\s*\(\s*\)\s*->\s*flash\s*\(/', $statement) === 1) {
                    $violations[] = ['subject' => ruleClassOf($file), 'message' => sprintf('flashes outside FlashToast — use Inertia::flash(FlashToast::KEY, FlashToast::success|error(...)): `%s`', trim((string) preg_replace('/\s+/', ' ', $statement)))];
                }
            }
        }

        expect(ruleUnexcused('form-pages', 'flash', $violations))->toBe([]);
    });

    it('keeps files out of the HTTP layer', function () {
        $violations = ruleCodeMatches(
            ruleSourceFiles(formPagesSpec()['http_path'], ['php']),
            formPagesSpec()['file_calls'],
            'stores, moves or reads a file — the handler does, through a storage port',
        );

        expect(ruleUnexcused('form-pages', 'uploads', $violations))->toBe([]);
    });

    it('matches every set of form values to its requests and its TypeScript twin', function () {
        $violations = [];
        $types = ruleSourceFiles(formPagesSpec()['types_path'], ['ts']);

        foreach (ruleGlob(ruleProjectPath(formPagesSpec()['form_values_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $class = ruleClassOf($file);
            $name = basename($file, '.php');
            $subject = substr($name, 0, -strlen('FormValues'));

            if (! class_exists($class)) {
                $violations[] = ['subject' => $class, 'message' => 'does not autoload'];

                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isFinal() || ! $reflection->implementsInterface(Arrayable::class)) {
                $violations[] = ['subject' => $class, 'message' => 'must be final and implement Arrayable'];
            }

            foreach (['empty', 'of'] as $factory) {
                if (! $reflection->hasMethod($factory) || ! $reflection->getMethod($factory)->isStatic()) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('needs static %s()', $factory)];
                }
            }

            $php = ruleArrayKeysOf($file, 'toArray');

            if ($php === null) {
                $violations[] = ['subject' => $class, 'message' => 'toArray() must return an array literal with every key spelled out'];

                continue;
            }

            foreach (array_filter($php, fn (string $key): bool => str_starts_with($key, '...')) as $spread) {
                $violations[] = ['subject' => $class, 'message' => sprintf('toArray() spreads `%s` — spell every key out', $spread)];
            }

            $store = ruleNamespaceOf($file).'\\Store'.$subject.'Request';
            $update = ruleNamespaceOf($file).'\\Update'.$subject.'Request';

            if (! class_exists($store)) {
                $violations[] = ['subject' => $class, 'message' => sprintf('needs %s beside it, whose rules() it mirrors', $store)];
            } else {
                $rules = formPagesRuleKeys($store);

                if ($rules === null) {
                    $violations[] = ['subject' => $store, 'message' => 'rules() must return an array literal'];
                } else {
                    foreach (array_diff($php, $rules) as $key) {
                        $violations[] = ['subject' => $class, 'message' => sprintf('sends "%s" but %s::rules() never validates it', $key, $store)];
                    }

                    foreach (array_diff($rules, $php) as $key) {
                        $violations[] = ['subject' => $class, 'message' => sprintf('%s::rules() validates "%s" but toArray() never sends it', $store, $key)];
                    }
                }
            }

            if (class_exists($update)) {
                $rules = formPagesRuleKeys($update);

                if ($rules === null) {
                    $violations[] = ['subject' => $update, 'message' => 'rules() must return an array literal'];
                } else {
                    foreach (array_diff($rules, $php) as $key) {
                        $violations[] = ['subject' => $class, 'message' => sprintf('%s::rules() validates "%s" but toArray() never sends it', $update, $key)];
                    }
                }
            }

            $found = null;

            foreach ($types as $candidate) {
                $keys = ruleTsTypeKeys($candidate, $name);

                if ($keys !== null) {
                    $found = [$candidate, $keys];

                    break;
                }
            }

            if ($found === null) {
                $violations[] = ['subject' => $class, 'message' => sprintf('has no `export type %s = { … }` under %s', $name, formPagesSpec()['types_path'])];

                continue;
            }

            [$typeFile, $ts] = $found;

            if (preg_match('/@see\s+\\\\?'.preg_quote($class, '/').'\b/', formPagesTypeDocblock($typeFile, $name)) !== 1) {
                $violations[] = ['subject' => $class, 'message' => sprintf('%s in %s needs `@see %s`', $name, $typeFile, $class)];
            }

            foreach (array_diff($php, $ts) as $key) {
                if (! str_starts_with($key, '...')) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('toArray() sends "%s" but the TypeScript %s does not declare it', $key, $name)];
                }
            }

            foreach (array_diff($ts, $php) as $key) {
                $violations[] = ['subject' => $class, 'message' => sprintf('the TypeScript %s declares "%s" but toArray() never sends it', $name, $key)];
            }
        }

        expect(ruleUnexcused('form-pages', 'form-values', $violations))->toBe([]);
    });

    it('renders every create and edit page from its form component and server defaults', function () {
        $violations = [];

        foreach (ruleSourceFiles(formPagesSpec()['pages_path'], ['tsx']) as $file) {
            if (! in_array(basename($file), formPagesSpec()['form_pages'], true)) {
                continue;
            }

            $code = ruleCodeWithoutComments($file);

            if (preg_match(formPagesSpec()['form_component_import'], $code) !== 1) {
                $violations[] = ['subject' => $file, 'message' => 'must render the form component from @/components/{aggregate}/form'];
            }

            if (preg_match(formPagesSpec()['defaults_prop'], $code) !== 1) {
                $violations[] = ['subject' => $file, 'message' => 'must take `defaults: {X}FormValues` from its props — the server sends the starting values'];
            }
        }

        expect(ruleUnexcused('form-pages', 'pages', $violations))->toBe([]);
    });
});
