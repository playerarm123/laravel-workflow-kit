<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * The props every page shares, which the kit's hooks read: the locale, the time zone, the
 * currency, the active locale's translations (list-pages.md, dates.md, numbers.md) and the main
 * menu app/Http/Navigation.php builds for the user (list-pages.md, "Menu").
 */
final class SharedProps
{
    private const string TRANSLATIONS = <<<'PHP'

            /**
             * The active locale's messages over the fallback locale's, so a key the active locale lacks
             * still shows text instead of its name.
             *
             * @return array<string, string>
             */
            private function translations(): array
            {
                return [...$this->messagesFor((string) config('app.fallback_locale')), ...$this->messagesFor(app()->getLocale())];
            }

            /**
             * @return array<string, string>
             */
            private function messagesFor(string $locale): array
            {
                $path = lang_path("{$locale}.json");

                if (! is_file($path)) {
                    return [];
                }

                $messages = json_decode((string) file_get_contents($path), true);

                return is_array($messages) ? $messages : [];
            }
        PHP;

    /**
     * Adds the props to HandleInertiaRequests::share() and the methods that read the translations.
     */
    public static function share(string $code): string
    {
        if (str_contains($code, "'translations' =>")) {
            return $code;
        }

        $props = "            'locale' => app()->getLocale(),\n"
            ."            'timezone' => config('app.timezone'),\n"
            ."            'currency' => config('app.currency'),\n"
            ."            'translations' => \$this->translations(),\n";

        $code = (string) preg_replace('/(public function share\(Request \$request\): array\n    \{\n(?:.*\n)*?)(        \];\n    \})/', "$1{$props}$2", $code, 1);

        return (string) preg_replace('/\n\}\s*$/', "\n".rtrim(self::TRANSLATIONS)."\n}\n", $code, 1);
    }

    /**
     * Adds the `menu` prop to HandleInertiaRequests::share(). Its own step, so a project set up
     * before the menu existed gets it too.
     */
    public static function menu(string $code): string
    {
        if (str_contains($code, "'menu' =>")) {
            return $code;
        }

        return (string) preg_replace(
            '/(public function share\(Request \$request\): array\n    \{\n(?:.*\n)*?)(        \];\n    \})/',
            "$1            'menu' => fn (): array => \\App\\Http\\Navigation::for(\$request->user()),\n$2",
            $code,
            1,
        );
    }

    /**
     * Adds the menu to the SharedProps type of a shared.ts written before the menu existed, and
     * widens the flat `MenuItem[]` of one written before menu groups to `MenuEntry[]`.
     */
    public static function declareMenu(string $code): string
    {
        if (preg_match('/^\s*menu: MenuItem\[\];$/m', $code) === 1) {
            $code = (string) preg_replace('/^(\s*)menu: MenuItem\[\];$/m', '$1menu: MenuEntry[];', $code, 1);
            $import = "/^import type \{ MenuItem \} from '@\/types\/ui';$/m";

            return preg_match('/\bMenuItem\b/', (string) preg_replace($import, '', $code)) === 1
                ? (string) preg_replace("/^import type \{ ([^}]*) \} from '@\/types\/ui';$/m", "import type { MenuEntry, $1 } from '@/types/ui';", $code, 1)
                : (string) preg_replace("/^import type \{ MenuItem \} from '@\/types\/ui';$/m", "import type { MenuEntry } from '@/types/ui';", $code, 1);
        }

        if (preg_match('/^\s*menu: /m', $code) === 1) {
            return $code;
        }

        $code = (string) preg_replace("/^(import type \{ Auth \} from '@\/types\/auth';\n)/m", "$1import type { MenuEntry } from '@/types/ui';\n", $code, 1);

        return (string) preg_replace('/(\n    translations: Record<string, string>;\n)(\};)/', "$1    /** @see app/Http/Navigation.php — the main menu, already narrowed to what the user may open */\n    menu: MenuEntry[];\n$2", $code, 1);
    }

    /**
     * Points the starter kit's global.d.ts at the SharedProps type the kit's hooks import.
     */
    public static function declare(string $code): string
    {
        if (str_contains($code, 'SharedProps')) {
            return $code;
        }

        $code = (string) preg_replace('/sharedPageProps: \{\n(?:.*\n)*?\s*\};/', 'sharedPageProps: SharedProps;', $code, 1);
        $code = (string) preg_replace('/^(\s*)(interface InputHTMLAttributes<T> \{)$/m', "$1// eslint-disable-next-line @typescript-eslint/no-unused-vars\n$1$2", $code, 1);

        return (string) preg_replace("/^import type \\{ Auth \\} from '@\\/types\\/auth';\n/m", "import type { SharedProps } from '@/types/shared';\n", $code, 1);
    }
}
