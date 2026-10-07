<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

/** The Architecture rule helpers (ruleTsTypeKeys…). */
require_once dirname(__DIR__, 3).'/Architecture/Support/rules.php';

/**
 * This file's scratch folder must not match any other generator test file's, or --parallel
 * runs delete each other's files midway.
 */
const LIST_PAGE_CONTEXT = 'SamplingShelving';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeListPage(array $arguments = []): PendingCommand
{
    return test()->artisan('make:list-page', ['name' => 'ListSamplingCartons', '--domain' => LIST_PAGE_CONTEXT, ...$arguments]);
}

function listPageFixturePath(string $relative): string
{
    return app_path('Application/'.LIST_PAGE_CONTEXT."/{$relative}.php");
}

/**
 * The generated files, relative to the project root as the rules helpers read them.
 *
 * @return array{types: string, toolbar: string, card: string, page: string, browser: string}
 */
function listPageGenerated(): array
{
    return [
        'types' => 'resources/js/types/sampling-carton.ts',
        'toolbar' => 'resources/js/components/sampling-carton/table-toolbar.tsx',
        'card' => 'resources/js/components/sampling-carton/card.tsx',
        'page' => 'resources/js/pages/sampling-cartons/index.tsx',
        'browser' => 'tests/Browser/SamplingCartons/IndexTest.php',
    ];
}

/**
 * The row, criteria and enum a filled-in `make:use-case ListSamplingCartons --query` would hold.
 *
 * A class loads once per process, so a case that needs a different row names a different
 * item instead of rewriting the carton's — the next case would read the stale class.
 */
function writeListPageFixtures(string $item = 'SamplingCarton', ?string $rowDocblock = null): void
{
    $namespace = 'App\\Application\\'.LIST_PAGE_CONTEXT;

    $rowDocblock ??= <<<'PHP'
    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     weight: int,
     *     fragile: bool,
     *     note: string|null,
     *     tags: list<string>,
     * }
     */
PHP;

    $fixtures = [];

    $fixtures['SamplingCartonStatus'] = <<<PHP
<?php

namespace {$namespace};

enum SamplingCartonStatus: string
{
    case Packed = 'packed';
    case Shipped = 'shipped';
}
PHP;

    $fixtures['UseCases/ListSamplingCartons/SamplingCartonListRow'] = <<<PHP
<?php

namespace {$namespace}\\UseCases\\ListSamplingCartons;

final class SamplingCartonListRow
{
{$rowDocblock}
    public function toArray(): array
    {
        return ['id' => '', 'label' => '', 'weight' => 0, 'fragile' => false, 'note' => null, 'tags' => []];
    }
}
PHP;

    $fixtures['UseCases/ListSamplingCartons/ListSamplingCartonsCriteria'] = <<<PHP
<?php

namespace {$namespace}\\UseCases\\ListSamplingCartons;

use {$namespace}\\SamplingCartonStatus;

final class ListSamplingCartonsCriteria
{
    public function __construct(
        public readonly ?SamplingCartonStatus \$status,
        public readonly string \$carrier,
        public readonly ?string \$shippedFrom,
        public readonly ?string \$shippedTo,
    ) {}

    /**
     * @return array{search: string, status: string|null, carrier: string|null, shipped_from: string|null, shipped_to: string|null, created_from: string|null, created_to: string|null}
     */
    public function toFilters(): array
    {
        return ['search' => '', 'status' => null, 'carrier' => null, 'shipped_from' => null, 'shipped_to' => null, 'created_from' => null, 'created_to' => null];
    }
}
PHP;

    foreach ($fixtures as $relative => $contents) {
        $path = listPageFixturePath(str_replace('SamplingCarton', $item, $relative));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, str_replace('SamplingCarton', $item, $contents));
    }
}

function cleanListPageScratch(): void
{
    File::deleteDirectory(app_path('Application/'.LIST_PAGE_CONTEXT));
    File::delete(array_map(base_path(...), listPageGenerated()));
    File::deleteDirectory(resource_path('js/components/sampling-carton'));
    File::deleteDirectory(resource_path('js/pages/sampling-cartons'));
    File::deleteDirectory(base_path('tests/Browser/SamplingCartons'));

    rewriteSharedFile(resource_path('js/types/index.ts'), fn (string $barrel): string => str_replace("export type * from './sampling-carton';\n", '', $barrel));
}

