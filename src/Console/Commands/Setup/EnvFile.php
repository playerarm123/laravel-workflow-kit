<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * Sets one key of a .env file: the line that sets it, or the commented line that names it,
 * becomes `KEY=value`. A key the file never names goes at the end.
 */
final class EnvFile
{
    public static function set(string $code, string $key, string $value, bool $onlyWhenMissing = false): string
    {
        $pattern = sprintf('/^#?[ \t]*%s=.*$/m', preg_quote($key, '/'));

        if (preg_match($pattern, $code) === 1) {
            return $onlyWhenMissing && preg_match(sprintf('/^%s=/m', preg_quote($key, '/')), $code) === 1
                ? $code
                : (string) preg_replace($pattern, $key.'='.$value, $code, 1);
        }

        return rtrim($code)."\n\n".$key.'='.$value."\n";
    }
}
