<?php

require_once __DIR__.'/Support/rules.php';

/**
 * rule-overrides.json is the only way to step outside a rule, so a broken entry must
 * fail loudly instead of silently exempting nothing — or everything.
 */
function ruleOverrideEntry(array $changes = []): array
{
    return [
        'rule' => 'stack',
        'check' => 'forbidden',
        'subject' => 'moment',
        'reason' => 'A vendored chart library pins moment as a peer dependency',
        'approved_by' => 'Tech lead',
        'date' => '2026-10-02',
        ...$changes,
    ];
}

describe('rule overrides', function () {
    it('keeps every entry in rule-overrides.json complete', function () {
        expect(is_file(ruleProjectPath('rule-overrides.json')))->toBeTrue()
            ->and(ruleOverrideProblems(ruleReadJson('rule-overrides.json')))->toBe([]);
    });

    it('accepts a complete entry', function () {
        expect(ruleOverrideProblems(['overrides' => [ruleOverrideEntry()]]))->toBe([]);
    });

    it('rejects an entry with a missing field', function (string $field) {
        $entry = ruleOverrideEntry();
        unset($entry[$field]);

        expect(ruleOverrideProblems(['overrides' => [$entry]]))
            ->toBe([sprintf('overrides[0] is missing "%s"', $field)]);
    })->with(RULE_OVERRIDE_FIELDS);

    it('rejects a reason too short to explain anything', function () {
        expect(ruleOverrideProblems(['overrides' => [ruleOverrideEntry(['reason' => 'needed'])]]))
            ->toHaveCount(1)
            ->and(ruleOverrideProblems(['overrides' => [ruleOverrideEntry(['reason' => 'needed'])]])[0])
            ->toContain('"reason" must be at least 20 characters');
    });

    it('rejects a date that is not Y-m-d', function (string $date) {
        expect(ruleOverrideProblems(['overrides' => [ruleOverrideEntry(['date' => $date])]]))
            ->toBe([sprintf('overrides[0] "date" must be Y-m-d, got "%s"', $date)]);
    })->with(['02/10/2026', '2026-13-01', '2026-02-30']);

    it('rejects a document without an overrides list', function () {
        expect(ruleOverrideProblems([]))->toBe(['rule-overrides.json must hold an "overrides" list']);
    });

    it('exempts only the exact rule, check and subject of a complete entry', function () {
        $document = ['overrides' => [ruleOverrideEntry()]];

        expect(ruleIsOverridden($document, 'stack', 'forbidden', 'moment'))->toBeTrue()
            ->and(ruleIsOverridden($document, 'stack', 'forbidden', 'dayjs'))->toBeFalse()
            ->and(ruleIsOverridden($document, 'stack', 'major', 'moment'))->toBeFalse()
            ->and(ruleIsOverridden($document, 'deployment', 'forbidden', 'moment'))->toBeFalse();
    });

    it('exempts nothing through an incomplete entry', function () {
        $document = ['overrides' => [ruleOverrideEntry(['reason' => ''])]];

        expect(ruleIsOverridden($document, 'stack', 'forbidden', 'moment'))->toBeFalse();
    });

    it('matches a locked line by major, or by minor on a 0.x line', function (string $installed, string $locked, bool $matches) {
        expect(ruleVersionMatches($installed, $locked))->toBe($matches);
    })->with([
        ['v13.25.0', '13', true],
        ['12.9.0', '13', false],
        ['0.1.21', '0.1', true],
        ['v0.2.0', '0.1', false],
        ['130.0.0', '13', false],
    ]);
});