beforeEach(function () {
    cleanListPageScratch();
    writeListPageFixtures();
});

afterEach(function () {
    cleanListPageScratch();
});

it('fails and points at make:use-case when the list has no row yet', function () {
    runMakeListPage(['name' => 'ListCrates'])
        ->expectsOutputToContain('make:use-case ListCrates')
        ->assertFailed();

    expect(File::exists(resource_path('js/pages/crates/index.tsx')))->toBeFalse();
});

it('fails when the row does not describe toArray() with an array shape', function () {
    writeListPageFixtures(item: 'SamplingBin', rowDocblock: '');

    runMakeListPage(['name' => 'ListSamplingBins'])
        ->expectsOutputToContain('needs a `@return array{…}` docblock')
        ->assertFailed();

    expect(File::exists(resource_path('js/pages/sampling-bins/index.tsx')))->toBeFalse();
});

it('mirrors the row key for key with the types JSON carries', function () {
    runMakeListPage()
        ->expectsOutputToContain('SamplingCartonListRow::toArray() key "tags" is `list<string>`')
        ->assertSuccessful();

    $types = File::get(base_path(listPageGenerated()['types']));

    expect($types)
        ->toContain('@see App\Application\SamplingShelving\UseCases\ListSamplingCartons\SamplingCartonListRow::toArray()')
        ->toContain('id: string;')
        ->toContain('weight: number;')
        ->toContain('fragile: boolean;')
        ->toContain('note: string | null;')
        ->toContain('tags: unknown;')
        ->and(ruleTsTypeKeys(listPageGenerated()['types'], 'SamplingCartonRow'))
        ->toBe(ruleArrayKeysOf('app/Application/SamplingShelving/UseCases/ListSamplingCartons/SamplingCartonListRow.php', 'toArray'));
});

it('mirrors the filters with only the keys DtFilters does not carry', function () {
    runMakeListPage()->assertSuccessful();

    $types = File::get(base_path(listPageGenerated()['types']));

    expect($types)
        ->toContain('@see App\Application\SamplingShelving\UseCases\ListSamplingCartons\ListSamplingCartonsCriteria::toFilters()')
        ->toContain('export type SamplingCartonFilters = DtFilters & {')
        ->toContain('export type SamplingCartonsQuery = SamplingCartonFilters & DtQuery;')
        ->toContain("import type { DtQuery } from '@/hooks/use-data-table';")
        ->toContain("import type { DtFilters } from './data-table';")
        ->not->toContain('    search:')
        ->and(ruleTsResolvedTypeKeys([...ruleSourceFiles('resources/js/types', ['ts']), listPageGenerated()['types']], 'SamplingCartonFilters'))
        ->toEqualCanonicalizing(['search', 'status', 'carrier', 'shipped_from', 'shipped_to', 'created_from', 'created_to']);
});

it('offers enum cases, date range pairs and leaves other filters to a human', function () {
    runMakeListPage()
        ->expectsOutputToContain('Filter "carrier" is not a backed enum')
        ->assertSuccessful();

    expect(File::get(base_path(listPageGenerated()['toolbar'])))
        ->toContain('export function SamplingCartonTableToolbar({ dt }: Props)')
        ->toContain('const FIELDS: DtFilterField<SamplingCartonsQuery>[] = [')
        ->toContain("{ value: 'packed', label: 'sampling-cartons.status.packed' }")
        ->toContain("{ value: 'shipped', label: 'sampling-cartons.status.shipped' }")
        ->toContain("type: 'date-range'")
        ->toContain("fromKey: 'shipped_from'")
        ->toContain("toKey: 'shipped_to'")
        ->toContain("label: 'sampling-cartons.filter_shipped'")
        ->toContain("{ key: 'carrier', label: 'sampling-cartons.filter_carrier', options: [] }")
        ->not->toContain('useState')
        ->not->toContain('router');
});

