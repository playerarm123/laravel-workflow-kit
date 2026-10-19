<?php

namespace App\Http;

use App\Http\Concerns\BuildsMainMenu;
use App\Models\AuditEntry;
use App\Models\User;

/**
 * The main menu, which every page shares as the `menu` prop and the sidebar and the header both
 * draw through useMainNavItems() (list-pages.md, "Menu").
 *
 * One line per entry: the translation key of its title, the name of the route it opens, the
 * lucide icon it shows (kebab-case, as lucide.dev names it) and, for a page about a model, the
 * model whose policy answers `viewAny` for the user. An entry without a model shows to everyone
 * signed in. To gather pages under a heading, declare the group once with
 * `$this->group('{key}', '{title key}', '{lucide icon}')` where it should sit, and give each of
 * its entries `group: '{key}'`. A group holds pages only, never another group, and shows only
 * while the user may open one of them. `kit:apply` writes a list page's entry above
 * `// kit:menu`, so keep the marker as the last line of the list, and move the lines into the
 * order the menu should read.
 *
 * @phpstan-import-type MenuEntry from BuildsMainMenu
 */
final class Navigation
{
    use BuildsMainMenu;

    private function __construct(private readonly ?User $user) {}

    /**
     * @return list<MenuEntry>
     */
    public static function for(?User $user): array
    {
        return (new self($user))->items();
    }

    /**
     * @return list<MenuEntry>
     */
    private function items(): array
    {
        return $this->menu([
            $this->item('common.dashboard', 'dashboard', 'layout-grid'),
            $this->item('audit-entries.title', 'audit-entries.index', 'scroll-text', AuditEntry::class),
            // kit:menu
        ]);
    }
}
