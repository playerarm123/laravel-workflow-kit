<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * Brings a `tests/Pest.php` the project already has (Laravel's Pest preset ships one) to what the
 * kit's tests need, keeping everything else in it: the TestCase binding the testing rule asks for,
 * and every helper function the kit's own copy declares.
 */
final class PestFile
{
    public const string BINDING = "pest()->extend(TestCase::class)\n    ->use(RefreshDatabase::class)\n    ->in('Feature', 'Browser');";

    /**
     * The TestCase binding (`pest()->extend(…)` or `uses(…)`) up to its `->in(…);`.
     */
    private const string BINDING_STATEMENT = '/^(?:pest\(\)|uses\()[^;]*?TestCase::class[^;]*->\s*in\([^;]*\);[ \t]*$/m';

    public static function complete(string $code, string $kitCopy): string
    {
        if (! self::bindsAsTheKitWants($code) && preg_match(self::BINDING_STATEMENT, $code) === 1) {
            $code = (string) preg_replace(self::BINDING_STATEMENT, str_replace('$', '\$', self::BINDING), $code, 1);
            $code = PhpFile::addUse($code, 'Illuminate\Foundation\Testing\RefreshDatabase');
            $code = PhpFile::addUse($code, 'Tests\TestCase');
        }

        $missing = '';

        foreach (self::functions($kitCopy) as $name => $block) {
            if (preg_match(sprintf('/^function %s\s*\(/m', preg_quote($name, '/')), $code) !== 1) {
                $missing .= "\n".$block;
            }
        }

        if ($missing === '') {
            return $code;
        }

        foreach (self::uses($kitCopy) as $class) {
            if (str_contains($missing, substr($class, (int) strrpos($class, '\\') + 1))) {
                $code = PhpFile::addUse($code, $class);
            }
        }

        return rtrim($code)."\n".$missing;
    }

    /**
     * Whether the file already binds TestCase with RefreshDatabase to Feature and Browser, read the
     * way the testing rule reads it.
     */
    public static function bindsAsTheKitWants(string $code): bool
    {
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        if (preg_match('/pest\(\)\s*->\s*extend\(\s*(?:\\\\?Tests\\\\)?TestCase::class\s*\)(.*?)->\s*in\(([^)]*)\)/s', $code, $matches) !== 1) {
            return false;
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $matches[2], $suites);

        return str_contains($matches[1], 'RefreshDatabase') && array_diff(['Feature', 'Browser'], $suites[1]) === [];
    }

    /**
     * Each top-level function of the kit's copy, with its docblock, by name.
     *
     * @return array<string, string>
     */
    private static function functions(string $code): array
    {
        preg_match_all('/^(?:\/\*\*(?:(?!\*\/).)*?\*\/\n)?function (\w+)\(.*?^\}\n/ms', $code, $matches, PREG_SET_ORDER);

        $functions = [];

        foreach ($matches as $match) {
            $functions[$match[1]] = $match[0];
        }

        return $functions;
    }

    /**
     * @return list<string>
     */
    private static function uses(string $code): array
    {
        preg_match_all('/^use ([^;]+);$/m', $code, $matches);

        return $matches[1];
    }
}
