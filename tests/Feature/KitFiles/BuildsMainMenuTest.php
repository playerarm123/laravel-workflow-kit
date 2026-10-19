<?php

use App\Http\Concerns\BuildsMainMenu;
use App\Models\AuditEntry;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The kit's `app/Http/Concerns/BuildsMainMenu.php`, as Navigation.php uses it: the closure runs
 * inside a class that promotes `$user`, so it calls item(), group() and menu() as Navigation does.
 *
 * @return list<array<string, mixed>>
 */
function kitMenu(Closure $lines): array
{
    $navigation = new class(null)
    {
        use BuildsMainMenu;

        public function __construct(private readonly ?User $user) {}
    };

    return $lines->call($navigation);
}

beforeEach(function () {
    foreach (['dashboard', 'products.index', 'audit-entries.index', 'reports.index'] as $name) {
        Route::get('/'.str_replace('.index', '', $name), fn () => '')->name($name);
    }

    Route::getRoutes()->refreshNameLookups();

    $shown = new class
    {
        public function viewAny(?User $user): bool
        {
            return true;
        }
    };
    $hidden = new class
    {
        public function viewAny(?User $user): bool
        {
            return false;
        }
    };

    Gate::policy(Product::class, $shown::class);
    Gate::policy(AuditEntry::class, $hidden::class);
});

it('keeps a menu with no group as the flat list it always was', function () {
    expect(kitMenu(fn () => $this->menu([
        $this->item('common.dashboard', 'dashboard', 'layout-grid'),
        $this->item('products.title', 'products.index', 'package', Product::class),
        $this->item('audit-entries.title', 'audit-entries.index', 'scroll-text', AuditEntry::class),
    ])))->toBe([
        ['title' => 'common.dashboard', 'href' => '/dashboard', 'icon' => 'layout-grid'],
        ['title' => 'products.title', 'href' => '/products', 'icon' => 'package'],
    ]);
});

it('nests the entries of a group under it, where its group() line sits', function () {
    expect(kitMenu(fn () => $this->menu([
        $this->item('common.dashboard', 'dashboard', 'layout-grid'),
        $this->group('stock', 'nav.stock', 'boxes'),
        $this->item('reports.title', 'reports.index', 'chart-bar'),
        $this->item('products.title', 'products.index', 'package', Product::class, group: 'stock', description: 'products.menu_description'),
    ])))->toBe([
        ['title' => 'common.dashboard', 'href' => '/dashboard', 'icon' => 'layout-grid'],
        ['title' => 'nav.stock', 'icon' => 'boxes', 'items' => [
            ['title' => 'products.title', 'href' => '/products', 'icon' => 'package', 'description' => 'products.menu_description'],
        ]],
        ['title' => 'reports.title', 'href' => '/reports', 'icon' => 'chart-bar'],
    ]);
});

it('takes an entry into its group whether its line comes before or after the group', function () {
    $menu = kitMenu(fn () => $this->menu([
        $this->item('reports.title', 'reports.index', 'chart-bar', group: 'stock'),
        $this->group('stock', 'nav.stock', 'boxes'),
        $this->item('products.title', 'products.index', 'package', Product::class, group: 'stock'),
    ]));

    expect($menu)->toHaveCount(1)
        ->and(array_column($menu[0]['items'], 'href'))->toBe(['/reports', '/products']);
});

it('leaves out a group the policy refuses every entry of', function () {
    expect(kitMenu(fn () => $this->menu([
        $this->item('common.dashboard', 'dashboard', 'layout-grid'),
        $this->group('system', 'nav.system', 'settings'),
        $this->item('audit-entries.title', 'audit-entries.index', 'scroll-text', AuditEntry::class, group: 'system'),
        $this->group('empty', 'nav.empty', 'folder'),
    ])))->toBe([
        ['title' => 'common.dashboard', 'href' => '/dashboard', 'icon' => 'layout-grid'],
    ]);
});

it('keeps an entry whose group no line declares at the top level', function () {
    expect(kitMenu(fn () => $this->menu([
        $this->item('products.title', 'products.index', 'package', group: 'nowhere'),
    ])))->toBe([
        ['title' => 'products.title', 'href' => '/products', 'icon' => 'package'],
    ]);
});

it('draws a group declared twice once, where its first line sits', function () {
    $menu = kitMenu(fn () => $this->menu([
        $this->group('stock', 'nav.stock', 'boxes'),
        $this->item('common.dashboard', 'dashboard', 'layout-grid'),
        $this->group('stock', 'nav.stock', 'boxes'),
        $this->item('products.title', 'products.index', 'package', group: 'stock'),
    ]));

    expect(array_column($menu, 'title'))->toBe(['nav.stock', 'common.dashboard']);
});
