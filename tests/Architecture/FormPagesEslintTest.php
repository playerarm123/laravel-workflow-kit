<?php

require_once __DIR__.'/Support/rules.php';

/**
 * Proves tests/ESLint/form-pages.js, the in-file half of form-pages.md.
 *
 * Each fixture under tests/ESLint/Fixtures/FormPages is linted through the project's own
 * eslint.config.js on stdin, under the path it pretends to live at, so the rules' `files` globs
 * apply exactly as they do to real code. Only the `[form-pages:…]` messages are read.
 */
const FORM_PAGES_ESLINT_FIXTURES = 'tests/ESLint/Fixtures/FormPages';

/**
 * @param  array<string, string>  $env
 * @return list<array{line: int, check: string}>
 */
function formPagesLint(string $fixture, string $pretendPath, array $env = []): array
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

    fwrite($pipes[0], (string) file_get_contents(ruleProjectPath(FORM_PAGES_ESLINT_FIXTURES.'/'.$fixture)));
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
        if (preg_match('/\[form-pages:([a-z-]+)\]/', (string) $message['message'], $check) === 1) {
            $found[] = ['line' => (int) $message['line'], 'check' => $check[1]];
        }
    }

    return $found;
}

describe('form pages ESLint rules', function () {
    it('keeps the fields and every way to submit out of a create or edit page', function () {
        $expected = [
            ['line' => 1, 'check' => 'page-shell'],
            ['line' => 1, 'check' => 'page-shell'],
            ['line' => 11, 'check' => 'page-shell'],
            ['line' => 12, 'check' => 'page-shell'],
        ];

        expect(formPagesLint('page.tsx', 'resources/js/pages/fixture/create.tsx'))->toBe($expected)
            ->and(formPagesLint('page.tsx', 'resources/js/pages/fixture/nested/edit.tsx'))->toBe($expected);
    });

    it('leaves every other page alone', function () {
        expect(formPagesLint('page.tsx', 'resources/js/pages/fixture/show.tsx'))->toBe([]);
    });

    it('lets a form component submit through <Form> only', function () {
        expect(formPagesLint('form.tsx', 'resources/js/components/fixture/form.tsx'))->toBe([
            ['line' => 1, 'check' => 'form-only'],
            ['line' => 7, 'check' => 'form-only'],
        ]);
    });

    it('exempts the file a complete override names, from that check only', function () {
        $overrides = tempnam(sys_get_temp_dir(), 'form-pages-overrides');
        file_put_contents($overrides, json_encode(['overrides' => [
            [
                'rule' => 'form-pages',
                'check' => 'form-only',
                'subject' => 'resources/js/components/fixture/form.tsx',
                'reason' => 'A fixture proving that an override exempts one file from one check',
                'approved_by' => 'Fixture',
                'date' => '2026-10-04',
            ],
        ]]));

        try {
            expect(formPagesLint('form.tsx', 'resources/js/components/fixture/form.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toBe([])
                ->and(formPagesLint('page.tsx', 'resources/js/pages/fixture/create.tsx', ['RULE_OVERRIDES_PATH' => $overrides]))->toHaveCount(4);
        } finally {
            unlink($overrides);
        }
    });
});