it('writes a table page with one column per row key and the one visit', function () {
    runMakeListPage()->assertSuccessful();

    $page = File::get(base_path(listPageGenerated()['page']));

    expect($page)
        ->toMatch('/\buseDataTable\s*\(/')
        ->toMatch('/<DataTable\b/')
        ->toContain('samplingCartons: Paginated<SamplingCartonRow>;')
        ->toContain('filters: SamplingCartonFilters;')
        ->toContain("import { index } from '@/routes/sampling-cartons';")
        ->toContain("import { SamplingCartonTableToolbar } from '@/components/sampling-carton/table-toolbar';")
        ->toContain("header: 'sampling-cartons.weight'")
        ->toContain("header: 'sampling-cartons.tags'")
        ->not->toContain("accessor('id'")
        ->toContain('replace: true')
        ->toContain('per_page: samplingCartons.per_page')
        ->not->toContain('{{');
});

it('writes the page\'s browser test at the path the testing rule mirrors', function () {
    runMakeListPage()->assertSuccessful();

    expect(File::get(base_path(listPageGenerated()['browser'])))
        ->toContain('resources/js/pages/sampling-cartons/index.tsx')
        ->toContain("it('renders the list without javascript errors')->todo();")
        ->not->toContain('{{');
});

it('adds the missing types to an existing types file and keeps what it held', function () {
    File::put(base_path(listPageGenerated()['types']), "/**\n * SamplingCartons.\n */\n\nexport type SamplingCartonSize = 'small' | 'large';\n");

    runMakeListPage()->assertSuccessful();
    runMakeListPage()->assertSuccessful();

    $types = File::get(base_path(listPageGenerated()['types']));

    expect($types)
        ->toStartWith("/**\n * SamplingCartons.\n */\nimport type { DtQuery }")
        ->toContain("export type SamplingCartonSize = 'small' | 'large';")
        ->and(substr_count($types, 'export type SamplingCartonRow'))->toBe(1)
        ->and(substr_count(File::get(resource_path('js/types/index.ts')), "export type * from './sampling-carton';"))->toBe(1);
});

it('never overwrites a page or toolbar that already exists', function () {
    File::ensureDirectoryExists(resource_path('js/pages/sampling-cartons'));
    File::put(base_path(listPageGenerated()['page']), 'hand written');

    runMakeListPage()
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(base_path(listPageGenerated()['page'])))->toBe('hand written');
});

it('never overwrites a browser test that already exists', function () {
    File::ensureDirectoryExists(base_path('tests/Browser/SamplingCartons'));
    File::put(base_path(listPageGenerated()['browser']), 'hand written');

    runMakeListPage()
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(base_path(listPageGenerated()['browser'])))->toBe('hand written');
});

it('reports the translation keys and the route the page still needs', function () {
    expect(Artisan::call('make:list-page', ['name' => 'ListSamplingCartons', '--domain' => LIST_PAGE_CONTEXT]))->toBe(0)
        ->and(Artisan::output())
        ->toContain('Route [sampling-cartons.index] does not exist yet')
        ->toContain('lang/en.json is missing')
        ->toContain('sampling-cartons.empty_title');
});

describe('--types-only', function () {
    it('writes the types and their barrel line, and no page, toolbar or browser test', function () {
        runMakeListPage(['--types-only' => true])->assertSuccessful();

        expect(File::get(base_path(listPageGenerated()['types'])))
            ->toContain('export type SamplingCartonRow = {')
            ->toContain('export type SamplingCartonFilters = DtFilters & {')
            ->toContain('export type SamplingCartonsQuery = SamplingCartonFilters & DtQuery;')
            ->and(File::get(resource_path('js/types/index.ts')))->toContain("export type * from './sampling-carton';")
            ->and(File::exists(base_path(listPageGenerated()['page'])))->toBeFalse()
            ->and(File::exists(base_path(listPageGenerated()['toolbar'])))->toBeFalse()
            ->and(File::exists(base_path(listPageGenerated()['browser'])))->toBeFalse();
    });

    it('writes no query type for a grid', function () {
        runMakeListPage(['--types-only' => true, '--grid' => true])->assertSuccessful();

        expect(File::get(base_path(listPageGenerated()['types'])))
            ->toContain('export type SamplingCartonRow = {')
            ->not->toContain('SamplingCartonsQuery');
    });
});

