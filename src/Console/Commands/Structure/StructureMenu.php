<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

/**
 * Reads the main menu's entries from app/Http/Navigation.php (list-pages.md, "Menu"), for the
 * planner, `kit:import` and the checks.
 *
 * An entry is one `$this->item('title', 'route', 'icon', Model::class)` call, which may name the
 * group it sits in, a description and the policy's ability it asks as `group: '{key}'`,
 * `description: '{key}'` and `ability: '{method}'`; an entry that names none asks `viewAny`. A group is
 * one `$this->group('{key}', 'title', 'icon')` call. The model is read as written: in full with
 * a leading backslash, as `kit:apply` writes it, or by its short name through the file's `use`
 * lines, as Pint leaves it.
 *
 * @phpstan-type MenuLine array{title: string, route: string, icon: string, model: string|null, group: string|null, description: string|null, ability: string}
 * @phpstan-type MenuGroupLine array{key: string, title: string, icon: string}
 */
final class StructureMenu
{
    public const string FILE = 'app/Http/Navigation.php';

    /** The ability an entry asks when it names none. */
    public const string DEFAULT_ABILITY = 'viewAny';

    /** The name of a policy's ability: a public method, camelCase. */
    public const string ABILITY = '/^[a-z][A-Za-z0-9]*$/';

    /** A call's arguments: none of them holds a parenthesis. */
    private const string ITEM = '/->item\(([^()]*)\)/';

    private const string GROUP = '/->group\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]+)\3\s*,\s*([\'"])([^\'"]+)\5\s*,?\s*\)/';

    /** One argument: its name when it has one, then a string, a `Model::class` or null. */
    private const string ARGUMENT = '/\G\s*(?:(\w+)\s*:\s*)?(?:([\'"])([^\'"]*)\2|(\\\\?[\w\\\\]+)::class|(null))\s*(?:,|$)/';

    /** The names item() gives its arguments, in their order. */
    private const array PARAMETERS = ['title', 'route', 'icon', 'model', 'group', 'description', 'ability'];

    public function __construct(private readonly string $root) {}

    public function exists(): bool
    {
        return is_file($this->root.'/'.self::FILE);
    }

    /**
     * @return list<MenuLine>
     */
    public function entries(): array
    {
        return $this->exists() ? self::parse($this->code()) : [];
    }

    /**
     * The groups the menu declares, in their order.
     *
     * @return list<MenuGroupLine>
     */
    public function groups(): array
    {
        return $this->exists() ? self::parseGroups($this->code()) : [];
    }

    /**
     * Whether the menu can take groups: a Navigation.php from before them has no `group()` and
     * no `group:` on its `item()`, until kit:setup brings it onto BuildsMainMenu.
     */
    public function supportsGroups(): bool
    {
        return $this->exists() && self::groupsIn($this->code());
    }

    /**
     * Whether a menu's code can take groups, as supportsGroups() asks of the project's.
     */
    public static function groupsIn(string $code): bool
    {
        return preg_match('/\bBuildsMainMenu\b|function group\(/', $code) === 1;
    }

    /**
     * The entry that opens a route, or null.
     *
     * @return MenuLine|null
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
    public static function line(string $title, string $route, string $icon, ?string $model, ?string $group = null, ?string $description = null, ?string $ability = null): string
    {
        $arguments = [sprintf("'%s'", $title), sprintf("'%s'", $route), sprintf("'%s'", $icon)];

        if ($model !== null) {
            $arguments[] = sprintf('\\%s::class', ltrim($model, '\\'));
        }

        if ($group !== null) {
            $arguments[] = sprintf("group: '%s'", $group);
        }

        if ($description !== null) {
            $arguments[] = sprintf("description: '%s'", $description);
        }

        if ($model !== null && $ability !== null && $ability !== self::DEFAULT_ABILITY) {
            $arguments[] = sprintf("ability: '%s'", $ability);
        }

        return sprintf('$this->item(%s),', implode(', ', $arguments));
    }

    /**
     * @return list<MenuLine>
     */
    public static function parse(string $code): array
    {
        $code = self::withoutComments($code);
        preg_match_all(self::ITEM, $code, $items, PREG_SET_ORDER);
        $uses = self::uses($code);
        $entries = [];

        foreach ($items as $item) {
            $arguments = self::arguments($item[1]);

            if ($arguments === null || ! is_string($arguments['title'] ?? null) || ! is_string($arguments['route'] ?? null) || ! is_string($arguments['icon'] ?? null)) {
                continue;
            }

            $model = $arguments['model'] ?? null;
            $entries[] = [
                'title' => $arguments['title'],
                'route' => $arguments['route'],
                'icon' => $arguments['icon'],
                'model' => is_array($model) ? self::resolve($model['class'], $uses, $code) : null,
                'group' => is_string($arguments['group'] ?? null) ? $arguments['group'] : null,
                'description' => is_string($arguments['description'] ?? null) ? $arguments['description'] : null,
                'ability' => is_string($arguments['ability'] ?? null) && $arguments['ability'] !== '' ? $arguments['ability'] : self::DEFAULT_ABILITY,
            ];
        }

        return $entries;
    }

    /**
     * @return list<MenuGroupLine>
     */
    public static function parseGroups(string $code): array
    {
        preg_match_all(self::GROUP, self::withoutComments($code), $calls, PREG_SET_ORDER);

        return array_map(fn (array $call): array => ['key' => $call[2], 'title' => $call[4], 'icon' => $call[6]], $calls);
    }

    private function code(): string
    {
        return StructureFiles::text($this->root.'/'.self::FILE);
    }

    private static function withoutComments(string $code): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $code);
    }

    /**
     * An item() call's arguments by the name of the parameter each fills: a string as itself, a
     * `Model::class` as `['class' => …]`, null as null. Null when one of them is anything else.
     *
     * @return array<string, string|array{class: string}|null>|null
     */
    private static function arguments(string $list): ?array
    {
        $list = trim($list);
        $arguments = [];
        $at = 0;
        $position = 0;

        while ($at < strlen($list)) {
            if (preg_match(self::ARGUMENT, $list, $match, 0, $at) !== 1) {
                return null;
            }

            $at += strlen($match[0]);
            $name = ($match[1] ?? '') !== '' ? $match[1] : (self::PARAMETERS[$position++] ?? null);

            if ($name === null) {
                return null;
            }

            $arguments[$name] = match (true) {
                ($match[4] ?? '') !== '' => ['class' => $match[4]],
                ($match[5] ?? '') !== '' => null,
                default => $match[3] ?? '',
            };
        }

        return $arguments;
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
