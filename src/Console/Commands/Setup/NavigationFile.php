<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * Brings an app/Http/Navigation.php written before menu groups (v0.2.13 and earlier) onto the
 * kit's BuildsMainMenu trait (list-pages.md, "Menu"), so its lines can take `group:` and
 * `description:`: the trait replaces the file's own `item()`, and `menu()` replaces the
 * `array_values(array_filter([ … ]))` around the list. The lines themselves are kept as they
 * are, in their order.
 *
 * The file belongs to the project, so a file whose `item()` or list no longer reads as the kit
 * wrote it is left alone, and kit:setup names it for a hand edit instead.
 */
final class NavigationFile
{
    private const string TRAIT = 'App\Http\Concerns\BuildsMainMenu';

    private const string ITEM = '/\n    \/\*\*\n     \* @param  class-string\|null  \$model\n     \* @return array\{title: string, href: string, icon: string\}\|null\n     \*\/\n    private function item\(string \$title, string \$route, string \$icon, \?string \$model = null\): \?array\n    \{\n        if \(\$model !== null && ! Gate::forUser\(\$this->user\)->allows\(\'viewAny\', \$model\)\) \{\n            return null;\n        \}\n\n        return \[\'title\' => \$title, \'href\' => route\(\$route, absolute: false\), \'icon\' => \$icon\];\n    \}\n/';

    private const string LIST = '/return array_values\(array_filter\(\[\n((?:.*\n)*?)(\s*)\]\)\);/';

    public static function upgrade(string $code): string
    {
        if (str_contains($code, 'BuildsMainMenu')) {
            return $code;
        }

        $upgraded = (string) preg_replace(self::ITEM, '', $code, 1, $removed);
        $upgraded = (string) preg_replace(self::LIST, "return \$this->menu([\n$1$2]);", $upgraded, 1, $wrapped);
        $upgraded = (string) preg_replace('/^((?:final )?class Navigation\b[^{\n]*\n?\{\n)/m', "$1    use BuildsMainMenu;\n\n", $upgraded, 1, $used);

        if ($removed !== 1 || $wrapped !== 1 || $used !== 1) {
            return $code;
        }

        $code = str_replace(
            [
                '@return list<array{title: string, href: string, icon: string}>',
                " * signed in. `kit:apply` writes a list page's entry above `// kit:menu`, so keep the marker as\n * the last line of the list, and move the lines into the order the menu should read.\n",
            ],
            [
                '@return list<MenuEntry>',
                " * signed in. To gather pages under a heading, declare the group once with\n * `\$this->group('{key}', '{title key}', '{lucide icon}')` where it should sit, and give each of\n * its entries `group: '{key}'`. A group holds pages only, never another group, and shows only\n * while the user may open one of them. `kit:apply` writes a list page's entry above\n * `// kit:menu`, so keep the marker as the last line of the list, and move the lines into the\n * order the menu should read.\n",
            ],
            $upgraded,
        );
        $code = (string) preg_replace('/\n \*\/\n((?:final )?class Navigation\b)/', "\n *\n * @phpstan-import-type MenuEntry from BuildsMainMenu\n */\n$1", $code, 1);
        $code = PhpFile::addUse($code, self::TRAIT);

        return preg_match('/\bGate::/', $code) === 1
            ? $code
            : (string) preg_replace('/^use Illuminate\\\\Support\\\\Facades\\\\Gate;\n/m', '', $code, 1);
    }
}
