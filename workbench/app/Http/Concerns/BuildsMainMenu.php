<?php

namespace App\Http\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Builds the main menu app/Http/Navigation.php lists (list-pages.md, "Menu"): one `item()` per
 * page, one `group()` per heading, handed to `menu()` in the order the menu reads.
 *
 * A group holds pages and never another group. It sits where its `group()` line sits, and an
 * entry joins it with `group: '{key}'` wherever its own line is. `menu()` leaves out what the
 * policy refuses the user, then any group left with nothing in it, so no one sees an empty
 * heading or dropdown. The one property read here is the `$user` Navigation promotes.
 *
 * @phpstan-type MenuItem array{title: string, href: string, icon: string, description?: string}
 * @phpstan-type MenuGroup array{title: string, icon: string, items: non-empty-list<MenuItem>}
 * @phpstan-type MenuEntry MenuItem|MenuGroup
 * @phpstan-type Declared array{title: string, href: string, icon: string, description?: string, group?: string}|array{key: string, title: string, icon: string}|null
 */
trait BuildsMainMenu
{
    /**
     * An entry: the translation key of its title, the name of the route it opens, the lucide
     * icon it shows (kebab-case, as lucide.dev names it) and, for a page about a model, the model
     * whose policy answers `viewAny` for the user. An entry without a model shows to everyone
     * signed in. `group` names the group it sits in, `description` the translation key of a
     * second line the header's dropdown shows under the title.
     *
     * @param  class-string|null  $model
     * @return array{title: string, href: string, icon: string, description?: string, group?: string}|null
     */
    private function item(string $title, string $route, string $icon, ?string $model = null, ?string $group = null, ?string $description = null): ?array
    {
        if ($model !== null && ! Gate::forUser($this->user)->allows('viewAny', $model)) {
            return null;
        }

        $item = ['title' => $title, 'href' => route($route, absolute: false), 'icon' => $icon];

        if ($description !== null) {
            $item['description'] = $description;
        }

        if ($group !== null) {
            $item['group'] = $group;
        }

        return $item;
    }

    /**
     * A group: the key its entries name, the translation key of its heading and its lucide icon.
     *
     * @return array{key: string, title: string, icon: string}
     */
    private function group(string $key, string $title, string $icon): array
    {
        return ['key' => $key, 'title' => $title, 'icon' => $icon];
    }

    /**
     * The menu as the client draws it: each entry in a group nested under it, in the order of
     * their lines. An entry naming a group no `group()` line declares stays at the top level,
     * where the `menu-group` check names it.
     *
     * @param  list<Declared>  $declared
     * @return list<MenuEntry>
     */
    private function menu(array $declared): array
    {
        $groups = [];

        foreach ($declared as $line) {
            if ($line !== null && array_key_exists('key', $line)) {
                $groups[$line['key']] ??= [];
            }
        }

        foreach ($declared as $line) {
            $group = $line === null || array_key_exists('key', $line) ? null : ($line['group'] ?? null);

            if ($line !== null && $group !== null && array_key_exists($group, $groups)) {
                $groups[$group][] = $this->link($line);
            }
        }

        $menu = [];
        $placed = [];

        foreach ($declared as $line) {
            if ($line === null) {
                continue;
            }

            if (! array_key_exists('key', $line)) {
                if (! array_key_exists($line['group'] ?? '', $groups)) {
                    $menu[] = $this->link($line);
                }

                continue;
            }

            $items = $groups[$line['key']];

            if (! isset($placed[$line['key']]) && $items !== []) {
                $menu[] = ['title' => $line['title'], 'icon' => $line['icon'], 'items' => $items];
            }

            $placed[$line['key']] = true;
        }

        return $menu;
    }

    /**
     * @param  array{title: string, href: string, icon: string, description?: string, group?: string}  $line
     * @return MenuItem
     */
    private function link(array $line): array
    {
        unset($line['group']);

        return $line;
    }
}
