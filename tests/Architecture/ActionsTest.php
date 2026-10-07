<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of actions.md — change the two together.
 *
 * An action controller is an invokable controller whose `__invoke()` takes a FormRequest. The
 * in-file rules of the page side (who may call the router, and how) are ESLint's, in
 * tests/ESLint/actions.js, proven by ActionsEslintTest. Routes are read as text: the
 * Architecture suite never boots the container, so the route list is not available.
 *
 * @return array{
 *     kit_files: list<string>,
 *     eslint_config: string,
 *     eslint_rules: string,
 *     controllers_path: string,
 *     requests_namespace: string,
 *     self_service: list<string>,
 *     resource_methods: list<string>,
 *     route_files: string,
 *     self_service_routes: list<string>,
 *     redirects_elsewhere: string,
 * }
 */
function actionsSpec(): array
{
    return [
        'kit_files' => [
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/actions.js',
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/Support/rules.js',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/MakeActionCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Concerns/BuildsRequestFields.php',
            'vendor/playerarm123/laravel-workflow-kit/stubs/action-controller.stub',
            'vendor/playerarm123/laravel-workflow-kit/stubs/action-bulk-controller.stub',
            'vendor/playerarm123/laravel-workflow-kit/stubs/action-request.stub',
            'vendor/playerarm123/laravel-workflow-kit/stubs/action-test.stub',
        ],
        'eslint_config' => 'eslint.config.js',
        'eslint_rules' => './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/actions.js',
        'controllers_path' => 'app/Http/Controllers',
        'requests_namespace' => 'App\Http\Requests',
        'self_service' => ['App\Http\Controllers\Settings'],
        'resource_methods' => ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'],
        'route_files' => 'routes/*.php',
        'self_service_routes' => ['routes/settings.php'],
        'redirects_elsewhere' => '/\b(to_route|redirect)\s*\(/',
    ];
}

/**
 * Every action controller, with what its `__invoke()` takes.
 *
 * @return array<class-string, array{
 *     file: string,
 *     stem: string,
 *     model: class-string<Model>|null,
 *     request: class-string<FormRequest>,
 *     request_variable: string,
 *     handlers: list<class-string>,
 * }>
 */
function actionsControllers(): array
{
    $actions = [];

    foreach (ruleConcreteClassesIn(actionsSpec()['controllers_path'], actionsSpec()['self_service']) as $controller) {
        if (! method_exists($controller, '__invoke')) {
            continue;
        }

        $request = null;
        $variable = '';
        $model = null;
        $handlers = [];

        foreach ((new ReflectionMethod($controller, '__invoke'))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if (is_subclass_of($name, FormRequest::class)) {
                $request ??= $name;
                $variable = $variable === '' ? $parameter->getName() : $variable;
            } elseif (is_subclass_of($name, Model::class)) {
                $model ??= $name;
            } elseif (str_ends_with($name, 'Handler')) {
                $handlers[] = $name;
            }
        }

        if ($request === null) {
            continue;
        }

        $file = ltrim(substr((string) (new ReflectionClass($controller))->getFileName(), strlen(ruleProjectPath())), '/');

        $actions[$controller] = [
            'file' => $file,
            'stem' => (string) preg_replace('/Controller$/', '', class_basename($controller)),
            'model' => $model,
            'request' => $request,
            'request_variable' => $variable,
            'handlers' => $handlers,
        ];
    }

    return $actions;
}

/**
 * Splits an action's stem into its model's name, its verb and whether it is the bulk twin, or
 * null when the stem does not start with the model's name.
 *
 * @param  array{stem: string, model: class-string<Model>|null}  $action
 * @return array{model: string, verb: string, bulk: bool}|null
 */
function actionsNameOf(array $action): ?array
{
    if ($action['model'] !== null) {
        $model = class_basename($action['model']);

        if (! str_starts_with($action['stem'], $model)) {
            return null;
        }

        $verb = substr($action['stem'], strlen($model));

        return $verb === '' || str_starts_with($verb, 'Bulk') ? null : ['model' => $model, 'verb' => $verb, 'bulk' => false];
    }

    if (preg_match('/^([A-Z]\w*?)Bulk([A-Z]\w*)$/', $action['stem'], $parts) !== 1) {
        return null;
    }

    return ['model' => $parts[1], 'verb' => $parts[2], 'bulk' => true];
}

/**
 * Every route written as `Route::{method}('uri', Controller::class)`, with its name when the
 * same statement names it. A controller is resolved through the file's imports.
 *
 * @return list<array{file: string, method: string, uri: string, controller: string, name: string|null}>
 */
