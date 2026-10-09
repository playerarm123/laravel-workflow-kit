<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

use Illuminate\Support\Facades\Process;
use ReflectionMethod;

/**
 * What a generator that writes one half of a page from the other needs: read a method's
 * `@return array{…}` into TypeScript types, add them to a types file without clobbering it,
 * write files from stubs without overwriting, hand TypeScript to prettier, and report the
 * translation keys still missing.
 *
 * The using command holds `$files` (a Filesystem) and renders its own stubs.
 */
trait WritesGeneratedFiles
{
    /**
     * What the run could not settle on its own, reported once the files are written.
     *
     * @var list<string>
     */
    protected array $warnings = [];

    /**
     * @var list<string> translation keys the generated files read
     */
    protected array $langKeys = [];

    /**
     * @param  array<string, string>  $replacements
     */
    abstract protected function render(string $stub, array $replacements): string;

    /**
     * The keys and TypeScript types a method's `@return array{…}` names, in order.
     *
     * @return array<string, string>|null null, already reported, when the method has no shape
     */
    protected function shapeOf(string $class, string $method): ?array
    {
        $doc = method_exists($class, $method) ? (new ReflectionMethod($class, $method))->getDocComment() : false;
        $body = is_string($doc) ? $this->arrayShapeBody($doc) : null;

        if ($body === null) {
            $this->components->error(sprintf(
                '%s::%s() needs a `@return array{…}` docblock naming every key it sends — it is what the TypeScript type is written from.',
                $class,
                $method,
            ));

            return null;
        }

        $shape = [];

        foreach ($this->splitTopLevel($body, ',') as $entry) {
            if (preg_match('/^([\'"]?)([\w-]+)\1(\?)?\s*:\s*(.+)$/s', trim($entry), $match) !== 1) {
                continue;
            }

            $shape[$match[2].$match[3]] = $this->typeScriptType($match[4], sprintf('%s::%s() key "%s"', class_basename($class), $method, $match[2]));
        }

        return $shape;
    }

