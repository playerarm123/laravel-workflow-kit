<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

/**
 * The two places `kit:apply` writes into a project's own files (structure.md): the line marked
 * `// kit:bindings`, inside the `$bindings` of one service provider under app/, and the line
 * marked `// kit:routes`, inside the route group under routes/ that the new pages belong in.
 *
 * A new line goes right above its marker with the marker's indent, so the marker stays the last
 * line of its list. Class names are written in full with a leading backslash, so no `use` line
 * has to change. Nothing is written when the line is already there, when no file holds the
 * marker, or when more than one does, because then the project has not said where it goes.
 */
final class StructureMarkers
{
    public const string BINDINGS = '// kit:bindings';

    public const string ROUTES = '// kit:routes';

    public const string WRITTEN = 'written';

    public const string PRESENT = 'present';

    public const string NO_MARKER = 'no-marker';

    public const string MANY_MARKERS = 'many-markers';

    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * Writes a line above its marker, and says what happened.
     */
    public function insert(string $marker, string $line): string
    {
        $files = $this->filesWith($marker);

        if (count($files) > 1) {
            return self::MANY_MARKERS;
        }

        if ($this->contains($marker, $line)) {
            return self::PRESENT;
        }

        if ($files === []) {
            return self::NO_MARKER;
        }

        $contents = StructureFiles::text($files[0]);
        $lines = explode("\n", $contents);
        $markers = array_keys(array_filter($lines, fn (string $text): bool => trim($text) === $marker));

        if (count($markers) !== 1) {
            return self::MANY_MARKERS;
        }

        $at = $markers[0];
        $indent = substr($lines[$at], 0, strlen($lines[$at]) - strlen(ltrim($lines[$at])));
        array_splice($lines, $at, 0, [$indent.trim($line)]);

        file_put_contents($files[0], implode("\n", $lines));

        return self::WRITTEN;
    }

    /**
     * Whether any file the marker may sit in already holds the text.
     */
    public function contains(string $marker, string $text): bool
    {
        foreach ($this->candidates($marker) as $file) {
            if (str_contains(StructureFiles::text($file), trim($text))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any file the marker may sit in matches the pattern.
     */
    public function matches(string $marker, string $pattern): bool
    {
        foreach ($this->candidates($marker) as $file) {
            if (preg_match($pattern, StructureFiles::text($file)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The path of the file that holds a marker, from the project root, or null.
     */
    public function fileOf(string $marker): ?string
    {
        $files = $this->filesWith($marker);

        return count($files) === 1 ? substr($files[0], strlen($this->root) + 1) : null;
    }

    /**
     * @return list<string>
     */
    private function filesWith(string $marker): array
    {
        return array_values(array_filter(
            $this->candidates($marker),
            fn (string $file): bool => array_filter(
                explode("\n", StructureFiles::text($file)),
                fn (string $text): bool => trim($text) === $marker,
            ) !== [],
        ));
    }

    /**
     * The files a marker may sit in: providers under app/ for bindings, route files for routes.
     *
     * @return list<string>
     */
    private function candidates(string $marker): array
    {
        if ($marker === self::ROUTES) {
            return glob($this->root.'/routes/*.php') ?: [];
        }

        $directory = $this->root.'/app';

        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_filter(
            StructureSwapper::phpFilesUnder($directory),
            fn (string $file): bool => str_ends_with($file, 'ServiceProvider.php'),
        ));
    }
}
