<?php

require_once __DIR__.'/Support/rules.php';

/**
 * Proves tests/ESLint/list-pages.js, the in-file half of list-pages.md.
 *
 * Each fixture under tests/ESLint/Fixtures/ListPages is linted through the project's own
 * eslint.config.js on stdin, under the path it pretends to live at, so the rules' `files` globs
 * apply exactly as they do to real code. Only the `[list-pages:…]` messages are read.
 */
const LIST_PAGES_ESLINT_FIXTURES = 'tests/ESLint/Fixtures/ListPages';

/**
 * @param  array<string, string>  $env
 * @return list<array{line: int, check: string}>
 */
function listPagesLint(string $fixture, string $pretendPath, array $env = []): array
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

    fwrite($pipes[0], (string) file_get_contents(ruleProjectPath(LIST_PAGES_ESLINT_FIXTURES.'/'.$fixture)));
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
        if (preg_match('/\[list-pages:([a-z-]+)\]/', (string) $message['message'], $check) === 1) {
            $found[] = ['line' => (int) $message['line'], 'check' => $check[1]];
        }
    }

    return $found;
}

describe('list pages ESLint rules', function () {
    it('lets a page call router.get only inside its visit, and never reach for the table markup', function () {
        expect(listPagesLint('page.tsx', 'resources/js/pages/fixture/index.tsx'))->toBe([
            ['line' => 2, 'check' => 'one-table'],
            ['line' => 3, 'check' => 'one-table'],
            ['line' => 12, 'check' => 'visit-only'],
        ]);
    });

    it('keeps its checks on a create or edit page, beside the form pages checks', function () {
        $expected = [
            ['line' => 2, 'check' => 'one-table'],
            ['line' => 3, 'check' => 'one-table'],
            ['line' => 12, 'check' => 'visit-only'],
        ];

        expect(listPagesLint('page.tsx', 'resources/js/pages/fixture/create.tsx'))->toBe($expected)
            ->and(listPagesLint('page.tsx', 'resources/js/pages/fixture/edit.tsx'))->toBe($expected);
    });

    it('keeps the router, state, effects and timers out of a domain toolbar', function () {
        expect(listPagesLint('table-toolbar.tsx', 'resources/js/components/fixture/table-toolbar.tsx'))->toBe([
            ['line' => 1, 'check' => 'toolbar-state'],
            ['line' => 5, 'check' => 'toolbar-state'],
            ['line' => 7, 'check' => 'toolbar-state'],
            ['line' => 8, 'check' => 'toolbar-state'],
        ]);
    });

    it('lets a component render a small fixed table but not build its own', function () {
        expect(listPagesLint('component.tsx', 'resources/js/components/fixture/lines.tsx'))->toBe([
            ['line' => 1, 'check' => 'one-table'],
        ]);
    });

    it('exempts the file a complete override names, from that check only', function () {
        $overrides = tempnam(sys_get_temp_dir(), 'list-pages-overrides');
        file_put_contents($overrides, json_encode(['overrides' => [
            [
                'rule' => 'list-pages',
                'check' => 'visit-only',
                'subject' => 'resources/js/pages/fixture/index.tsx',
                'reason' => 'A fixture proving that an override exempts one file from one check',
                'approved_by' => 'Fixture',
                'date' => '2026-10-03',
            ],
        ]]));

        try {
            expect(listPagesLint('page.tsx', 'resources/js/pages/fixture/index.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toBe([
                ['line' => 2, 'check' => 'one-table'],
                ['line' => 3, 'check' => 'one-table'],
            ]);
        } finally {
            unlink($overrides);
        }
    });
});