function actionsRoutes(): array
{
    $routes = [];

    foreach (ruleGlob(ruleProjectPath(actionsSpec()['route_files'])) as $path) {
        $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
        $imports = [];

        foreach (ruleDependenciesOf($file) as $dependency) {
            $imports[class_basename($dependency)] = $dependency;
        }

        foreach (explode(';', ruleCodeWithoutComments($file)) as $statement) {
            if (preg_match('/\bRoute::(\w+)\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*\\\\?([\w\\\\]+)::class\s*\)/', $statement, $route) !== 1) {
                continue;
            }

            $controller = str_contains($route[3], '\\') ? ltrim($route[3], '\\') : ($imports[$route[3]] ?? $route[3]);

            $routes[] = [
                'file' => $file,
                'method' => strtolower($route[1]),
                'uri' => trim($route[2], '/'),
                'controller' => $controller,
                'name' => preg_match('/->\s*name\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $statement, $name) === 1 ? $name[1] : null,
            ];
        }
    }

    return $routes;
}

describe('actions', function () {
    it('ships the actions kit at its fixed home', function () {
        $violations = [];

        foreach (actionsSpec()['kit_files'] as $file) {
            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => 'is missing — copy it from the kit'];
            }
        }

        expect(ruleUnexcused('actions', 'kit-files', $violations))->toBe([]);
    });

    it('spreads the actions ESLint rules into the ESLint config', function () {
        $config = actionsSpec()['eslint_config'];
        $code = is_file(ruleProjectPath($config)) ? ruleCodeWithoutComments($config) : '';
        $import = sprintf('/^import\s+(\w+)\s+from\s+[\'"]%s[\'"]/m', preg_quote(actionsSpec()['eslint_rules'], '/'));
        $violations = [];

        if (preg_match($import, $code, $match) !== 1) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must import %s', actionsSpec()['eslint_rules'])];
        } elseif (! str_contains($code, '...'.$match[1])) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must spread ...%s into the exported config', $match[1])];
        }

        expect(ruleUnexcused('actions', 'eslint', $violations))->toBe([]);
    });

    it('gives every action an invokable controller named after its model and its verb that answers back()', function () {
        $violations = [];

        foreach (actionsControllers() as $controller => $action) {
            if (actionsNameOf($action) === null) {
                $violations[] = ['subject' => $controller, 'message' => $action['model'] === null
                    ? 'takes no route model, so it is a bulk action — name it {Model}Bulk{Verb}Controller'
                    : sprintf('acts on %s — name it %sVerbController, with a verb', class_basename($action['model']), class_basename($action['model']))];
            }

            $public = array_map(fn (ReflectionMethod $method): string => $method->getName(), ruleControllerActions($controller));

            if ($public !== ['__invoke']) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('declares %s — an action controller has __invoke() only', implode(', ', $public))];
            }

            $return = (new ReflectionMethod($controller, '__invoke'))->getReturnType();

            if (! $return instanceof ReflectionNamedType || ! str_ends_with($return->getName(), 'RedirectResponse')) {
                $violations[] = ['subject' => $controller, 'message' => '__invoke() must return a RedirectResponse'];
            }

            $code = ruleCodeWithoutComments($action['file']);

            if (preg_match('/\bback\s*\(/', $code) !== 1) {
                $violations[] = ['subject' => $controller, 'message' => 'answers without ->back() — the button sits on a page that keeps its state in the URL'];
            }

            if (preg_match(actionsSpec()['redirects_elsewhere'], $code, $call) === 1) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('redirects with %s() — an action answers with ->back()', $call[1])];
            }
        }

        foreach (ruleConcreteClassesIn(actionsSpec()['controllers_path'], actionsSpec()['self_service']) as $controller) {
            foreach (ruleControllerActions($controller) as $method) {
                if ($method->getName() === '__invoke' || in_array($method->getName(), actionsSpec()['resource_methods'], true)) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                        $violations[] = ['subject' => $controller, 'message' => sprintf('%s() is an action on a resource controller — move it into an invokable {Model}{Verb}Controller', $method->getName())];
                    }
                }
            }
        }

        expect(ruleUnexcused('actions', 'shape', $violations))->toBe([]);
    });

    it('takes one request named after the controller and reads it only through toCommand()', function () {
        $violations = [];

        foreach (actionsControllers() as $controller => $action) {
            $name = actionsNameOf($action);

            if ($name !== null) {
                $expected = sprintf('%s\\%s\\%sRequest', actionsSpec()['requests_namespace'], $name['model'], $action['stem']);

                if ($action['request'] !== $expected) {
                    $violations[] = ['subject' => $controller, 'message' => sprintf('takes %s — name its request %s', $action['request'], $expected)];
                }
            }

            $toCommand = method_exists($action['request'], 'toCommand') ? new ReflectionMethod($action['request'], 'toCommand') : null;

            if ($toCommand === null || $toCommand->getDeclaringClass()->getName() !== $action['request']) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('takes %s, which declares no toCommand()', $action['request'])];
            }

            $code = ruleCodeWithoutComments($action['file']);
            $variable = preg_quote($action['request_variable'], '/');

            if (preg_match('/\$'.$variable.'\s*->\s*toCommand\s*\(/', $code) !== 1) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('never calls $%s->toCommand() — hand the handler the Command the request builds', $action['request_variable'])];
            }

            preg_match_all('/\$'.$variable.'\s*->\s*(\w+)/', $code, $reads);

            foreach (array_unique(array_diff($reads[1], ['toCommand'])) as $read) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('reads $%s->%s — read the request only through toCommand(), and the toast\'s values from the route model or the Command', $action['request_variable'], $read)];
            }
        }

        expect(ruleUnexcused('actions', 'request', $violations))->toBe([]);
    });

    it('posts every action to a route whose uri and name spell its verb', function () {
        $violations = [];
        $routes = actionsRoutes();

        foreach (actionsControllers() as $controller => $action) {
            $name = actionsNameOf($action);
            $matching = array_values(array_filter($routes, fn (array $route): bool => $route['controller'] === $controller));

            if (count($matching) !== 1) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('is registered by %d routes — register it once, with Route::post()', count($matching))];

                continue;
            }

            $route = $matching[0];

            if ($route['method'] !== 'post') {
                $violations[] = ['subject' => $controller, 'message' => sprintf('is registered with Route::%s() — an action is a POST', $route['method'])];
            }

            if ($name === null) {
                continue;
            }

            $verb = Str::kebab($name['verb']);
            $uri = $name['bulk']
                ? '#^(?:.*/)?([a-z0-9-]+)/'.preg_quote($verb, '#').'$#'
                : '#^(?:.*/)?([a-z0-9-]+)/\{[^/}]+\}/'.preg_quote($verb, '#').'$#';

            if (preg_match($uri, $route['uri'], $prefix) !== 1) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('is posted to "%s" — use %s', $route['uri'], $name['bulk'] ? "{prefix}/{$verb}" : "{prefix}/{param}/{$verb}")];

                continue;
            }

            $routeName = $name['bulk'] ? "{$prefix[1]}.bulk-{$verb}" : "{$prefix[1]}.{$verb}";

            if ($route['name'] === null || ($route['name'] !== $routeName && ! str_ends_with($route['name'], '.'.$routeName))) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('is named "%s" — name it %s', $route['name'] ?? '(nothing)', $routeName)];
            }
        }

        $reported = array_map(fn (string $controller): string => class_basename($controller).'::class', array_keys(actionsControllers()));

        foreach (ruleGlob(ruleProjectPath(actionsSpec()['route_files'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');

            if (in_array($file, actionsSpec()['self_service_routes'], true)) {
                continue;
            }

            foreach (ruleCodeMatches([$file], '/\bRoute::(patch|put)\s*\(/', 'registers a PATCH or PUT outside Route::resource() — an action is a POST') as $violation) {
                if (! array_filter($reported, fn (string $class): bool => str_contains($violation['message'], $class))) {
                    $violations[] = $violation;
                }
            }
        }

        expect(ruleUnexcused('actions', 'route', $violations))->toBe([]);
    });

    it('lets a row action and its bulk twin share one handler that counts what changed', function () {
        $violations = [];
        $actions = actionsControllers();
        $rows = [];

        foreach ($actions as $controller => $action) {
            $name = actionsNameOf($action);

            if ($name !== null && ! $name['bulk']) {
                $rows[$name['model'].$name['verb']] = $controller;
            }
        }

        foreach ($actions as $controller => $action) {
            $name = actionsNameOf($action);

            if ($name === null || ! $name['bulk']) {
                continue;
            }

            if (count($action['handlers']) !== 1) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('injects %d handlers — a bulk action calls the one its row action calls', count($action['handlers']))];

                continue;
            }

            $handler = $action['handlers'][0];
            $return = (new ReflectionMethod($handler, '__invoke'))->getReturnType();

            if (! $return instanceof ReflectionNamedType || $return->getName() !== 'int') {
                $violations[] = ['subject' => $controller, 'message' => sprintf('calls %s, which must return the int count of rows it changed', $handler)];
            }

            $row = $rows[$name['model'].$name['verb']] ?? null;

            if ($row !== null && $actions[$row]['handlers'] !== [$handler]) {
                $violations[] = ['subject' => $controller, 'message' => sprintf('calls %s but %s calls %s — one action, one handler', $handler, $row, implode(', ', $actions[$row]['handlers']) ?: 'none')];
            }
        }

        expect(ruleUnexcused('actions', 'bulk-handler', $violations))->toBe([]);
    });
});
