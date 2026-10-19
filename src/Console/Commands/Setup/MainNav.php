<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * Points the starter kit's sidebar and header at the menu the server shares (list-pages.md,
 * "Menu"): each file declares its own `const mainNavItems: NavItem[] = [ … ]` with the dashboard
 * in it, and reads `useMainNavItems()` instead, so a new page's entry shows in both at once.
 *
 * The starter kit draws one level only, so the kit's own components draw the menu in its place:
 * MainNavSidebar instead of `<NavMain>`, and MainNavHeader and MainNavMobile instead of the
 * header's two `mainNavItems.map(…)`, so a group shows as a heading in the sidebar and as a
 * dropdown in the header. A file set up before menu groups, which reads the hook already, gets
 * the components the same way. A file that reads neither way is left as it is, whole.
 */
final class MainNav
{
    private const string HOOK = '@/hooks/use-main-nav-items';

    private const string SIDEBAR = '@/components/main-nav-sidebar';

    private const string HEADER = '@/components/main-nav-header';

    /** Prettier's printWidth in the `.prettierrc` kit:setup writes. */
    private const int WIDTH = 80;

    /** What the header drew its menu with, which the kit's components leave unused. */
    private const array HEADER_LEFTOVERS = [
        '@/components/ui/navigation-menu' => ['NavigationMenuItem', 'navigationMenuTriggerStyle'],
        '@/lib/utils' => ['cn'],
    ];

    public static function rewire(string $code): string
    {
        $hooked = str_contains($code, 'useMainNavItems()') ? $code : self::readHook($code);

        if ($hooked === null) {
            return $code;
        }

        if (str_contains($hooked, '<MainNavSidebar') || str_contains($hooked, '<MainNavHeader')) {
            return $hooked;
        }

        return self::drawSidebar($hooked) ?? self::drawHeader($hooked) ?? $code;
    }

    /**
     * Swaps the file's own `mainNavItems` list for the hook, or null when it has no such list.
     */
    private static function readHook(string $code): ?string
    {
        $without = (string) preg_replace('/^const mainNavItems: NavItem\[\] = \[\n(?:.*\n)*?\];\n\n/m', '', $code, 1, $removed);

        if ($removed !== 1) {
            return null;
        }

        // A blank line before a `return`, as the ESLint config's padding rule wants it.
        $rewired = (string) preg_replace_callback(
            '/(\nexport (?:default )?function \w+\([^)]*\)[^{\n]*\{\n)(\s*return\b)?/',
            fn (array $match): string => $match[1]."    const mainNavItems = useMainNavItems();\n".(($match[2] ?? '') === '' ? '' : "\n".$match[2]),
            $without,
            1,
            $placed,
        );

        return $placed === 1
            ? self::withoutUnusedIcons(self::import($rewired, sprintf("import { useMainNavItems } from '%s';\n", self::HOOK), self::HOOK))
            : null;
    }

    /**
     * `<NavMain items={mainNavItems} />` → `<MainNavSidebar items={mainNavItems} />`.
     */
    private static function drawSidebar(string $code): ?string
    {
        $drawn = str_replace('<NavMain items={mainNavItems} />', '<MainNavSidebar items={mainNavItems} />', $code, $count);

        if ($count !== 1) {
            return null;
        }

        $withoutNavMain = (string) preg_replace("/^import \{ NavMain \} from '@\/components\/nav-main';\n/m", '', $drawn, 1);

        if (preg_match('/\bNavMain\b/', $withoutNavMain) === 0) {
            $drawn = $withoutNavMain;
        }

        return self::import($drawn, sprintf("import { MainNavSidebar } from '%s';\n", self::SIDEBAR), self::SIDEBAR);
    }

    /**
     * The desktop `mainNavItems.map(…)` inside `<NavigationMenuList>` → `<MainNavHeader … />`,
     * and the one in the small screen's sheet → `<MainNavMobile … />`.
     */
    private static function drawHeader(string $code): ?string
    {
        $drawn = (string) preg_replace_callback(
            '/^([ \t]*)\{mainNavItems\.map\(\(item, index\) => \(\n\s*<NavigationMenuItem\b(?:.*\n)*?\s*<\/NavigationMenuItem>\n\s*\)\)\}\n/m',
            fn (array $match): string => self::element($match[1], 'MainNavHeader'),
            $code,
            1,
            $desktop,
        );
        $drawn = (string) preg_replace_callback(
            '/^([ \t]*)\{mainNavItems\.map\(\(item\) => \(\n\s*<Link\b(?:.*\n)*?\s*<\/Link>\n\s*\)\)\}\n/m',
            fn (array $match): string => self::element($match[1], 'MainNavMobile'),
            $drawn,
            1,
            $mobile,
        );

        if ($desktop !== 1 || $mobile !== 1) {
            return null;
        }

        $drawn = self::withoutUnusedCurrentUrl(self::withoutUnusedConst($drawn, 'activeItemStyles'));

        foreach (self::HEADER_LEFTOVERS as $module => $names) {
            $drawn = self::withoutUnusedImports($drawn, $module, $names);
        }

        return self::import($drawn, sprintf("import { MainNavHeader, MainNavMobile } from '%s';\n", self::HEADER), self::HEADER);
    }

