<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * Moves the starter kit's imports from the `@radix-ui/react-*` packages to the single `radix-ui`
 * package the stack names, the way shadcn's own migration writes them:
 * `import * as DialogPrimitive from "@radix-ui/react-dialog"` reads
 * `import { Dialog as DialogPrimitive } from "radix-ui"`, and the `Slot` component becomes
 * `Slot.Root`.
 */
final class RadixImports
{
    public static function rewrite(string $code): string
    {
        if (! str_contains($code, '@radix-ui/')) {
            return $code;
        }

        $code = (string) preg_replace_callback(
            '/import \* as (\w+) from (["\'])@radix-ui\/react-([\w-]+)\2/',
            fn (array $match): string => sprintf('import { %s as %s } from %sradix-ui%s', self::studly($match[3]), $match[1], $match[2], $match[2]),
            $code,
        );

        if (preg_match('/import \{ Slot \} from (["\'])@radix-ui\/react-slot\1/', $code) === 1) {
            $code = (string) preg_replace('/import \{ Slot \} from (["\'])@radix-ui\/react-slot\1/', 'import { Slot } from $1radix-ui$1', $code);
            $code = (string) preg_replace('/(?<![\w.])Slot(?![\w.])(?! \} from)/', 'Slot.Root', $code);
        }

        return $code;
    }

    private static function studly(string $kebab): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab)));
    }
}
