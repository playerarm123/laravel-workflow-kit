<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * What `kit:ui-add` decides about the shadcn components it adds, apart from running the CLI:
 * which of their packages the stack lets in, where components.json's aliases put files, and the
 * fixes every file the CLI writes needs before it reads like the rest of the project.
 *
 * shadcn's registry imports `cn` from the `cn` package and lists that package as a dependency,
 * while the stack merges class names with `cn()` from the utils alias and forbids the package
 * (stack.md). The registry also still names `@radix-ui/*` packages in places, which the stack
 * swaps for the single `radix-ui`.
 */
final class UiComponents
{
    /** Where `@/` points when tsconfig.json does not say: Laravel's starter kits keep it here. */
    private const string DEFAULT_ROOT = 'resources/js';

    /** The components.json aliases a component's files may land under. */
    private const array FILE_ALIASES = ['components', 'ui', 'hooks', 'lib'];

    /** Where a registry item's file lands when it names no target, by the file's type. */
    private const array ALIAS_OF_TYPE = [
        'registry:ui' => 'ui',
        'registry:hook' => 'hooks',
        'registry:lib' => 'lib',
    ];

    /**
     * Sorts the packages the registry items ask for. A forbidden package is dropped, one the
     * project already declares needs nothing, one stack.md lists or `--allow` names is installed,
     * and any other waits for the user to approve it (stack.md, "Adding any other dependency").
     *
     * @param  list<array<string, mixed>>  $items  the registry items, as `shadcn view` prints them
     * @param  array<string, mixed>  $package  the project's package.json
     * @param  array{required: array<string, string>, optional: array<string, string>, forbidden: list<string>}  $npm  `stackSpec()['npm']`
     * @param  list<string>  $allow
     * @return array{install: list<string>, installDev: list<string>, dropped: array<string, string>, awaiting: list<string>}
     */
    public static function settle(array $items, array $package, array $npm, array $allow): array
    {
        $settled = ['install' => [], 'installDev' => [], 'dropped' => [], 'awaiting' => []];
        $declared = [...(array) ($package['dependencies'] ?? []), ...(array) ($package['devDependencies'] ?? [])];
        $lines = [...$npm['required'], ...$npm['optional']];

        foreach (['dependencies' => 'install', 'devDependencies' => 'installDev'] as $key => $list) {
            foreach ($items as $item) {
                foreach ((array) ($item[$key] ?? []) as $dependency) {
                    [$name, $constraint] = self::splitDependency((string) $dependency);
                    $forbidden = self::firstMatch($name, $npm['forbidden']);

                    if ($forbidden !== null) {
                        $settled['dropped'][$name] = $name === 'cn'
                            ? 'the stack merges class names with cn() from @/lib/utils, never the cn package (stack.md)'
                            : sprintf('the stack forbids %s (stack.md)', $forbidden);
                    } elseif (array_key_exists($name, $declared)) {
                        continue;
                    } elseif (array_key_exists($name, $lines) || in_array($name, $allow, true)) {
                        $spec = $constraint ?? (isset($lines[$name]) ? '^'.$lines[$name] : null);
                        $settled[$list][$name] = $spec === null ? $name : $name.'@'.$spec;
                    } else {
                        $settled['awaiting'][$name] = $name;
                    }
                }
            }
        }

        $settled['installDev'] = array_diff_key($settled['installDev'], $settled['install']);

        return [
            'install' => array_values($settled['install']),
            'installDev' => array_values($settled['installDev']),
            'dropped' => $settled['dropped'],
            'awaiting' => array_values($settled['awaiting']),
        ];
    }

    /**
     * A file the CLI wrote, fixed: `cn` comes from the utils alias, `@radix-ui/*` imports read
     * `radix-ui` (RadixImports), and sonner's Toaster leaves the flash toast to FlashToast.
     */
    public static function fix(string $code, string $utilsAlias): string
    {
        $code = (string) preg_replace('/(\b(?:from|import)\s*)(["\'])cn\2/', '${1}\''.$utilsAlias.'\'', $code);

        return self::withoutFlashToast(RadixImports::rewrite($code));
    }

