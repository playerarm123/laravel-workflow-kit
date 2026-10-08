<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * The props every page shares, which the kit's hooks read: the locale, the time zone, the
 * currency and the active locale's translations (list-pages.md, dates.md, numbers.md).
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
