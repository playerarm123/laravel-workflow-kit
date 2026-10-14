<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * Points the starter kit's sidebar and header at the menu the server shares (list-pages.md,
 * "Menu"): each file declares its own `const mainNavItems: NavItem[] = [ … ]` with the dashboard
 * in it, and reads `useMainNavItems()` instead, so a new page's entry shows in both at once.
 */
final class MainNav
{
    private const string HOOK = '@/hooks/use-main-nav-items';

    public static function rewire(string $code): string
    {
        if (str_contains($code, 'useMainNavItems()')) {
            return $code;
        }

        $without = (string) preg_replace('/^const mainNavItems: NavItem\[\] = \[\n(?:.*\n)*?\];\n\n/m', '', $code, 1, $removed);

        if ($removed !== 1) {
            return $code;
        }

        $rewired = (string) preg_replace(
            '/(\nexport (?:default )?function \w+\([^)]*\)[^{\n]*\{\n)/',
            "$1    const mainNavItems = useMainNavItems();\n",
            $without,
            1,
            $placed,
        );

        return $placed === 1 ? self::withoutUnusedIcons(self::import($rewired)) : $code;
    }

    /**
     * Adds the hook's import among the `@/` imports, where import/order's alphabetical order
     * puts it.
     */
    private static function import(string $code): string
    {
        $line = sprintf("import { useMainNavItems } from '%s';\n", self::HOOK);

        preg_match_all("/^import [^;]*? from '([^']+)';\\n/ms", $code, $imports, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $last = null;

        foreach ($imports as $import) {
            [$statement, $at] = $import[0];
            $path = $import[1][0];

            if (str_starts_with($path, '@/') && strcasecmp($path, self::HOOK) > 0) {
                return substr($code, 0, $at).$line.substr($code, $at);
            }

            $last = $at + strlen($statement);
        }

        return $last === null ? $line.$code : substr($code, 0, $last).$line.substr($code, $last);
    }

    /**
     * Drops the lucide icons only the old list used (the dashboard's LayoutGrid), so nothing is
     * left imported and unused.
     */
    private static function withoutUnusedIcons(string $code): string
    {
        return (string) preg_replace_callback(
            "/^import \\{([^}]*)\\} from 'lucide-react';\\n/m",
            function (array $match) use ($code): string {
                $rest = str_replace($match[0], '', $code);
                $kept = array_values(array_filter(
                    array_map('trim', explode(',', $match[1])),
                    fn (string $name): bool => $name !== '' && preg_match('/\b'.preg_quote($name, '/').'\b/', $rest) === 1,
                ));

                return $kept === [] ? '' : sprintf("import { %s } from 'lucide-react';\n", implode(', ', $kept));
            },
            $code,
            1,
        );
    }
}
