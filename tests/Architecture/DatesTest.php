<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of dates.md — change the two together.
 *
 * The in-file rules (no Intl.DateTimeFormat, no toLocale*String() outside the kit file) are
 * ESLint's, in tests/ESLint/dates.js, proven by DatesEslintTest. This file checks that the kit
 * ships and that its ESLint rules are spread into the project's config.
 *
 * @return array{
 *     kit_files: list<string>,
 *     eslint_config: string,
 *     eslint_rules: string,
 * }
 */
function datesSpec(): array
{
    return [
        'kit_files' => [
            'resources/js/lib/dates.ts',
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/dates.js',
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/Support/rules.js',
        ],
        'eslint_config' => 'eslint.config.js',
        'eslint_rules' => './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/dates.js',
    ];
}

describe('dates', function () {
    it('ships the dates kit at its fixed home', function () {
        $violations = ruleKitFileViolations(datesSpec()['kit_files']);

        expect(ruleUnexcused('dates', 'kit-files', $violations))->toBe([]);
    });

    it('spreads the dates ESLint rules into the ESLint config', function () {
        $config = datesSpec()['eslint_config'];
        $code = is_file(ruleProjectPath($config)) ? ruleCodeWithoutComments($config) : '';
        $import = sprintf('/^import\s+(\w+)\s+from\s+[\'"]%s[\'"]/m', preg_quote(datesSpec()['eslint_rules'], '/'));
        $violations = [];

        if (preg_match($import, $code, $match) !== 1) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must import %s', datesSpec()['eslint_rules'])];
        } elseif (! str_contains($code, '...'.$match[1])) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must spread ...%s into the exported config', $match[1])];
        }

        expect(ruleUnexcused('dates', 'eslint', $violations))->toBe([]);
    });
});