    /**
     * The starter kit's sonner.tsx shows the flash toast itself; the kit renders FlashToast beside
     * `<Toaster>` instead (form-pages.md), so the Toaster stops calling useFlashToast().
     */
    public static function withoutFlashToast(string $code): string
    {
        return (string) preg_replace(
            ["/import \\{ useFlashToast \\} from '@\\/hooks\\/use-flash-toast';\n/", "/\n    useFlashToast\\(\\);\n/"],
            ['', ''],
            $code,
        );
    }

    /**
     * The folders, from the project root, that components.json's file aliases point at.
     *
     * @param  array<string, mixed>  $components  components.json
     * @param  array<string, mixed>  $tsconfig  tsconfig.json
     * @return list<string>
     */
    public static function aliasRoots(array $components, array $tsconfig): array
    {
        $roots = [];

        foreach (self::FILE_ALIASES as $alias) {
            $path = self::aliasPath($components, $tsconfig, $alias);

            if ($path !== null) {
                $roots[] = $path;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * The import path of the utils file, `@/lib/utils` in a starter kit.
     *
     * @param  array<string, mixed>  $components
     */
    public static function utilsAlias(array $components): string
    {
        $alias = $components['aliases']['utils'] ?? null;

        return is_string($alias) && $alias !== '' ? $alias : '@/lib/utils';
    }

    /**
     * Where a registry item's file lands, from the project root, for the plan `--dry-run` prints.
     *
     * @param  array<string, mixed>  $file  one of a registry item's `files`
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $tsconfig
     */
    public static function targetOf(array $file, array $components, array $tsconfig): string
    {
        $target = $file['target'] ?? null;

        if (is_string($target) && $target !== '') {
            return ltrim(str_replace('~/', '', $target), '/');
        }

        $alias = self::ALIAS_OF_TYPE[$file['type'] ?? ''] ?? 'components';
        $root = self::aliasPath($components, $tsconfig, $alias) ?? self::DEFAULT_ROOT.'/'.$alias;

        return $root.'/'.basename((string) ($file['path'] ?? ''));
    }

    /**
     * Whether a project file is one the user named with `--overwrite`: by its path from the
     * project root, or by its trailing path (`ui/dialog.tsx`, `dialog.tsx`).
     *
     * @param  list<string>  $named
     */
    public static function isNamed(string $relative, array $named): bool
    {
        foreach ($named as $name) {
            $name = (string) preg_replace('#^\./#', '', str_replace('\\', '/', $name));

            if ($name !== '' && ($relative === $name || str_ends_with($relative, '/'.$name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads a tsconfig.json, which may carry comments and trailing commas.
     *
     * @return array<string, mixed>
     */
    public static function decodeJsonc(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $stripped = (string) preg_replace('#("(?:[^"\\\\]|\\\\.)*")|/\*.*?\*/|//[^\n]*#s', '$1', $json);
            $decoded = json_decode((string) preg_replace('/,(\s*[}\]])/', '$1', $stripped), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $tsconfig
     */
    private static function aliasPath(array $components, array $tsconfig, string $alias): ?string
    {
        $value = $components['aliases'][$alias] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        foreach ((array) ($tsconfig['compilerOptions']['paths'] ?? []) as $pattern => $targets) {
            $prefix = rtrim((string) $pattern, '*');
            $target = ((array) $targets)[0] ?? null;

            if ($prefix !== '' && is_string($target) && str_starts_with($value, $prefix)) {
                return trim(rtrim(ltrim($target, './'), '*'), '/').'/'.substr($value, strlen($prefix));
            }
        }

        return str_starts_with($value, '@/') ? self::DEFAULT_ROOT.'/'.substr($value, 2) : null;
    }

    /**
     * `cmdk`, `cmdk@^1.1.1` or `@scope/name@1` as its name and its constraint, if it has one.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function splitDependency(string $dependency): array
    {
        $at = strrpos($dependency, '@');

        return $at === false || $at === 0
            ? [$dependency, null]
            : [substr($dependency, 0, $at), substr($dependency, $at + 1)];
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function firstMatch(string $name, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return $pattern;
            }
        }

        return null;
    }
}
