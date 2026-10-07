<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

/**
 * Points code at a replacement instead of the class it replaces (structure.md): the `use` line,
 * the short name a file with that `use` line writes, and the full name written inline. Only the
 * folders a replacement is allowed to touch are searched, and the old class's own files are left
 * alone, so the old class still stands until kit:retire removes it.
 *
 * A list use case reaches the frontend too, through the TypeScript twins of its Row and Criteria
 * (list-pages.md), so this also renames a type in the pages that read it, and kit:retire takes
 * the old type and the old binding line out once nothing else names them.
 *
 * Imports may fall out of order; the caller runs Pint on the PHP files this returns, and prettier
 * on the TypeScript ones.
 */
final class StructureSwapper
{
    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * @param  array<string, string>  $classes  each old class's full name, to the new one's
     * @param  list<string>  $directories  folders to search, from the project root
     * @param  list<string>  $except  files or folders left alone, from the project root
     * @return list<string> the files changed, from the project root
     */
    public function swap(array $classes, array $directories, array $except = []): array
    {
        $changed = [];

        foreach ($this->files($directories, $except) as $file) {
            $before = $this->read($file);

            if ($before === null) {
                continue;
            }

            $after = $before;

            foreach ($classes as $old => $new) {
                $after = $this->swapOne($after, ltrim($old, '\\'), ltrim($new, '\\'));
            }

            if ($after !== $before) {
                file_put_contents($file, $after);
                $changed[] = substr($file, strlen($this->root) + 1);
            }
        }

        return $changed;
    }

