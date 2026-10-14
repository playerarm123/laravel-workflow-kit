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
            ['title' => 'common.dashboard', 'route' => 'dashboard', 'icon' => 'layout-grid', 'model' => null],
            ['title' => 'audit-entries.title', 'route' => 'audit-entries.index', 'icon' => 'scroll-text', 'model' => 'App\Models\AuditEntry'],
            ['title' => 'merchants.title', 'route' => 'merchants.index', 'icon' => 'store', 'model' => 'App\Models\Merchant'],
            ['title' => 'crates.title', 'route' => 'crates.index', 'icon' => 'package', 'model' => 'App\Models\Crate'],
        ]);
    });

    it('writes the line it reads back', function () {
        $line = StructureMenu::line('crates.title', 'crates.index', 'package', 'App\Models\Crate');

        expect($line)->toBe("\$this->item('crates.title', 'crates.index', 'package', \\App\\Models\\Crate::class),")
            ->and(StructureMenu::parse("<?php\n{$line}\n"))->toBe([['title' => 'crates.title', 'route' => 'crates.index', 'icon' => 'package', 'model' => 'App\Models\Crate']])
            ->and(StructureMenu::line('reports.title', 'reports.index', 'chart-bar', null))->toBe("\$this->item('reports.title', 'reports.index', 'chart-bar'),");
    });

    it('finds the entry a route opens on the project it runs on', function () {
        $menu = new StructureMenu(base_path());

        expect($menu->exists())->toBeTrue()
            ->and($menu->entryFor('audit-entries.index'))->toBe(['title' => 'audit-entries.title', 'route' => 'audit-entries.index', 'icon' => 'scroll-text', 'model' => 'App\Models\AuditEntry'])
            ->and($menu->entryFor('nowhere.index'))->toBeNull();
    });
});
