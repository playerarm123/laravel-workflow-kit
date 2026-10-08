<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * Small edits to a PHP file that keep its own layout.
 */
final class PhpFile
{
    /**
     * Adds `use {$class};` to the file's imports, in alphabetical order, unless it is there.
     */
    public static function addUse(string $code, string $class): string
    {
        if (preg_match(sprintf('/^use %s;$/m', preg_quote($class, '/')), $code) === 1) {
            return $code;
        }

        if (preg_match_all('/^use [^;]+;$/m', $code, $uses, PREG_OFFSET_CAPTURE) === 0) {
            return (string) preg_replace('/^(<\?php\n\n(?:namespace [^;]+;\n\n)?)/', "$1use {$class};\n\n", $code, 1);
        }

        foreach ($uses[0] as [$line, $offset]) {
            if (strcasecmp(substr($line, 4, -1), $class) > 0) {
                return substr_replace($code, "use {$class};\n", $offset, 0);
            }
        }

        $last = $uses[0][count($uses[0]) - 1];

        return substr_replace($code, "\nuse {$class};", $last[1] + strlen($last[0]), 0);
    }
}
