<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of handlers.md — change the two together.
 *
 * Handlers are found under app/Application/{Context}/UseCases, never listed by hand. A
 * handler's shape is read through reflection; its writes, ids and transactions from its
 * code with comments removed.
 *
 * @return array{
 *     kit_files: list<string>,
 *     id_port: class-string,
 *     actor_port: class-string,
 *     actor_method: string,
 *     actor_names: string,
 *     actor_binders: list<string>,
 *     use_cases_glob: string,
 *     method: string,
 *     payload_base: class-string,
 *     command_returns: list<string>,
 *     id_scope: list<string>,
 *     id_minting: string,
 *     id_libraries: list<string>,
 *     write_methods: list<string>,
 *     transaction_call: string,
 *     entry_points: list<string>,
 * }
 */
function handlersSpec(): array
{
    return [
        'kit_files' => [
            'app/Domain/Shared/Ports/IdGenerator.php',
            'app/Application/Auth/UserContext.php',
        ],
        'id_port' => 'App\Domain\Shared\Ports\IdGenerator',
        'actor_port' => 'App\Application\Auth\UserContext',
        'actor_method' => 'id',
        'actor_names' => '/^(actorId|[a-z]+By)$/',
        'actor_binders' => ['app/Http/Middleware'],
        'use_cases_glob' => 'app/Application/*/UseCases',
        'method' => '__invoke',
        'payload_base' => 'Spatie\LaravelData\Data',
        'command_returns' => ['void', 'string', 'int'],
        'id_scope' => ['app/Domain', 'app/Application', 'app/Http', 'app/Console', 'app/Jobs', 'app/Listeners'],
        'id_minting' => '#\bStr::(uuid7?|orderedUuid|ulid)\s*\(#',
        'id_libraries' => ['Ramsey\Uuid', 'Symfony\Component\Uid'],
        'write_methods' => ['save', 'saveMany', 'update', 'updateMany', 'delete', 'restore', 'purge', 'clone', 'handle'],
        'transaction_call' => '#\bDB::(transaction|beginTransaction)\s*\(#',
        'entry_points' => ['app/Http', 'app/Console', 'app/Jobs', 'app/Listeners'],
    ];
}

/**
 * Why a handler's file sits where its shape says it cannot, or its __invoke() takes none of
 * the three shapes in handlers.md — null when it is one of them.
 *
 * @param  array{file: string, class: class-string, name: string, folder: ?string}  $handler
 */
function handlersShapeViolation(array $handler, ReflectionMethod $invoke): ?string
{
    ['file' => $file, 'name' => $name, 'folder' => $folder] = $handler;
    $parameters = $invoke->getParameters();
    $commands = array_values(array_filter($parameters, fn (ReflectionParameter $parameter) => array_filter(
        ruleTypeNames($parameter->getType()),
        fn (string $type) => str_ends_with($type, 'Command'),
    ) !== []));
    $returns = ruleTypeNames($invoke->getReturnType());
    $results = array_values(array_filter($returns, fn (string $type) => str_ends_with($type, 'Result')));

    if ($folder !== null && basename(dirname($file)) !== $name) {
        return sprintf('lives in %s/ — a use case folder is named after its handler', basename(dirname($file)));
    }

    if ($commands === []) {
        if ($results !== []) {
            return sprintf('a Result comes back only from __invoke(%sCommand $command)', $name);
        }

        return $folder === null ? null : sprintf('takes no Command, so it is plain — move it to UseCases/%sHandler.php', $name);
    }

    if ($folder === null) {
        return sprintf('takes a Command — move it to UseCases/%1$s/%1$sHandler.php beside its Command', $name);
    }

    if (count($parameters) !== 1) {
        return 'a Command is the only parameter — move every other value into it';
    }

    if (ruleTypeNames($parameters[0]->getType()) !== [$folder.'\\'.$name.'Command']) {
        return sprintf('takes %s — a handler takes its own %sCommand', implode('|', ruleTypeNames($parameters[0]->getType())), $name);
    }

    if ($results !== []) {
        return $returns === [$folder.'\\'.$name.'Result']
            ? null
            : sprintf('returns %s — a handler returns its own %sResult', implode('|', $returns), $name);
    }

    return count($returns) === 1 && in_array($returns[0], handlersSpec()['command_returns'], true)
        ? null
        : sprintf('returns %s — a Command handler returns %sResult, %s', implode('|', $returns) ?: 'nothing declared', $name, implode(', ', handlersSpec()['command_returns']));
}