    /**
     * `<{name} items={mainNavItems} />` at an indent, its prop on a line of its own when the
     * element would run past Prettier's width, as Prettier prints it.
     */
    private static function element(string $indent, string $name): string
    {
        $line = "{$indent}<{$name} items={mainNavItems} />";

        return strlen($line) <= self::WIDTH
            ? $line."\n"
            : "{$indent}<{$name}\n{$indent}    items={mainNavItems}\n{$indent}/>\n";
    }

    /**
     * Adds an import among the `@/` imports, where import/order's alphabetical order puts it.
     */
    private static function import(string $code, string $line, string $path): string
    {
        preg_match_all("/^import [^;]*? from '([^']+)';\\n/ms", $code, $imports, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $last = null;

        foreach ($imports as $import) {
            [$statement, $at] = $import[0];
            $from = $import[1][0];

            if (str_starts_with($from, '@/') && strcasecmp($from, $path) > 0) {
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

    /**
     * Drops `names` from a module's named import when nothing else in the file reads them, and
     * prints what is left the way Prettier does: on one line when it fits, one name a line when
     * it does not.
     *
     * @param  list<string>  $names
     */
    private static function withoutUnusedImports(string $code, string $module, array $names): string
    {
        $pattern = sprintf("/^import (type )?\\{([^}]*)\\} from '%s';\\n/m", preg_quote($module, '/'));

        return (string) preg_replace_callback(
            $pattern,
            function (array $match) use ($code, $names, $module): string {
                $rest = str_replace($match[0], '', $code);
                $all = array_values(array_filter(array_map('trim', explode(',', $match[2])), fn (string $name): bool => $name !== ''));
                $kept = array_values(array_filter(
                    $all,
                    fn (string $name): bool => ! in_array($name, $names, true) || preg_match('/\b'.preg_quote($name, '/').'\b/', $rest) === 1,
                ));

                if ($kept === $all) {
                    return $match[0];
                }

                if ($kept === []) {
                    return '';
                }

                $line = sprintf("import %s{ %s } from '%s';", $match[1], implode(', ', $kept), $module);

                return strlen($line) <= self::WIDTH
                    ? $line."\n"
                    : sprintf("import %s{\n%s} from '%s';\n", $match[1], implode('', array_map(fn (string $name): string => "    {$name},\n", $kept)), $module);
            },
            $code,
            1,
        );
    }

    /**
     * Drops a top-level `const {name} = …;` and the blank line after it when nothing reads it.
     */
    private static function withoutUnusedConst(string $code, string $name): string
    {
        $pattern = sprintf('/^const %s =\s*[^;]*;\n\n/m', preg_quote($name, '/'));
        $without = (string) preg_replace($pattern, '', $code, 1);

        return preg_match('/\b'.preg_quote($name, '/').'\b/', $without) === 1 ? $code : $without;
    }

    /**
     * Drops what the header took from useCurrentUrl() for its own links, and the hook's import
     * when nothing is left that reads it.
     */
    private static function withoutUnusedCurrentUrl(string $code): string
    {
        $code = (string) preg_replace_callback(
            '/^(\s*)const \{ ([^}]*) \} = useCurrentUrl\(\);\n/m',
            function (array $match) use ($code): string {
                $rest = str_replace($match[0], '', $code);
                $kept = array_values(array_filter(
                    array_map('trim', explode(',', $match[2])),
                    fn (string $name): bool => $name !== '' && preg_match('/\b'.preg_quote($name, '/').'\b/', $rest) === 1,
                ));

                return $kept === [] ? '' : sprintf("%sconst { %s } = useCurrentUrl();\n", $match[1], implode(', ', $kept));
            },
            $code,
            1,
        );

        return self::withoutUnusedImports($code, '@/hooks/use-current-url', ['useCurrentUrl']);
    }
}
