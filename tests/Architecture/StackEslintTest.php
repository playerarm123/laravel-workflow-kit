<?php

require_once __DIR__.'/Support/rules.php';

/**
 * Proves tests/ESLint/stack.js, the ESLint half of stack.md.
 *
 * The fixture under tests/ESLint/Fixtures/Stack is linted through the project's own
 * eslint.config.js on stdin, under the path it pretends to live at, so the rules' `files` and
 * `ignores` apply exactly as they do to real code. Only the `[stack:…]` messages are read,
 * sorted by line and check.
 */
const STACK_ESLINT_FIXTURES = __DIR__.'/../ESLint/Fixtures/Stack';

/**
 * @param  array<string, string>  $env
 * @return list<array{line: int, check: string}>
 */
function stackLint(string $fixture, string $pretendPath, array $env = []): array
{
    $process = proc_open(
        [ruleProjectPath('node_modules/.bin/eslint'), '--stdin', '--stdin-filename', $pretendPath, '--format', 'json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        ruleProjectPath(),
        [...getenv(), ...$env],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start ESLint');
    }

    fwrite($pipes[0], (string) file_get_contents(STACK_ESLINT_FIXTURES.'/'.$fixture));
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $report = json_decode($output, true);

    if (! is_array($report) || ! is_array($report[0]['messages'] ?? null)) {
        throw new RuntimeException('ESLint gave no report: '.$errors.$output);
    }

    $found = [];

    foreach ($report[0]['messages'] as $message) {
        if (preg_match('/\[stack:([a-z-]+)\]/', (string) $message['message'], $check) === 1) {
            $found[] = ['line' => (int) $message['line'], 'check' => $check[1]];
        }
    }

    usort($found, fn (array $a, array $b): int => [$a['line'], $a['check']] <=> [$b['line'], $b['check']]);

    return $found;
}

/** Every import of the cn package in the fixture: import, type import, re-export and import(). */
const STACK_ESLINT_FOUND = [
    ['line' => 1, 'check' => 'cn-import'],
    ['line' => 3, 'check' => 'cn-import'],
    ['line' => 4, 'check' => 'cn-import'],
    ['line' => 8, 'check' => 'cn-import'],
];

describe('stack ESLint rules', function () {
    it('finds every import of the cn package in a component', function () {
        expect(stackLint('cn.tsx', 'resources/js/components/fixture/classes.tsx'))->toBe(STACK_ESLINT_FOUND);
    });

    it('finds every import of the cn package in a page', function () {
        expect(stackLint('cn.tsx', 'resources/js/pages/fixture/show.tsx'))->toBe(STACK_ESLINT_FOUND);
    });

    it('exempts the file a complete override names', function () {
        $overrides = tempnam(sys_get_temp_dir(), 'stack-overrides');
        file_put_contents($overrides, json_encode(['overrides' => [
            [
                'rule' => 'stack',
                'check' => 'cn-import',
                'subject' => 'resources/js/components/fixture/classes.tsx',
                'reason' => 'A fixture proving that an override exempts one file from one check',
                'approved_by' => 'Fixture',
                'date' => '2026-10-14',
            ],
        ]]));

        try {
            expect(stackLint('cn.tsx', 'resources/js/components/fixture/classes.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toBe([])
                ->and(stackLint('cn.tsx', 'resources/js/pages/fixture/show.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toBe(STACK_ESLINT_FOUND);
        } finally {
            unlink($overrides);
        }
    });
});
