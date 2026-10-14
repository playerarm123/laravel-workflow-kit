<?php

require_once __DIR__.'/Support/rules.php';
require_once __DIR__.'/Support/stack.php';

/** The ESLint half of stack.md, which eslint.config.js imports from vendor/ and spreads in. */
const STACK_ESLINT_RULES = './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/stack.js';

/**
 * @param  array<string, string>  $locked
 * @param  array<string, string>  $declared
 * @return list<array{subject: string, message: string}>
 */
function stackMissing(array $locked, array $declared): array
{
    $violations = [];

    foreach (array_keys($locked) as $package) {
        if (! array_key_exists($package, $declared)) {
            $violations[] = ['subject' => $package, 'message' => 'is required but not declared'];
        }
    }

    return $violations;
}

/**
 * @param  array<string, string>  $locked
 * @param  array<string, string>  $declared
 * @param  array<string, string>  $installed
 * @return list<array{subject: string, message: string}>
 */
function stackOffLine(array $locked, array $declared, array $installed, string $lockfile): array
{
    $violations = [];

    foreach ($locked as $package => $line) {
        if (! array_key_exists($package, $declared)) {
            continue;
        }

        if (! array_key_exists($package, $installed)) {
            $violations[] = [
                'subject' => $package,
                'message' => sprintf('is declared but missing from %s — run the install so the lockfile matches', $lockfile),
            ];

            continue;
        }

        if (! ruleVersionMatches($installed[$package], $line)) {
            $violations[] = [
                'subject' => $package,
                'message' => sprintf('found %s, expected %s.x', $installed[$package], $line),
            ];
        }
    }

    return $violations;
}

describe('stack', function () {
    it('declares every required package', function () {
        $violations = [
            ...stackMissing(stackSpec()['composer']['required'], ruleComposerDeclared()),
            ...stackMissing(stackSpec()['npm']['required'], ruleNpmDeclared()),
        ];

        expect(ruleUnexcused('stack', 'required', $violations))->toBe([]);
    });

    it('installs every required package on its locked line', function () {
        $violations = [
            ...stackOffLine(stackSpec()['composer']['required'], ruleComposerDeclared(), ruleComposerInstalled(), 'composer.lock'),
            ...stackOffLine(stackSpec()['npm']['required'], ruleNpmDeclared(), ruleNpmInstalled(), ruleNpmLockfile()),
        ];

        $php = ruleComposerDeclared()['php'] ?? '';

        if (preg_match('/(\d+)\.(\d+)/', $php, $matches) !== 1 || stackSpec()['php'] !== $matches[1].'.'.$matches[2]) {
            $violations[] = [
                'subject' => 'php',
                'message' => sprintf('constraint is "%s", expected it to start at %s', $php, stackSpec()['php']),
            ];
        }

        expect(ruleUnexcused('stack', 'major', $violations))->toBe([]);
    });

    it('keeps every installed optional package on its locked line', function () {
        $violations = [
            ...stackOffLine(stackSpec()['composer']['optional'], ruleComposerDeclared(), ruleComposerInstalled(), 'composer.lock'),
            ...stackOffLine(stackSpec()['npm']['optional'], ruleNpmDeclared(), ruleNpmInstalled(), ruleNpmLockfile()),
        ];

        expect(ruleUnexcused('stack', 'optional-major', $violations))->toBe([]);
    });

    it('declares no package the stack already answered for', function () {
        $violations = [
            ...ruleForbiddenFound(ruleComposerDeclared(), stackSpec()['composer']['forbidden'], 'the stack picks another library for this need'),
            ...ruleForbiddenFound(ruleNpmDeclared(), stackSpec()['npm']['forbidden'], 'the stack picks another library for this need'),
        ];

        expect(ruleUnexcused('stack', 'forbidden', $violations))->toBe([]);
    });

    /*
     * The shadcn CLI depends on the cn package itself, so node_modules/cn is there and the import
     * builds. tests/ESLint/stack.js reports it while editing; this covers components/ui too,
     * which eslint.config.js ignores.
     */
    it('imports cn from @/lib/utils, never from the cn package', function () {
        $violations = ruleCodeMatches(
            ruleSourceFiles('resources/js', ['ts', 'tsx', 'js', 'jsx']),
            '/\b(?:from|import)\s*\(?\s*([\'"])cn\1/',
            'imports the cn package — import cn from @/lib/utils (`php artisan kit:ui-add` rewrites it)',
        );

        expect(ruleUnexcused('stack', 'cn-import', $violations))->toBe([]);
    });

    it('spreads the stack ESLint rules into the ESLint config', function () {
        $config = 'eslint.config.js';
        $code = is_file(ruleProjectPath($config)) ? ruleCodeWithoutComments($config) : '';
        $import = sprintf('/^import\s+(\w+)\s+from\s+[\'"]%s[\'"]/m', preg_quote(STACK_ESLINT_RULES, '/'));
        $violations = [];

        if (preg_match($import, $code, $match) !== 1) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must import %s', STACK_ESLINT_RULES)];
        } elseif (! str_contains($code, '...'.$match[1])) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must spread ...%s into the exported config', $match[1])];
        }

        expect(ruleUnexcused('stack', 'eslint', $violations))->toBe([]);
    });

    it('talks to redis through phpredis', function () {
        $client = ruleEnvValue('.env.example', 'REDIS_CLIENT');
        $violations = $client === stackSpec()['redis_client'] ? [] : [[
            'subject' => '.env.example REDIS_CLIENT',
            'message' => sprintf('is "%s", expected "%s"', $client ?? '(unset)', stackSpec()['redis_client']),
        ]];

        expect(ruleUnexcused('stack', 'redis-client', $violations))->toBe([]);
    });

    it('declares a supported database and tests on that same engine', function () {
        $declared = ruleEnvValue('.env.example', 'DB_CONNECTION');
        $tested = rulePhpunitEnv('DB_CONNECTION');
        $violations = [];

        if (! in_array($declared, stackSpec()['databases'], true)) {
            $violations[] = [
                'subject' => '.env.example DB_CONNECTION',
                'message' => sprintf('is "%s", expected one of %s', $declared ?? '(unset)', implode(', ', stackSpec()['databases'])),
            ];
        }

        if ($tested !== $declared) {
            $violations[] = [
                'subject' => 'phpunit.xml DB_CONNECTION',
                'message' => sprintf('is "%s", expected the declared engine "%s"', $tested ?? '(unset)', $declared ?? '(unset)'),
            ];
        }

        expect(ruleUnexcused('stack', 'database', $violations))->toBe([]);
    });

    it('generates shadcn components with the stack icon library', function () {
        $library = ruleReadJson('components.json')['iconLibrary'] ?? null;
        $violations = $library === stackSpec()['icon_library'] ? [] : [[
            'subject' => 'components.json iconLibrary',
            'message' => sprintf('is "%s", expected "%s"', is_string($library) ? $library : '(unset)', stackSpec()['icon_library']),
        ]];

        expect(ruleUnexcused('stack', 'icons', $violations))->toBe([]);
    });
});
