<?php

use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMenu;

/**
 * The main menu's entries as app/Http/Navigation.php writes them, in the forms a project holds:
 * the scaffold, a line kit:apply wrote, and the same line after Pint imported its model.
 */
describe('StructureMenu', function () {
    it('reads every entry, its model in full or by its short name', function () {
        $code = <<<'PHP'
            <?php

            namespace App\Http;

            use App\Models\AuditEntry;
            use App\Models\Merchant as Shop;

            final class Navigation
            {
                private function items(): array
                {
                    return array_values(array_filter([
                        $this->item('common.dashboard', 'dashboard', 'layout-grid'),
                        $this->item('audit-entries.title', 'audit-entries.index', 'scroll-text', AuditEntry::class),
                        $this->item('merchants.title', 'merchants.index', 'store', Shop::class),
                        $this->item('crates.title', 'crates.index', 'package', \App\Models\Crate::class),
                        // $this->item('old.title', 'old.index', 'trash', \App\Models\Old::class),
                        // kit:menu
                    ]));
                }
            }
            PHP;

        expect(StructureMenu::parse($code))->toBe([
            ['title' => 'common.dashboard', 'route' => 'dashboard', 'icon' => 'layout-grid', 'model' => null, 'group' => null, 'description' => null],
            ['title' => 'audit-entries.title', 'route' => 'audit-entries.index', 'icon' => 'scroll-text', 'model' => 'App\Models\AuditEntry', 'group' => null, 'description' => null],
            ['title' => 'merchants.title', 'route' => 'merchants.index', 'icon' => 'store', 'model' => 'App\Models\Merchant', 'group' => null, 'description' => null],
            ['title' => 'crates.title', 'route' => 'crates.index', 'icon' => 'package', 'model' => 'App\Models\Crate', 'group' => null, 'description' => null],
        ]);
    });

    it('writes the line it reads back', function () {
        $line = StructureMenu::line('crates.title', 'crates.index', 'package', 'App\Models\Crate');

        expect($line)->toBe("\$this->item('crates.title', 'crates.index', 'package', \\App\\Models\\Crate::class),")
            ->and(StructureMenu::parse("<?php\n{$line}\n"))->toBe([['title' => 'crates.title', 'route' => 'crates.index', 'icon' => 'package', 'model' => 'App\Models\Crate', 'group' => null, 'description' => null]])
            ->and(StructureMenu::line('reports.title', 'reports.index', 'chart-bar', null))->toBe("\$this->item('reports.title', 'reports.index', 'chart-bar'),");
    });

    it('reads the group an entry sits in, its description, and the groups the menu declares', function () {
        $code = <<<'PHP'
            <?php

            namespace App\Http;

            final class Navigation
            {
                use BuildsMainMenu;

                private function items(): array
                {
                    return $this->menu([
                        $this->group('finance', 'nav.finance', 'receipt'),
                        $this->item('invoices.title', 'invoices.index', 'wallet', \App\Models\Invoice::class, group: 'finance', description: 'invoices.menu_description'),
                        $this->item(
                            'refunds.title',
                            'refunds.index',
                            'undo-2',
                            description: "refunds.menu_description",
                            group: 'finance',
                        ),
                        $this->item('reports.title', 'reports.index', 'chart-bar', null, 'reports'),
                        // $this->group('old', 'nav.old', 'trash'),
                        // kit:menu
                    ]);
                }
            }
            PHP;

        expect(StructureMenu::parse($code))->toBe([
            ['title' => 'invoices.title', 'route' => 'invoices.index', 'icon' => 'wallet', 'model' => 'App\Models\Invoice', 'group' => 'finance', 'description' => 'invoices.menu_description'],
            ['title' => 'refunds.title', 'route' => 'refunds.index', 'icon' => 'undo-2', 'model' => null, 'group' => 'finance', 'description' => 'refunds.menu_description'],
            ['title' => 'reports.title', 'route' => 'reports.index', 'icon' => 'chart-bar', 'model' => null, 'group' => 'reports', 'description' => null],
        ])->and(StructureMenu::parseGroups($code))->toBe([
            ['key' => 'finance', 'title' => 'nav.finance', 'icon' => 'receipt'],
        ]);
    });

    it('writes a line in its group, and reads it back', function () {
        $line = StructureMenu::line('invoices.title', 'invoices.index', 'wallet', 'App\Models\Invoice', 'finance', 'invoices.menu_description');

        expect($line)->toBe("\$this->item('invoices.title', 'invoices.index', 'wallet', \\App\\Models\\Invoice::class, group: 'finance', description: 'invoices.menu_description'),")
            ->and(StructureMenu::parse("<?php\n{$line}\n")[0])->toMatchArray(['model' => 'App\Models\Invoice', 'group' => 'finance', 'description' => 'invoices.menu_description'])
            ->and(StructureMenu::line('reports.title', 'reports.index', 'chart-bar', null, 'insights'))->toBe("\$this->item('reports.title', 'reports.index', 'chart-bar', group: 'insights'),");
    });

    it('finds the entry a route opens on the project it runs on', function () {
        $menu = new StructureMenu(base_path());

        expect($menu->exists())->toBeTrue()
            ->and($menu->supportsGroups())->toBeTrue()
            ->and($menu->groups())->toBe([])
            ->and($menu->entryFor('audit-entries.index'))->toBe(['title' => 'audit-entries.title', 'route' => 'audit-entries.index', 'icon' => 'scroll-text', 'model' => 'App\Models\AuditEntry', 'group' => null, 'description' => null])
            ->and($menu->entryFor('nowhere.index'))->toBeNull();
    });
});