describe('handlers', function () {
    it('ships the id port and the actor port at their fixed home', function () {
        $violations = ruleKitFileViolations(handlersSpec()['kit_files']);

        $actor = handlersSpec()['actor_port'];

        if (interface_exists($actor) && ! method_exists($actor, handlersSpec()['actor_method'])) {
            $violations[] = ['subject' => $actor, 'message' => sprintf('declares no %s(): string', handlersSpec()['actor_method'])];
        }

        expect(ruleUnexcused('handlers', 'kit-files', $violations))->toBe([]);
    });

    it('binds the id port to an adapter in a provider', function () {
        $short = substr(strrchr(handlersSpec()['id_port'], '\\') ?: '', 1);
        $providers = implode("\n", array_map(
            fn (string $file) => ruleCodeWithoutComments($file),
            array_values(array_filter(ruleSourceFiles('app', ['php']), fn (string $file) => str_ends_with($file, 'ServiceProvider.php'))),
        ));
        $violations = preg_match('/\b'.preg_quote($short, '/').'::class\s*(=>|,)/', $providers) === 1
            ? []
            : [['subject' => handlersSpec()['id_port'], 'message' => 'is not bound to an adapter in any *ServiceProvider']];

        expect(ruleUnexcused('handlers', 'binding', $violations))->toBe([]);
    });

    it('gives every handler one __invoke() in one of the three shapes', function () {
        $method = handlersSpec()['method'];
        $base = handlersSpec()['payload_base'];
        $violations = [];

        foreach (ruleHandlers(handlersSpec()['use_cases_glob']) as $handler) {
            ['class' => $class, 'name' => $name, 'folder' => $folder] = $handler;

            if (! class_exists($class)) {
                $violations[] = ['subject' => $handler['file'], 'message' => sprintf('declares no %s — name the file after its class', $class)];

                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isFinal()) {
                $violations[] = ['subject' => $class, 'message' => 'a handler is final'];
            }

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

            $reason = handlersShapeViolation($handler, $reflection->getMethod($method));

            if ($reason !== null) {
                $violations[] = ['subject' => $class, 'message' => $reason];
            }

            foreach (['Command', 'Result'] as $kind) {
                $payload = $folder.'\\'.$name.$kind;

                if ($folder === null || ! class_exists($payload)) {
                    continue;
                }

                if (! (new ReflectionClass($payload))->isFinal() || ! is_subclass_of($payload, $base)) {
                    $violations[] = ['subject' => $payload, 'message' => sprintf('a %s is final and extends %s', $kind, $base)];
                }
            }
        }

        expect(ruleUnexcused('handlers', 'shape', $violations))->toBe([]);
    });

    it('reads the actor inside the handler, never from what the caller passes', function () {
        $spec = handlersSpec();
        $violations = [];

        foreach (ruleHandlers(handlersSpec()['use_cases_glob']) as $handler) {
            ['class' => $class, 'name' => $name, 'folder' => $folder] = $handler;

            if (! class_exists($class) || ! method_exists($class, $spec['method'])) {
                continue;
            }

            $command = $folder.'\\'.$name.'Command';
            $carriers = [
                $class => (new ReflectionMethod($class, $spec['method']))->getParameters(),
                $command => $folder !== null && class_exists($command) ? ((new ReflectionClass($command))->getConstructor()?->getParameters() ?? []) : [],
            ];

            foreach ($carriers as $subject => $parameters) {
                foreach ($parameters as $parameter) {
                    if (preg_match($spec['actor_names'], $parameter->getName()) === 1) {
                        $violations[] = ['subject' => $subject, 'message' => sprintf('carries $%s — the handler reads who is acting from %s', $parameter->getName(), $spec['actor_port'])];
                    }
                }
            }
        }

        foreach ($spec['entry_points'] as $directory) {
            foreach (ruleSourceFiles($directory, ['php'], $spec['actor_binders']) as $file) {
                if (in_array($spec['actor_port'], ruleDependenciesOf($file), true)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('uses %s — only the middleware that binds it may; the handler reads the actor itself', $spec['actor_port'])];
                }
            }
        }

        expect(ruleUnexcused('handlers', 'actor', $violations))->toBe([]);
    });

    it('mints every new id through the id port', function () {
        $spec = handlersSpec();
        $violations = [];

        foreach ($spec['id_scope'] as $directory) {
            $files = ruleSourceFiles($directory, ['php']);
            $violations = [...$violations, ...ruleCodeMatches($files, $spec['id_minting'], sprintf('mints an id — inject %s and call next()', $spec['id_port']))];

            foreach ($files as $file) {
                foreach (ruleDependenciesOf($file) as $dependency) {
                    foreach ($spec['id_libraries'] as $library) {
                        if (ruleIsIn($dependency, $library)) {
                            $violations[] = ['subject' => $file, 'message' => sprintf('uses %s — ids come from %s', $dependency, $spec['id_port'])];
                        }
                    }
                }
            }
        }

        expect(ruleUnexcused('handlers', 'ids', $violations))->toBe([]);
    });

    it('wraps a handler that writes more than once in one transaction, and opens none in an entry point', function () {
        $spec = handlersSpec();
        $violations = [];

        foreach (ruleHandlers(handlersSpec()['use_cases_glob']) as $handler) {
            if (! class_exists($handler['class'])) {
                continue;
            }

            $writers = ruleHandlerWriters($handler['class']);

            if ($writers === []) {
                continue;
            }

            $code = ruleCodeWithoutComments($handler['file']);
            $writes = preg_match_all(sprintf(
                '/\$this->(%s)->(%s)\s*\(/',
                implode('|', array_map(fn (string $writer) => preg_quote($writer, '/'), $writers)),
                implode('|', $spec['write_methods']),
            ), $code);

            if ($writes >= 2 && preg_match($spec['transaction_call'], $code) !== 1) {
                $violations[] = ['subject' => $handler['class'], 'message' => sprintf('writes in %d calls with no DB::transaction() around them', $writes)];
            }
        }

        foreach ($spec['entry_points'] as $directory) {
            $violations = [...$violations, ...ruleCodeMatches(ruleSourceFiles($directory, ['php']), $spec['transaction_call'], 'opens a transaction — the handler owns it')];
        }

        expect(ruleUnexcused('handlers', 'transaction', $violations))->toBe([]);
    });
});
