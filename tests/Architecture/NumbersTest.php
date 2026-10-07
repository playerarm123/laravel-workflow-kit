<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of numbers.md — change the two together.
 *
 * The in-file rules (no Intl.NumberFormat, no toFixed() outside the kit file) are ESLint's, in
 * tests/ESLint/numbers.js, proven by NumbersEslintTest. This file checks that the kit ships, that
 * its ESLint rules are spread into the project's config, that the page receives the currency, and
 * that no use case's payload carries a float.
 *
 * @return array{
 *     kit_files: list<string>,
 *     eslint_config: string,
 *     eslint_rules: string,
 *     share_file: string,
 *     shared_prop: string,
 *     types_path: string,
 *     translation_hook: string,
 *     payload_paths: list<string>,
 *     payload_files: list<string>,
 * }
 */
function numbersSpec(): array
{
    return [
        'kit_files' => [
            'app/Domain/Shared/ValueObjects/Money.php',
            'app/Domain/Shared/ValueObjects/Percent.php',
            'app/Domain/Shared/ValueObjects/Concerns/ParsesScaledDecimal.php',
            'app/Domain/Shared/Exceptions/InvalidMoneyException.php',
            'app/Domain/Shared/Exceptions/InvalidPercentException.php',
            'tests/Unit/Domain/Shared/ValueObjects/MoneyTest.php',
            'tests/Unit/Domain/Shared/ValueObjects/PercentTest.php',
            'resources/js/lib/numbers.ts',
            'tests/ESLint/numbers.js',
            'tests/ESLint/Support/rules.js',
        ],
        'eslint_config' => 'eslint.config.js',
        'eslint_rules' => './tests/ESLint/numbers.js',
        'share_file' => 'app/Http/Middleware/HandleInertiaRequests.php',
        'shared_prop' => 'currency',
        'types_path' => 'resources/js/types',
        'translation_hook' => 'resources/js/hooks/use-translation.ts',
        // Every class under these paths is a payload: a Command, a Result, a Data, an item
        // nested in one, a list Row, a Criteria, or a domain service's Data and Result.
        'payload_paths' => [
            '#^app/Application/[^/]+/UseCases/#',
            '#^app/Domain/[^/]+/Services/#',
        ],
        'payload_files' => [
            '#^app/Http/Requests/.+FormValues\.php$#',
        ],
    ];
}

/**
 * Every PHP file the no-float check reads.
 *
 * @return list<string>
 */
function numbersPayloadFiles(): array
{
    $patterns = [...numbersSpec()['payload_paths'], ...numbersSpec()['payload_files']];

    return array_values(array_filter(
        ruleSourceFiles('app', ['php']),
        function (string $file) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $file) === 1) {
                    return true;
                }
            }

            return false;
        },
    ));
}

describe('numbers', function () {
    it('ships the numbers kit at its fixed home', function () {
        $violations = [];

        foreach (numbersSpec()['kit_files'] as $file) {
            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => 'is missing — copy it from the kit'];
            }
        }

        expect(ruleUnexcused('numbers', 'kit-files', $violations))->toBe([]);
    });

    it('spreads the numbers ESLint rules into the ESLint config', function () {
        $config = numbersSpec()['eslint_config'];
        $code = is_file(ruleProjectPath($config)) ? ruleCodeWithoutComments($config) : '';
        $import = sprintf('/^import\s+(\w+)\s+from\s+[\'"]%s[\'"]/m', preg_quote(numbersSpec()['eslint_rules'], '/'));
        $violations = [];

        if (preg_match($import, $code, $match) !== 1) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must import %s', numbersSpec()['eslint_rules'])];
        } elseif (! str_contains($code, '...'.$match[1])) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must spread ...%s into the exported config', $match[1])];
        }

        expect(ruleUnexcused('numbers', 'eslint', $violations))->toBe([]);
    });

    it('shares the currency every amount is formatted in', function () {
        $prop = numbersSpec()['shared_prop'];
        $share = numbersSpec()['share_file'];
        $hook = numbersSpec()['translation_hook'];
        $violations = [];

        $code = is_file(ruleProjectPath($share)) ? ruleCodeWithoutComments($share) : '';

        if (! str_contains($code, sprintf("'%s' =>", $prop))) {
            $violations[] = ['subject' => $share, 'message' => sprintf("share() must return '%s'", $prop)];
        }

        $declared = null;

        foreach (ruleSourceFiles(numbersSpec()['types_path'], ['ts']) as $file) {
            $declared ??= ruleTsTypeKeys($file, 'SharedProps');
        }

        if (! in_array($prop, $declared ?? [], true)) {
            $violations[] = ['subject' => 'SharedProps', 'message' => sprintf('must declare %s', $prop)];
        }

        $hookCode = is_file(ruleProjectPath($hook)) ? ruleCodeWithoutComments($hook) : '';

        if (preg_match(sprintf('/return\s*\{[^}]*\b%s\b[^}]*\}/', $prop), $hookCode) !== 1) {
            $violations[] = ['subject' => $hook, 'message' => sprintf('useTranslation() must return %s', $prop)];
        }

        expect(ruleUnexcused('numbers', 'shared-props', $violations))->toBe([]);
    });

    it('carries no float in any payload', function () {
        $violations = [];

        foreach (numbersPayloadFiles() as $file) {
            $class = ruleClassOf($file);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (in_array('float', ruleTypeNames($property->getType()), true)) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('$%s is a float — carry the decimal string or the value object', $property->getName())];
                }
            }

            if ($reflection->hasMethod('toArray')) {
                $docblock = (string) $reflection->getMethod('toArray')->getDocComment();

                if (preg_match('/@return\s+array\{.*\bfloat\b/s', $docblock) === 1) {
                    $violations[] = ['subject' => $class, 'message' => 'toArray() sends a float — send the decimal string'];
                }
            }
        }

        expect(ruleUnexcused('numbers', 'no-float', $violations))->toBe([]);
    });
});
