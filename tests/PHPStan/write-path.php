<?php

use Playerarm123\LaravelWorkflowKit\Tests\PHPStan\DatabaseWriteOutsideInfraRule;

require_once __DIR__.'/DatabaseWriteOutsideInfraRule.php';

/**
 * Registers DatabaseWriteOutsideInfraRule with the classes rule-overrides.json exempts
 * (`rule: write-path`, `check: outside-infra`).
 *
 * The list is read here, into the config, rather than by the rule at analysis time: PHPStan's
 * result cache is keyed on the config, so adding or removing an override re-runs the analysis
 * instead of replaying a stale result. A malformed entry exempts nothing —
 * tests/Architecture/RuleOverridesTest reports it.
 *
 * The project's phpstan.neon includes this file from the workflow kit package, so the overrides
 * are read from the directory PHPStan runs in, the project root, never counted up from here:
 * vendor/ may hold the package as a symlink.
 */
$path = getcwd().'/rule-overrides.json';
$document = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
$exempt = [];

foreach (is_array($document) && is_array($document['overrides'] ?? null) ? $document['overrides'] : [] as $entry) {
    if (! is_array($entry)
        || ($entry['rule'] ?? null) !== 'write-path'
        || ($entry['check'] ?? null) !== 'outside-infra'
        || ! is_string($entry['subject'] ?? null)
        || mb_strlen(trim((string) ($entry['reason'] ?? ''))) < 20
        || trim((string) ($entry['approved_by'] ?? '')) === ''
        || trim((string) ($entry['date'] ?? '')) === '') {
        continue;
    }

    $exempt[] = ltrim($entry['subject'], '\\');
}

sort($exempt);

return [
    'services' => [
        [
            'class' => DatabaseWriteOutsideInfraRule::class,
            'arguments' => ['exemptClasses' => $exempt],
            'tags' => ['phpstan.rules.rule'],
        ],
    ],
];
