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

    public const string WIDENED = 'widened';

    public const string BY_HAND = 'by-hand';

    /**
     * The methods `Route::resource()` registers when nothing narrows it.
     */
    public const array RESOURCE_METHODS = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * Writes a line above its marker, and says what happened.
     */
    public function insert(string $marker, string $line): string
    {
        if ($marker === self::ROUTES && preg_match('/Route::resource\\(.*\\b(\\w+Controller)::class\\)->only\\(\\[(.*)\\]\\);/', $line, $match) === 1) {
            $widened = $this->widenResource($match[1], $this->names($match[2]));

            if ($widened !== null) {
                return $widened;
            }
        }

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
     * The methods a route file registers for a resource controller through `Route::resource()`:
     * every resource method, or those its `only([...])` names, less those its `except([...])`
     * names. Null when no route file registers it that way.
     *
     * @return list<string>|null
     */
    public function resourceMethods(string $controller): ?array
    {
        foreach ($this->candidates(self::ROUTES) as $file) {
            $statement = $this->resourceStatement(StructureFiles::text($file), $controller);

            if ($statement !== null) {
                return $this->registered($statement);
            }
        }

        return null;
    }

    /**
     * Widens the `only([...])` of a resource a route file already registers so it covers every
     * method named, or says the line goes in by hand when an `except([...])` holds one back.
     * Null when no route file registers the resource yet, so the line goes above its marker.
     *
     * @param  list<string>  $methods
     */
    private function widenResource(string $controller, array $methods): ?string
    {
        foreach ($this->candidates(self::ROUTES) as $file) {
            $contents = StructureFiles::text($file);
            $statement = $this->resourceStatement($contents, $controller);

            if ($statement === null) {
                continue;
            }

            $missing = array_values(array_diff($methods, $this->registered($statement)));

            if ($missing === []) {
                return self::PRESENT;
            }

            if (preg_match('/->only\(\[(.*?)\]\)/s', $statement, $only) !== 1 || str_contains($statement, '->except(')) {
                return self::BY_HAND;
            }

            $names = array_values(array_unique([...$this->names($only[1]), ...$missing]));
            usort($names, fn (string $a, string $b): int => $this->rank($a) <=> $this->rank($b));
            $widened = str_replace($only[0], "->only(['".implode("', '", $names)."'])", $statement);

            file_put_contents($file, str_replace($statement, $widened, $contents));

            return self::WIDENED;
        }

        return null;
    }

    /**
     * The whole `Route::resource(...)...;` statement that registers the controller, or null.
     */
    private function resourceStatement(string $contents, string $controller): ?string
    {
        $pattern = '/Route::resource\([^;]*?\b'.preg_quote($controller, '/').'::class[^;]*;/s';

        return preg_match($pattern, $contents, $match) === 1 ? $match[0] : null;
    }

    /**
     * @return list<string>
     */
    private function registered(string $statement): array
    {
        $methods = preg_match('/->only\(\[(.*?)\]\)/s', $statement, $only) === 1 ? $this->names($only[1]) : self::RESOURCE_METHODS;

        if (preg_match('/->except\(\[(.*?)\]\)/s', $statement, $except) === 1) {
            $methods = array_values(array_diff($methods, $this->names($except[1])));
        }

        return $methods;
    }

    /**
     * The quoted names of a PHP array literal's body.
     *
     * @return list<string>
     */
    private function names(string $body): array
    {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $body, $matches);

        return $matches[1];
    }

    private function rank(string $method): int
    {
        $at = array_search($method, self::RESOURCE_METHODS, true);

        return $at === false ? count(self::RESOURCE_METHODS) : $at;
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
