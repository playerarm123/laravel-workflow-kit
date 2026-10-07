<?php

require_once __DIR__.'/Support/rules.php';

/**
 * Proves tests/ESLint/numbers.js, the ESLint half of numbers.md.
 *
 * The fixture under tests/ESLint/Fixtures/Numbers is linted through the project's own
 * eslint.config.js on stdin, under the path it pretends to live at, so the rules' `files` and
 * `ignores` apply exactly as they do to real code. Only the `[numbers:…]` messages are read,
 * sorted by line and check.
 */
const NUMBERS_ESLINT_FIXTURES = 'tests/ESLint/Fixtures/Numbers';

/**
 * @param  array<string, string>  $env
 * @return list<array{line: int, check: string}>
 */
function numbersLint(string $fixture, string $pretendPath, array $env = []): array
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

    fwrite($pipes[0], (string) file_get_contents(ruleProjectPath(NUMBERS_ESLINT_FIXTURES.'/'.$fixture)));
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
        if (preg_match('/\[numbers:([a-z-]+)\]/', (string) $message['message'], $check) === 1) {
            $found[] = ['line' => (int) $message['line'], 'check' => $check[1]];
        }
    }

    usort($found, fn (array $a, array $b): int => [$a['line'], $a['check']] <=> [$b['line'], $b['check']]);

    return $found;
}

/** Every line of the fixture that formats a number by hand. */
const NUMBERS_ESLINT_FOUND = [
    ['line' => 4, 'check' => 'intl-number'],
    ['line' => 5, 'check' => 'intl-number'],
    ['line' => 6, 'check' => 'intl-number'],
    ['line' => 7, 'check' => 'to-fixed'],
    ['line' => 8, 'check' => 'to-fixed'],
];

describe('numbers ESLint rules', function () {
    it('finds every hand-made number format in a component', function () {
        expect(numbersLint('format.tsx', 'resources/js/components/fixture/rate.tsx'))->toBe(NUMBERS_ESLINT_FOUND);
    });

    it('finds every hand-made number format in a page', function () {
        expect(numbersLint('format.tsx', 'resources/js/pages/fixture/show.tsx'))->toBe(NUMBERS_ESLINT_FOUND);
    });

    it('lets the kit file format', function () {
        expect(numbersLint('format.tsx', 'resources/js/lib/numbers.ts'))->toBe([]);
    });

    it('exempts the file a complete override names, from that check only', function () {
        $overrides = tempnam(sys_get_temp_dir(), 'numbers-overrides');
        file_put_contents($overrides, json_encode(['overrides' => [
            [
                'rule' => 'numbers',
                'check' => 'to-fixed',
                'subject' => 'resources/js/components/fixture/rate.tsx',
                'reason' => 'A fixture proving that an override exempts one file from one check',
                'approved_by' => 'Fixture',
                'date' => '2026-10-05',
            ],
        ]]));

        try {
            expect(numbersLint('format.tsx', 'resources/js/components/fixture/rate.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toBe([
                ['line' => 4, 'check' => 'intl-number'],
                ['line' => 5, 'check' => 'intl-number'],
                ['line' => 6, 'check' => 'intl-number'],
            ])
                ->and(numbersLint('format.tsx', 'resources/js/pages/fixture/show.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toBe(NUMBERS_ESLINT_FOUND);
        } finally {
            unlink($overrides);
        }
    });
});