describe('--grid', function () {
    it('writes a grid page on the list query, infinite scroll and the toolbar', function () {
        runMakeListPage(['--grid' => true])
            ->expectsOutputToContain('Inertia::scroll(')
            ->assertSuccessful();

        // The grid shape's three markers in ListPagesTest's `pages` check.
        expect(File::get(base_path(listPageGenerated()['page'])))
            ->toMatch('/\buseListQuery\s*(<[^()]*>)?\s*\(/')
            ->toMatch('/<InfiniteScroll\b/')
            ->toMatch('/<\w*TableToolbar\b/')
            ->not->toMatch('/\buseDataTable\s*\(/')
            ->toContain('samplingCartons: Paginated<SamplingCartonRow>;')
            ->toContain('filters: SamplingCartonFilters;')
            ->not->toContain('SortState')
            ->not->toContain('sort:')
            ->toContain('useListQuery<SamplingCartonFilters>({')
            ->toContain('data="samplingCartons"')
            ->toMatch('/<SamplingCartonCard\s+key=\{samplingCarton\.id\}\s+samplingCarton=\{samplingCarton\}\s+\/>/')
            ->toContain('<SamplingCartonTableToolbar dt={list} />')
            ->toContain("t('sampling-cartons.no_results_title')")
            ->toContain('replace: true')
            ->not->toContain('{{');
    });

    it('types a grid with no query type and its toolbar on the list query', function () {
        runMakeListPage(['--grid' => true])->assertSuccessful();

        expect(File::get(base_path(listPageGenerated()['types'])))
            ->toContain('export type SamplingCartonRow = {')
            ->toContain('export type SamplingCartonFilters = DtFilters & {')
            ->not->toContain('SamplingCartonsQuery')
            ->not->toContain('DtQuery')
            ->and(File::get(base_path(listPageGenerated()['toolbar'])))
            ->toContain('dt: ListQuery<SamplingCartonFilters>;')
            ->toContain('const FIELDS: DtFilterField<SamplingCartonFilters>[] = [')
            ->toContain("{ value: 'packed', label: 'sampling-cartons.status.packed' }")
            ->toContain("fromKey: 'shipped_from'")
            ->not->toContain('DataTableInstance');
    });

    it('writes a card with one label and value per row key but the id', function () {
        runMakeListPage(['--grid' => true])->assertSuccessful();

        expect(File::get(base_path(listPageGenerated()['card'])))
            ->toContain('export function SamplingCartonCard({ samplingCarton }: Props)')
            ->toContain("t('sampling-cartons.label')")
            ->toContain('String(samplingCarton.weight)')
            ->toContain("String(samplingCarton.note ?? '')")
            ->toContain("t('sampling-cartons.tags')")
            ->not->toContain('samplingCarton.id')
            ->not->toContain('{{');
    });

    it('writes the grid\'s browser test at the path the testing rule mirrors', function () {
        runMakeListPage(['--grid' => true])->assertSuccessful();

        expect(File::get(base_path(listPageGenerated()['browser'])))
            ->toContain('resources/js/pages/sampling-cartons/index.tsx')
            ->toContain("it('loads the next page of cards as the user scrolls')->todo();")
            ->not->toContain('{{');
    });

    it('never overwrites a card that already exists', function () {
        File::ensureDirectoryExists(resource_path('js/components/sampling-carton'));
        File::put(base_path(listPageGenerated()['card']), 'hand written');

        runMakeListPage(['--grid' => true])
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        expect(File::get(base_path(listPageGenerated()['card'])))->toBe('hand written');
    });

    it('writes grid files the ESLint rules and import order accept', function () {
        runMakeListPage(['--grid' => true])->assertSuccessful();

        $files = array_map(base_path(...), [listPageGenerated()['page'], listPageGenerated()['toolbar'], listPageGenerated()['card']]);
        $eslint = Process::path(base_path())->run([base_path('node_modules/.bin/eslint'), '--no-ignore', ...$files]);

        expect($eslint->successful())->toBeTrue($eslint->output());
    });
});
