<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

/**
 * Reads the main menu's entries from app/Http/Navigation.php (list-pages.md, "Menu"), for the
 * planner, `kit:import` and the checks.
 *
 * An entry is one `$this->item('title', 'route', 'icon', Model::class)` call. The model is read
 * as written: in full with a leading backslash, as `kit:apply` writes it, or by its short name
 * through the file's `use` lines, as Pint leaves it.
 */
final class StructureMenu
{
    public const string FILE = 'app/Http/Navigation.php';

    private const string ITEM = '/->item\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]+)\3\s*,\s*([\'"])([^\'"]+)\5\s*(?:,\s*(\\\\?[\w\\\\]+)::class\s*)?,?\s*\)/';

    public function __construct(private readonly string $root) {}

    public function exists(): bool
    {
        return is_file($this->root.'/'.self::FILE);
    }

    /**
     * @return list<array{title: string, route: string, icon: string, model: string|null}>
     */
    public function entries(): array
    {
        return $this->exists() ? self::parse(StructureFiles::text($this->root.'/'.self::FILE)) : [];
    }

    /**
     * The entry that opens a route, or null.
     *
     * @return array{title: string, route: string, icon: string, model: string|null}|null
     */
    public function entryFor(string $route): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['route'] === $route) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The line `kit:apply` writes above `// kit:menu` for an entry.
     */
    public static function line(string $title, string $route, string $icon, ?string $model): string
    {
        return $model === null
            ? sprintf("\$this->item('%s', '%s', '%s'),", $title, $route, $icon)
            : sprintf("\$this->item('%s', '%s', '%s', \\%s::class),", $title, $route, $icon, ltrim($model, '\\'));
    }

    /**
     * @return list<array{title: string, route: string, icon: string, model: string|null}>
     */
    public static function parse(string $code): array
    {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $code);
        preg_match_all(self::ITEM, $code, $items, PREG_SET_ORDER);
        $uses = self::uses($code);
        $entries = [];

        foreach ($items as $item) {
            $model = ($item[7] ?? '') === '' ? null : self::resolve($item[7], $uses, $code);
            $entries[] = ['title' => $item[2], 'route' => $item[4], 'icon' => $item[6], 'model' => $model];
        }

        return $entries;
    }

    /**
     * @return array<string, string> short name => full class name
     */
    private static function uses(string $code): array
    {
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $lines, PREG_SET_ORDER);
        $uses = [];

        foreach ($lines as $line) {
            $uses[($line[2] ?? '') !== '' ? $line[2] : substr($line[1], (int) strrpos('\\'.$line[1], '\\'))] = $line[1];
        }

        return $uses;
    }

    /**
     * @param  array<string, string>  $uses
     */
    private static function resolve(string $name, array $uses, string $code): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = explode('\\', $name)[0];

        if (isset($uses[$first])) {
            return $uses[$first].substr($name, strlen($first));
        }

        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $match) === 1 ? $match[1].'\\' : '';

        return $namespace.$name;
    }
}
