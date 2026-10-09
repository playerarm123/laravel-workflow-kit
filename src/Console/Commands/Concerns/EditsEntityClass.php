<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

/**
 * Edits an entity class `make:entity` wrote, by the section headers its stub lays out (Building,
 * Behavior, Getter, Asserting). `make:entity-method` adds a method through it and
 * `make:entity-state` a getter.
 */
trait EditsEntityClass
{
    protected const string BEHAVIOURS = 'Behavior Section';

    protected const string GETTERS = 'Getter Section';

    protected const string ASSERTIONS = 'Asserting Section';

    /**
     * The file with each missing import added to its `use` lines, sorted as pint sorts them.
     *
     * @param  array<string, string>  $imports
     */
    protected function withImports(string $code, array $imports): string
    {
        preg_match_all('/^use [^;]+;$/m', $code, $matches, PREG_OFFSET_CAPTURE);
        $existing = array_column($matches[0], 0);
        $lines = array_values(array_unique([...$existing, ...array_map(fn (string $class): string => "use {$class};", array_values($imports))]));

        if ($lines === $existing) {
            return $code;
        }

        usort($lines, fn (string $a, string $b): int => strcasecmp(rtrim($a, ';'), rtrim($b, ';')));

        if ($existing === []) {
            return (string) preg_replace('/^(namespace [^;]+;\n)/m', "$1\n".implode("\n", $lines)."\n", $code, 1);
        }

        $first = $matches[0][0][1];
        $last = $matches[0][count($matches[0]) - 1];
        $end = $last[1] + strlen($last[0]);

        return substr($code, 0, $first).implode("\n", $lines).substr($code, $end);
    }

    /**
     * The file with a method added at the end of a section: before the next section's header, or
     * before the class closes when the section is the last one. A method with no docblock sits
     * right under the section's header, as pint wants it. A file with no such section gets the
     * method before the class closes, and a warning.
     */
    protected function withMethod(string $code, string $section, string $member, string $entity): string
    {
        $lines = explode("\n", rtrim($code, "\n"));
        $close = (int) array_key_last(array_filter($lines, fn (string $line): bool => $line === '}'));
        $header = null;

        foreach ($lines as $index => $line) {
            if (trim($line) === '* '.$section) {
                $header = $index;

                break;
            }
        }

        if ($header === null) {
            $this->components->warn("{$entity}Entity has no {$section} — it goes before the class closes.");
        }

        $at = $close;

        foreach ($header === null ? [] : array_slice($lines, $header + 1, $close - $header - 1, preserve_keys: true) as $index => $line) {
            if (preg_match('/^\s+\* \w+ Section$/', $line) === 1) {
                $at = $index;

                while ($at > 0 && trim($lines[$at]) !== '/**') {
                    $at--;
                }

                break;
            }
        }

        $before = array_slice($lines, 0, $at);

        while ($before !== [] && trim((string) end($before)) === '') {
            array_pop($before);
        }

        $after = array_slice($lines, $at);
        $block = explode("\n", rtrim($member, "\n"));
        $afterHeader = count($before) >= 2 && trim((string) end($before)) === '*/' && str_contains($before[count($before) - 2], '* ====');
        $gap = $afterHeader && trim($block[0]) !== '/**' ? [] : [''];

        return implode("\n", [...$before, ...$gap, ...$block, ...($after[0] === '}' ? [] : ['']), ...$after])."\n";
    }
}