    /**
     * The files that still name a class, from the project root, outside the paths left alone. A
     * binding of the old piece's port to its adapter belongs to the old piece, so it is read past.
     *
     * @param  list<string>  $classes  full class names
     * @param  list<string>  $directories
     * @param  list<string>  $except
     * @param  array<string, string>  $bindings  each port's full name, to the adapter bound to it
     * @return list<string>
     */
    public function references(array $classes, array $directories, array $except = [], array $bindings = []): array
    {
        $found = [];

        foreach ($this->files($directories, $except) as $file) {
            $contents = $this->read($file) ?? '';

            foreach ($bindings as $port => $adapter) {
                $contents = self::unbound($contents, $port, $adapter);
            }

            foreach ($classes as $class) {
                if (str_contains($contents, ltrim($class, '\\'))) {
                    $found[] = substr($file, strlen($this->root) + 1);

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Renames whole names, such as a TypeScript type, in the files of the given extensions.
     *
     * @param  array<string, string>  $names  each old name, to the new one
     * @param  list<string>  $directories  folders to search, from the project root
     * @param  list<string>  $extensions
     * @return list<string> the files changed, from the project root
     */
    public function swapNames(array $names, array $directories, array $extensions = ['ts', 'tsx']): array
    {
        $changed = [];

        foreach ($this->files($directories, [], $extensions) as $file) {
            $before = $this->read($file);

            if ($before === null) {
                continue;
            }

            $after = $before;

            foreach ($names as $old => $new) {
                $after = (string) preg_replace('/(?<![\w$])'.preg_quote($old, '/').'(?![\w$])/', $new, $after);
            }

            if ($after !== $before) {
                file_put_contents($file, $after);
                $changed[] = substr($file, strlen($this->root) + 1);
            }
        }

        return $changed;
    }

    /**
     * The files that still name one of the names as a whole word, from the project root.
     *
     * @param  list<string>  $names
     * @param  list<string>  $directories
     * @param  list<string>  $except
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public function nameReferences(array $names, array $directories, array $except = [], array $extensions = ['ts', 'tsx']): array
    {
        $found = [];

        foreach ($this->files($directories, $except, $extensions) as $file) {
            $contents = $this->read($file) ?? '';

            foreach ($names as $name) {
                if (preg_match('/(?<![\w$])'.preg_quote($name, '/').'(?![\w$])/', $contents) === 1) {
                    $found[] = substr($file, strlen($this->root) + 1);

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The file under a folder that declares a TypeScript type, from the project root, or null.
     */
    public function declaringFile(string $type, string $directory): ?string
    {
        foreach ($this->files([$directory], [], ['ts']) as $file) {
            if (preg_match('/^export\s+type\s+'.preg_quote($type, '/').'\b/m', $this->read($file) ?? '') === 1) {
                return substr($file, strlen($this->root) + 1);
            }
        }

        return null;
    }

    /**
     * Takes TypeScript types out of the file that declares them, each with the docblock above it,
     * along with the type imports nothing left in the file names. A file left with no export goes,
     * and so does its line in the folder's `index.ts`.
     *
     * @param  list<string>  $types
     * @return list<string> the files changed or removed, from the project root
     */
    public function removeTypes(string $file, array $types): array
    {
        $path = $this->root.'/'.$file;
        $contents = $this->read($path);

        if ($contents === null) {
            return [];
        }

        $after = $contents;

        foreach ($types as $type) {
            $after = self::withoutType($after, $type);
        }

        if ($after === $contents) {
            return [];
        }

        $after = self::withoutUnusedTypeImports($after);

        if (preg_match('/^export\s/m', $after) === 1) {
            file_put_contents($path, $after);

            return [$file];
        }

        @unlink($path);
        $barrel = dirname($path).'/index.ts';
        $slug = basename($file, '.ts');
        $before = $this->read($barrel);

        if ($before !== null) {
            $without = (string) preg_replace('#^export (?:type )?\* from \'\./'.preg_quote($slug, '#').'\';\R#m', '', $before);

            if ($without !== $before) {
                file_put_contents($barrel, $without);

                return [$file, substr($barrel, strlen($this->root) + 1)];
            }
        }

        return [$file];
    }

    /**
     * Takes the line that binds a port to an adapter out of every provider under the folders, with
     * the `use` lines nothing else in the file names any more.
     *
     * @param  list<string>  $directories
     * @return list<string> the files changed, from the project root
     */
    public function unbind(string $port, string $adapter, array $directories): array
    {
        $changed = [];

        foreach ($this->files($directories, []) as $file) {
            $before = $this->read($file);

            if ($before === null || ! str_ends_with($file, 'ServiceProvider.php')) {
                continue;
            }

            $after = self::unbound($before, $port, $adapter);

            if ($after !== $before) {
                file_put_contents($file, $after);
                $changed[] = substr($file, strlen($this->root) + 1);
            }
        }

        return $changed;
    }

    /**
     * A file's text without the line that binds the port to the adapter, written either with full
     * names or with the short names its `use` lines import, and without those `use` lines once
     * nothing else names them.
     */
    public static function unbound(string $contents, string $port, string $adapter): string
    {
        $port = ltrim($port, '\\');
        $adapter = ltrim($adapter, '\\');
        $name = fn (string $class): string => '(?:\\\\?'.preg_quote($class, '/').'|'.preg_quote(self::baseName($class), '/').')';
        $after = (string) preg_replace('/^[ \t]*'.$name($port).'::class\s*=>\s*'.$name($adapter).'::class,?[ \t]*\R/m', '', $contents);

        if ($after === $contents) {
            return $contents;
        }

        foreach ([$port, $adapter] as $class) {
            $import = '/^use '.preg_quote($class, '/').';\R/m';
            $rest = (string) preg_replace($import, '', $after);

            if ($rest !== $after && preg_match('/(?<![\w\\\\$])'.preg_quote(self::baseName($class), '/').'(?!\w)/', $rest) !== 1) {
                $after = $rest;
            }
        }

        return $after;
    }

    /**
     * A TypeScript file's text without one `export type`, from the docblock right above it to the
     * `;` that ends it outside every bracket.
     */
    private static function withoutType(string $contents, string $type): string
    {
        if (preg_match('/^export\s+type\s+'.preg_quote($type, '/').'\b/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $contents;
        }

        $start = $match[0][1];
        $before = rtrim(substr($contents, 0, $start));

        if (str_ends_with($before, '*/')) {
            $opening = strrpos($before, '/**');
            $start = $opening === false ? $start : $opening;
        }

        $depth = 0;
        $end = strlen($contents);

        for ($index = $match[0][1]; $index < strlen($contents); $index++) {
            $character = $contents[$index];

            if (in_array($character, ['{', '(', '['], true)) {
                $depth++;
            } elseif (in_array($character, ['}', ')', ']'], true)) {
                $depth--;
            } elseif ($character === ';' && $depth === 0) {
                $end = $index + 1;

                break;
            }
        }

        $head = rtrim(substr($contents, 0, $start));
        $tail = ltrim(substr($contents, $end));

        return match (true) {
            $head === '' => $tail,
            $tail === '' => $head."\n",
            default => $head."\n\n".$tail,
        };
    }

    /**
     * A TypeScript file's text without the names its `import type` lines bring in that nothing
     * else in it names, and without an import left with none or the blank lines it leaves.
     */
    private static function withoutUnusedTypeImports(string $contents): string
    {
        $after = (string) preg_replace_callback(
            '/^import\s+type\s*\{([^}]*)\}\s*from\s*(\'[^\']+\');\R/m',
            function (array $import) use ($contents): string {
                $rest = str_replace($import[0], '', $contents);
                $kept = array_values(array_filter(
                    array_map('trim', explode(',', $import[1])),
                    fn (string $name): bool => $name !== '' && preg_match('/(?<![\w$])'.preg_quote($name, '/').'(?![\w$])/', $rest) === 1,
                ));

                return $kept === [] ? '' : 'import type { '.implode(', ', $kept)." } from {$import[2]};\n";
            },
            $contents,
        );

        return $after === $contents ? $contents : (string) preg_replace('/\n{3,}/', "\n\n", ltrim($after, "\n"));
    }

    private static function baseName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function swapOne(string $contents, string $old, string $new): string
    {
        $oldShort = self::baseName($old);
        $newShort = self::baseName($new);
        $imported = preg_match('/^use '.preg_quote($old, '/').';$/m', $contents) === 1;

        $contents = (string) preg_replace('/^use '.preg_quote($old, '/').';$/m', 'use '.str_replace('\\', '\\\\', $new).';', $contents);
        $contents = (string) preg_replace('/\\\\'.preg_quote($old, '/').'(?!\w)/', '\\\\'.str_replace('\\', '\\\\', $new), $contents);

        if ($imported && $oldShort !== $newShort) {
            $contents = (string) preg_replace('/(?<![\w\\\\$])'.preg_quote($oldShort, '/').'(?!\w)/', $newShort, $contents);
        }

        return $contents;
    }

    /**
     * Every PHP file under a folder. A folder another process removes while it is walked is
     * skipped rather than failing the walk.
     *
     * @return list<string>
     */
    public static function phpFilesUnder(string $directory): array
    {
        return self::filesUnder($directory, ['php']);
    }

    /**
     * Every file under a folder with one of the extensions, walked as phpFilesUnder() walks.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function filesUnder(string $directory, array $extensions): array
    {
        $files = [];
        $pending = [$directory];

        while ($pending !== []) {
            $folder = array_pop($pending);
            $entries = @scandir($folder);

            foreach ($entries === false ? [] : $entries as $entry) {
                $path = $folder.'/'.$entry;

                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if (is_dir($path)) {
                    $pending[] = $path;
                } elseif (in_array(pathinfo($entry, PATHINFO_EXTENSION), $extensions, true)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A file's text, or null when it is gone between listing the folder and reading it.
     */
    private function read(string $file): ?string
    {
        return is_file($file) ? StructureFiles::text($file) : null;
    }

    /**
     * The files under the folders, PHP unless other extensions are named, without the paths left
     * alone.
     *
     * @param  list<string>  $directories
     * @param  list<string>  $except
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function files(array $directories, array $except, array $extensions = ['php']): array
    {
        $files = [];
        $skipped = array_map(fn (string $path): string => $this->root.'/'.trim($path, '/'), $except);

        foreach ($directories as $directory) {
            $path = $this->root.'/'.trim($directory, '/');

            if (! is_dir($path)) {
                continue;
            }

            foreach (self::filesUnder($path, $extensions) as $name) {
                foreach ($skipped as $skip) {
                    if ($name === $skip || str_starts_with($name, $skip.'/')) {
                        continue 2;
                    }
                }

                $files[] = $name;
            }
        }

        sort($files);

        return $files;
    }
}
