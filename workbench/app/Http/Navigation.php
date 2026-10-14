<?php

namespace App\Http;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The main menu, which every page shares as the `menu` prop and the sidebar and the header both
 * draw through useMainNavItems() (list-pages.md, "Menu").
 *
 * One line per entry: the translation key of its title, the name of the route it opens, the
 * lucide icon it shows (kebab-case, as lucide.dev names it) and, for a page about a model, the
 * model whose policy answers `viewAny` for the user. An entry without a model shows to everyone
 * signed in. `kit:apply` writes a list page's entry above `// kit:menu`, so keep the marker as
 * the last line of the list, and move the lines into the order the menu should read.
 */
final class Navigation
{
    private function __construct(private readonly ?User $user) {}

    /**
     * @return list<array{title: string, href: string, icon: string}>
     */
    public static function for(?User $user): array
    {
        return (new self($user))->items();
    }

    /**
     * @return list<array{title: string, href: string, icon: string}>
     */
    private function items(): array
    {
        return array_values(array_filter([
            $this->item('common.dashboard', 'dashboard', 'layout-grid'),
            $this->item('audit-entries.title', 'audit-entries.index', 'scroll-text', AuditEntry::class),
            // kit:menu
        ]));
    }

    /**
     * @param  class-string|null  $model
     * @return array{title: string, href: string, icon: string}|null
     */
    private function item(string $title, string $route, string $icon, ?string $model = null): ?array
    {
        if ($model !== null && ! Gate::forUser($this->user)->allows('viewAny', $model)) {
            return null;
        }

        return ['title' => $title, 'href' => route($route, absolute: false), 'icon' => $icon];
    }
}
