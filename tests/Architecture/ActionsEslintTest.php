<?php

require_once __DIR__.'/Support/rules.php';

/**
 * Proves tests/ESLint/actions.js, the page half of actions.md.
 *
 * Each fixture under tests/ESLint/Fixtures/Actions is linted through the project's own
 * eslint.config.js on stdin, under the path it pretends to live at, so the rules' `files` globs
 * apply exactly as they do to real code. Only the `[actions:…]` messages are read, sorted by
 * line and check.
 */
const ACTIONS_ESLINT_FIXTURES = __DIR__.'/../ESLint/Fixtures/Actions';

/**
 * @param  array<string, string>  $env
 * @return list<array{line: int, check: string}>
 */
function actionsLint(string $fixture, string $pretendPath, array $env = []): array
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

    fwrite($pipes[0], (string) file_get_contents(ACTIONS_ESLINT_FIXTURES.'/'.$fixture));
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
        if (preg_match('/\[actions:([a-z-]+)\]/', (string) $message['message'], $check) === 1) {
            $found[] = ['line' => (int) $message['line'], 'check' => $check[1]];
        }
    }

    usort($found, fn (array $a, array $b): int => [$a['line'], $a['check']] <=> [$b['line'], $b['check']]);

    return $found;
}

describe('actions ESLint rules', function () {
    it('lets a component post an action through a Wayfinder url only', function () {
        expect(actionsLint('calls.tsx', 'resources/js/components/fixture/actions.tsx'))->toBe([
            ['line' => 6, 'check' => 'post-only'],
            ['line' => 7, 'check' => 'post-only'],
            ['line' => 8, 'check' => 'wayfinder-url'],
            ['line' => 9, 'check' => 'wayfinder-url'],
        ]);
    });

    it('keeps every action call out of a page', function () {
        expect(actionsLint('calls.tsx', 'resources/js/pages/fixture/show.tsx'))->toBe([
            ['line' => 5, 'check' => 'action-home'],
            ['line' => 6, 'check' => 'post-only'],
            ['line' => 7, 'check' => 'post-only'],
            ['line' => 8, 'check' => 'action-home'],
            ['line' => 8, 'check' => 'wayfinder-url'],
            ['line' => 9, 'check' => 'wayfinder-url'],
        ]);
    });

    it('exempts the file a complete override names, from that check only', function () {
        $overrides = tempnam(sys_get_temp_dir(), 'actions-overrides');
        file_put_contents($overrides, json_encode(['overrides' => [
            [
                'rule' => 'actions',
                'check' => 'post-only',
                'subject' => 'resources/js/components/fixture/actions.tsx',
                'reason' => 'A fixture proving that an override exempts one file from one check',
                'approved_by' => 'Fixture',
                'date' => '2026-10-04',
            ],
        ]]));

        try {
            expect(actionsLint('calls.tsx', 'resources/js/components/fixture/actions.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toBe([
                ['line' => 8, 'check' => 'wayfinder-url'],
                ['line' => 9, 'check' => 'wayfinder-url'],
            ])
                ->and(actionsLint('calls.tsx', 'resources/js/pages/fixture/show.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toHaveCount(6);
        } finally {
            unlink($overrides);
        }
    });
});