    /**
     * The text between the braces of the docblock's `@return array{…}`.
     */
    protected function arrayShapeBody(string $doc): ?string
    {
        $text = implode(' ', array_map(
            fn (string $line): string => (string) preg_replace('#^\s*(/\*\*|\*/|\*)?\s?#', '', $line),
            explode("\n", $doc),
        ));

        if (preg_match('/@return\s+array\{/', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;

        for ($i = $start; $i < strlen($text); $i++) {
            $depth += match ($text[$i]) {
                '{', '<', '(' => 1,
                '}', '>', ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return substr($text, $start, $i - $start);
            }
        }

        return null;
    }

    /**
     * Split on a separator that sits outside every `{}`, `<>` and `()`.
     *
     * @return list<string>
     */
    protected function splitTopLevel(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($text) as $character) {
            $depth += match ($character) {
                '{', '<', '(' => 1,
                '}', '>', ')' => -1,
                default => 0,
            };

            if ($character === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        return array_values(array_filter([...$parts, $current], fn (string $part): bool => trim($part) !== ''));
    }

    /**
     * The TypeScript a PHPDoc type arrives as once JSON carried it. Anything the generator
     * cannot map is written `unknown` and reported, never guessed.
     */
    protected function typeScriptType(string $phpType, string $subject): string
    {
        $types = [];

        foreach ($this->splitTopLevel(trim($phpType), '|') as $part) {
            $part = trim($part);

            if (str_starts_with($part, '?')) {
                $part = substr($part, 1);
                $types[] = 'null';
            }

            $mapped = match (true) {
                $part === 'null' => 'null',
                in_array($part, ['string', 'non-empty-string', 'numeric-string', 'literal-string', 'non-falsy-string', 'lowercase-string'], true) => 'string',
                preg_match('/^(int|float|positive-int|negative-int|non-negative-int|non-positive-int|int<.*>)$/', $part) === 1 => 'number',
                in_array($part, ['bool', 'true', 'false'], true) => 'boolean',
                preg_match('/^\'[^\']*\'$|^-?\d+(\.\d+)?$/', $part) === 1 => $part,
                default => null,
            };

            if ($mapped === null) {
                $this->warnings[] = sprintf('%s is `%s`, which the generator cannot mirror — it is written `unknown`, type it by hand.', $subject, trim($phpType));
                $mapped = 'unknown';
            }

            $types[] = $mapped;
        }

        $types = array_values(array_unique($types));

        // null reads last, as every hand-written row type spells it.
        usort($types, fn (string $a, string $b): int => ($a === 'null') <=> ($b === 'null'));

        return implode(' | ', $types);
    }

    protected function typeNameOf(string $block): string
    {
        return preg_match('/export\s+type\s+(\w+)/', $block, $match) === 1 ? $match[1] : '';
    }

    protected function declaresType(string $contents, string $type): bool
    {
        return preg_match('/\bexport\s+type\s+'.preg_quote($type, '/').'\b/', $contents) === 1;
    }

    /**
     * Put an import where import/order wants it: aliased before relative, alphabetical within.
     */
    protected function addImport(string $contents, string $import, string $module): string
    {
        $rank = fn (string $path): string => (str_starts_with($path, '.') ? '3' : (str_starts_with($path, '@/') ? '2' : '1')).strtolower($path);

        preg_match_all("/^import\\s[^;]*?from\\s+'([^']+)';\\n?/m", $contents, $imports, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $offset = null;

        foreach ($imports as $existing) {
            if (strcmp($rank($existing[1][0]), $rank($module)) > 0) {
                $offset = $existing[0][1];

                break;
            }

            $offset = $existing[0][1] + strlen($existing[0][0]);
        }

        // With no import to sit beside, go below the file's header comment, not above it. A comment
        // the next line's declaration follows straight away is that declaration's doc, not a
        // header, so the import goes above it and never splits the two.
        $offset ??= preg_match('#^\s*/\*.*?\*/\n(?=[ \t]*\n)#s', $contents, $header) === 1 ? strlen($header[0]) : 0;

        return substr($contents, 0, $offset).$import."\n".substr($contents, $offset);
    }

    /**
     * Re-export a types file from the barrel the pages import `@/types` through.
     *
     * The barrel is one file every page generator and its tests rewrite. The read and the write
     * happen under one lock, so a --parallel run never reads a barrel another process has just
     * truncated and writes it back empty.
     */
    protected function registerTypesFile(string $slug): void
    {
        $barrel = resource_path('js/types/index.ts');
        $line = "export type * from './{$slug}';";
        $handle = fopen($barrel, 'c+');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            $contents = (string) stream_get_contents($handle);

            if (! str_contains($contents, $line)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, ltrim(rtrim($contents)."\n".$line."\n"));
                fflush($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Write a file from a stub, leaving an existing one untouched.
     *
     * @param  array<string, string>  $replacements
     */
    protected function writeFromStub(string $stub, string $path, string $label, array $replacements): ?string
    {
        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('%s [%s] already exists.', $label, $this->relativePath($path)));

            return null;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->render($stub, $replacements));

        $this->components->info(sprintf('%s [%s] created successfully.', $label, $this->relativePath($path)));

        return $path;
    }

    /**
     * Hand the written files to prettier, so the stubs need not predict where it wraps.
     *
     * @param  list<string>  $paths
     */
    protected function format(array $paths): void
    {
        $prettier = base_path('node_modules/.bin/prettier');

        if ($paths === []) {
            return;
        }

        if (! $this->files->exists($prettier)) {
            $this->warnings[] = 'prettier is not installed — run `npm run format` once it is.';

            return;
        }

        $result = Process::path(base_path())->run([$prettier, '--write', ...$paths]);

        if ($result->failed()) {
            $this->warnings[] = 'prettier could not format the generated files: '.trim($result->errorOutput());
        }
    }

    /**
     * A missing key renders as itself, so nothing else would point these out.
     */
    protected function warnAboutLangKeys(): void
    {
        $keys = array_values(array_unique($this->langKeys));

        foreach ($this->files->glob(lang_path('*.json')) as $file) {
            $translations = json_decode($this->files->get($file), true);
            $missing = array_values(array_diff($keys, array_keys(is_array($translations) ? $translations : [])));

            if ($missing !== []) {
                $this->warnings[] = sprintf('%s is missing %d keys: %s', $this->relativePath($file), count($missing), implode(', ', $missing));
            }
        }
    }

    protected function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
