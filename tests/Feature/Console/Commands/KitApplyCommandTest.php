<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;

/**
 * The scratch context and model of this file, which the real generators build. Both carry
 * `Sampling`, so no Architecture check reads what they write, and differ from every other test
 * file's. The binding and route lines go into a provider and a route file under storage, bound in
 * the container, so no case writes into motto's own.
 */
const SAMPLING_APPLY_CONTEXT = 'SamplingApply';

const SAMPLING_APPLY_MODEL = 'SamplingPannier';

function samplingApplyMarkersRoot(): string
{
    return storage_path('framework/testing/sampling-apply');
}

function samplingApplyRow(): string
{
    return app_path('Application/'.SAMPLING_APPLY_CONTEXT.'/UseCases/ListSamplingPanniers/SamplingPannierListRow.php');
}

function writeSamplingApplyManifests(): void
{
    $files = new StructureFiles(base_path());
    $files->write([
        'context' => SAMPLING_APPLY_CONTEXT,
        'aggregates' => ['Pannier' => ['children' => [], 'repository' => false]],
        'services' => [],
        'ports' => [],
        'useCases' => [
            'ListSamplingPanniers' => ['shape' => 'command-result', 'returns' => 'result', 'creates' => false, 'query' => true, 'repositories' => []],
        ],
    ]);
    $files->writeResource([
        'resource' => SAMPLING_APPLY_MODEL,
        'model' => SAMPLING_APPLY_MODEL,
        'controller' => ['index' => [SAMPLING_APPLY_CONTEXT.'/ListSamplingPanniers']],
        'actions' => [],
        'policy' => ['viewAny'],
        'pages' => ['sampling-panniers/index' => 'table'],
    ]);

    $root = samplingApplyMarkersRoot();
    File::ensureDirectoryExists($root.'/app/Providers');
    File::ensureDirectoryExists($root.'/routes');
    File::put($root.'/app/Providers/AppServiceProvider.php', "<?php\n\nclass AppServiceProvider\n{\n    public array \$bindings = [\n        // kit:bindings\n    ];\n}\n");
    File::put($root.'/routes/web.php', "<?php\n");
}

function forgetSamplingApply(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_APPLY_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_APPLY_CONTEXT));
    File::deleteDirectory(app_path('Application/'.SAMPLING_APPLY_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Application/'.SAMPLING_APPLY_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Http/Controllers/'.SAMPLING_APPLY_MODEL.'Controller'));
    File::deleteDirectory(resource_path('js/pages/sampling-panniers'));
    File::deleteDirectory(resource_path('js/components/sampling-pannier'));
    File::deleteDirectory(base_path('tests/Browser/SamplingPanniers'));
    File::deleteDirectory(samplingApplyMarkersRoot());
    File::delete([
        base_path('.kit/structure/'.SAMPLING_APPLY_CONTEXT.'.json'),
        base_path('.kit/structure/http/'.SAMPLING_APPLY_MODEL.'.json'),
        app_path('Infra/Persistence/Eloquent/Queries/EloquentListSamplingPanniersQuery.php'),
        base_path('tests/Feature/Infra/Persistence/Eloquent/Queries/EloquentListSamplingPanniersQueryTest.php'),
        app_path('Models/'.SAMPLING_APPLY_MODEL.'.php'),
        database_path('factories/'.SAMPLING_APPLY_MODEL.'Factory.php'),
        app_path('Policies/'.SAMPLING_APPLY_MODEL.'Policy.php'),
        base_path('tests/Feature/Policies/'.SAMPLING_APPLY_MODEL.'PolicyTest.php'),
        app_path('Http/Controllers/'.SAMPLING_APPLY_MODEL.'Controller.php'),
        resource_path('js/types/sampling-pannier.ts'),
    ]);

    rewriteSharedFile(resource_path('js/types/index.ts'), fn (string $barrel): string => str_replace("export type * from './sampling-pannier';\n", '', $barrel));
}

beforeEach(function () {
    forgetSamplingApply();
    writeSamplingApplyManifests();
    app()->instance(StructureMarkers::class, new StructureMarkers(samplingApplyMarkersRoot()));
});

afterEach(fn () => forgetSamplingApply());

it('builds what is ready, then picks up where it stopped once the waiting code is filled in', function () {
    $options = ['--context' => [SAMPLING_APPLY_CONTEXT], '--resource' => [SAMPLING_APPLY_MODEL]];
    $controller = '\\App\\Http\\Controllers\\'.SAMPLING_APPLY_MODEL.'Controller::class';

    $this->artisan('kit:apply', $options)
        ->expectsOutputToContain('above // kit:bindings in [app/Providers/AppServiceProvider.php]')
        ->expectsOutputToContain("No file holds // kit:routes, so place this line by hand: Route::resource('sampling-panniers', {$controller})->only(['index']);")
        ->expectsOutputToContain('fill in the keys of the ListSamplingPanniers Row first')
        ->expectsOutputToContain('Done: 6 of 8 steps.')
        ->assertSuccessful();

    expect(File::exists(app_path('Domain/'.SAMPLING_APPLY_CONTEXT.'/Pannier/PannierEntity.php')))->toBeTrue()
        ->and(File::exists(app_path('Models/'.SAMPLING_APPLY_MODEL.'.php')))->toBeTrue()
        ->and(File::exists(app_path('Policies/'.SAMPLING_APPLY_MODEL.'Policy.php')))->toBeTrue()
        ->and(File::exists(app_path('Http/Controllers/'.SAMPLING_APPLY_MODEL.'Controller.php')))->toBeTrue()
        ->and(File::get(samplingApplyMarkersRoot().'/app/Providers/AppServiceProvider.php'))->toContain(
            "        \\App\\Application\\SamplingApply\\UseCases\\ListSamplingPanniers\\ListSamplingPanniersQuery::class => \\App\\Infra\\Persistence\\Eloquent\\Queries\\EloquentListSamplingPanniersQuery::class,\n        // kit:bindings\n",
        )
        ->and(File::exists(resource_path('js/pages/sampling-panniers/index.tsx')))->toBeFalse();

    File::put(samplingApplyRow(), str_replace(
        ['        // describe the rest of the row here', 'array{id: string}', "            'id' => \$this->id,"],
        ['        public readonly string $label,', 'array{id: string, label: string}', "            'id' => \$this->id,\n            'label' => \$this->label,"],
        File::get(samplingApplyRow()),
    ));
    File::put(samplingApplyMarkersRoot().'/routes/web.php', "<?php\n\n// kit:routes\n");

    $this->artisan('kit:apply', $options)
        ->expectsOutputToContain("Wrote [Route::resource('sampling-panniers', {$controller})->only(['index']);] above // kit:routes in [routes/web.php].")
        ->expectsOutputToContain('Done: 8 of 8 steps.')
        ->expectsOutputToContain('Routes changed.')
        ->assertSuccessful();

    expect(File::exists(resource_path('js/pages/sampling-panniers/index.tsx')))->toBeTrue();
});

/**
 * The scratch context of the entity case: an aggregate, its child and their methods, built from
 * nothing.
 */
const SAMPLING_BEHAVE_CONTEXT = 'SamplingBehave';

function forgetSamplingBehave(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_BEHAVE_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_BEHAVE_CONTEXT));
    File::delete(base_path('.kit/structure/'.SAMPLING_BEHAVE_CONTEXT.'.json'));
}

it('builds an entity\'s methods after the entity, the enums they take and the exceptions they throw', function () {
    forgetSamplingBehave();
    $context = SAMPLING_BEHAVE_CONTEXT;
    (new StructureFiles(base_path()))->write([
        'context' => $context,
        'aggregates' => ['Basket' => ['children' => ['Handle'], 'repository' => false]],
        'enums' => ['BasketWeave' => ['aggregate' => 'Basket', 'backing' => 'string', 'cases' => ['Tight' => 'tight', 'Loose' => 'loose']]],
        'entities' => [
            'Basket' => ['aggregate' => 'Basket', 'behaviours' => ['weave' => ['params' => ['weave' => 'BasketWeave'], 'throws' => ['BasketTornException']]], 'assertions' => []],
            'Handle' => ['aggregate' => 'Basket', 'behaviours' => [], 'assertions' => ['assertFixed' => ['params' => [], 'throws' => ['BasketTornException']]]],
        ],
    ]);

    try {
        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain('Done: 5 of 5 steps.')
            ->expectsOutputToContain('Classes moved or changed during this run.')
            ->assertSuccessful();

        expect(File::get(app_path("Domain/{$context}/Basket/BasketEntity.php")))
            ->toContain("     * @throws BasketTornException\n     */\n    public function weave(BasketWeave \$weave): void")
            ->and(File::get(app_path("Domain/{$context}/Basket/Entities/HandleEntity.php")))
            ->toContain('public function assertFixed(): void')
            ->and(File::exists(app_path("Domain/{$context}/Basket/Exceptions/BasketTornException.php")))->toBeTrue()
            ->and(File::get(base_path("tests/Unit/Domain/{$context}/Basket/BasketEntityTest.php")))->toContain("describe('weave()'");
    } finally {
        forgetSamplingBehave();
    }
});

it('fails on a name no manifest gives', function () {
    $this->artisan('kit:apply', ['--resource' => ['SamplingNowhere']])
        ->expectsOutputToContain('No manifest names the context or HTTP resource SamplingNowhere.')
        ->assertFailed();
});

/**
 * The scratch context of the swap case: CountBins replaced by CountBinsTwice, which a controller
 * calls, and the Sieve port moving from CoarseSieve to FineSieve, which a provider binds.
 */
const SAMPLING_SWAP_CONTEXT = 'SamplingSwap';

function writeSamplingSwapFixtures(): void
{
    $context = SAMPLING_SWAP_CONTEXT;
    $useCases = "App\\Application\\{$context}\\UseCases\\CountBins";
    $fixtures = [
        "Application/{$context}/UseCases/CountBins/CountBinsCommand.php" => "<?php\n\nnamespace {$useCases};\n\nuse Spatie\\LaravelData\\Data;\n\nfinal class CountBinsCommand extends Data\n{\n    public function __construct(public readonly string \$binId) {}\n}\n",
        "Application/{$context}/UseCases/CountBins/CountBinsHandler.php" => "<?php\n\nnamespace {$useCases};\n\nfinal class CountBinsHandler\n{\n    public function __invoke(CountBinsCommand \$command): int { return 0; }\n}\n",
        'Http/Controllers/'.$context.'Controller.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse {$useCases}\\CountBinsCommand;\nuse {$useCases}\\CountBinsHandler;\n\nclass {$context}Controller\n{\n    public function store(CountBinsHandler \$countBins): int\n    {\n        return \$countBins(new CountBinsCommand(binId: 'bin'));\n    }\n}\n",
        "Domain/{$context}/Ports/Sieve.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Ports;\n\ninterface Sieve {}\n",
        "Infra/{$context}/CoarseSieve.php" => "<?php\n\nnamespace App\\Infra\\{$context};\n\nuse App\\Domain\\{$context}\\Ports\\Sieve;\n\nfinal class CoarseSieve implements Sieve {}\n",
        'Providers/'.$context.'ServiceProvider.php' => "<?php\n\nnamespace App\\Providers;\n\nuse App\\Domain\\{$context}\\Ports\\Sieve;\nuse App\\Infra\\{$context}\\CoarseSieve;\nuse Illuminate\\Support\\ServiceProvider;\n\nclass {$context}ServiceProvider extends ServiceProvider\n{\n    public array \$bindings = [\n        Sieve::class => CoarseSieve::class,\n    ];\n}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(app_path($relative)));
        File::put(app_path($relative), $contents);
    }

    (new StructureFiles(base_path()))->write([
        'context' => $context,
        'aggregates' => [],
        'services' => [],
        'ports' => ['Sieve' => ['layer' => 'domain', 'adapter' => "Infra/{$context}/FineSieve", 'replaces' => "Infra/{$context}/CoarseSieve"]],
        'useCases' => [
            'CountBins' => ['shape' => 'command', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => []],
            'CountBinsTwice' => ['shape' => 'command', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => [], 'replaces' => 'CountBins'],
        ],
    ]);
}

function forgetSamplingSwap(): void
{
    $context = SAMPLING_SWAP_CONTEXT;

    foreach (["app/Domain/{$context}", "app/Application/{$context}", "app/Infra/{$context}", "tests/Feature/Infra/{$context}", "tests/Feature/Application/{$context}"] as $folder) {
        File::deleteDirectory(base_path($folder));
    }

    File::delete([
        base_path(".kit/structure/{$context}.json"),
        app_path("Http/Controllers/{$context}Controller.php"),
        app_path("Providers/{$context}ServiceProvider.php"),
    ]);
}

describe('a replacement', function () {
    beforeEach(function () {
        forgetSamplingSwap();
        writeSamplingSwapFixtures();
        Process::fake();
    });

    afterEach(fn () => forgetSamplingSwap());

    it('builds the new pieces, then swaps each in once it can stand in for the old one', function () {
        $context = SAMPLING_SWAP_CONTEXT;
        $controller = app_path("Http/Controllers/{$context}Controller.php");

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain("Swapped in 1 file(s): app/Providers/{$context}ServiceProvider.php")
            ->expectsOutputToContain('fill in the fields of CountBinsTwiceCommand first, as CountBinsCommand has them')
            ->assertSuccessful();

        expect(File::get(app_path("Providers/{$context}ServiceProvider.php")))->toContain("use App\\Infra\\{$context}\\FineSieve;")->toContain('Sieve::class => FineSieve::class')
            ->and(File::get($controller))->toContain('CountBinsHandler $countBins');

        $command = app_path("Application/{$context}/UseCases/CountBinsTwice/CountBinsTwiceCommand.php");
        File::put($command, preg_replace('/public function __construct\((.*?)\) \{\}/s', 'public function __construct(public readonly string $binId) {}', File::get($command)));

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain("Swapped in 1 file(s): app/Http/Controllers/{$context}Controller.php")
            ->expectsOutputToContain('Classes moved or changed during this run.')
            ->assertSuccessful();

        expect(File::get($controller))
            ->toContain("use App\\Application\\{$context}\\UseCases\\CountBinsTwice\\CountBinsTwiceHandler;")
            ->toContain('CountBinsTwiceHandler $countBins')
            ->toContain('new CountBinsTwiceCommand(')
            ->not->toContain('CountBinsHandler');

        Process::assertRan(fn ($process): bool => is_array($process->command) && $process->command[0] === 'vendor/bin/pint');
    });
});

/**
 * The scratch context of the list swap case: ListSamplingTrunks, whose page and TypeScript twins
 * exist, replaced by ListSamplingOpenTrunks, which kit:apply builds. Every name it writes carries
 * Sampling. The binding line goes into the provider under storage that the markers are bound to.
 */
const SAMPLING_RELIST_CONTEXT = 'SamplingRelist';

function writeSamplingRelistFixtures(): void
{
    $context = SAMPLING_RELIST_CONTEXT;
    $namespace = "App\\Application\\{$context}\\UseCases\\ListSamplingTrunks";
    $command = "<?php\n\nnamespace {$namespace};\n\nuse Spatie\\LaravelData\\Data;\n\nfinal class ListSamplingTrunksCommand extends Data\n{\n    public function __construct(public readonly string \$search = '') {}\n}\n";
    $fixtures = [
        "app/Application/{$context}/UseCases/ListSamplingTrunks/ListSamplingTrunksCommand.php" => $command,
        "app/Application/{$context}/UseCases/ListSamplingTrunks/ListSamplingTrunksResult.php" => "<?php\n\nnamespace {$namespace};\n\nfinal class ListSamplingTrunksResult {}\n",
        "app/Application/{$context}/UseCases/ListSamplingTrunks/ListSamplingTrunksHandler.php" => "<?php\n\nnamespace {$namespace};\n\nfinal class ListSamplingTrunksHandler\n{\n    public function __invoke(ListSamplingTrunksCommand \$command): ListSamplingTrunksResult { return new ListSamplingTrunksResult; }\n}\n",
        "app/Http/Controllers/{$context}Controller.php" => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse {$namespace}\\ListSamplingTrunksCommand;\nuse {$namespace}\\ListSamplingTrunksHandler;\n\nclass {$context}Controller\n{\n    public function index(ListSamplingTrunksHandler \$listTrunks): void\n    {\n        \$listTrunks(new ListSamplingTrunksCommand(search: ''));\n    }\n}\n",
        'resources/js/types/sampling-trunk.ts' => "export type SamplingTrunkRow = {\n    id: string;\n};\n\nexport type SamplingTrunkFilters = {\n    search: string;\n};\n\nexport type SamplingTrunksQuery = SamplingTrunkFilters;\n",
        'resources/js/pages/sampling-trunks/index.tsx' => "import type { SamplingTrunkFilters, SamplingTrunkRow, SamplingTrunksQuery } from '@/types';\n\nexport type Props = { rows: SamplingTrunkRow[]; filters: SamplingTrunkFilters; query: SamplingTrunksQuery };\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $contents);
    }

    (new StructureFiles(base_path()))->write([
        'context' => $context,
        'aggregates' => [],
        'services' => [],
        'ports' => [],
        'useCases' => [
            'ListSamplingTrunks' => ['shape' => 'command-result', 'returns' => 'result', 'creates' => false, 'query' => true, 'repositories' => []],
            'ListSamplingOpenTrunks' => ['shape' => 'command-result', 'returns' => 'result', 'creates' => false, 'query' => true, 'repositories' => [], 'replaces' => 'ListSamplingTrunks'],
        ],
    ]);
}

function forgetSamplingRelist(): void
{
    $context = SAMPLING_RELIST_CONTEXT;

    foreach (["app/Application/{$context}", "tests/Feature/Application/{$context}", 'resources/js/pages/sampling-trunks'] as $folder) {
        File::deleteDirectory(base_path($folder));
    }

    File::delete([
        base_path(".kit/structure/{$context}.json"),
        app_path("Http/Controllers/{$context}Controller.php"),
        app_path('Infra/Persistence/Eloquent/Queries/EloquentListSamplingOpenTrunksQuery.php'),
        base_path('tests/Feature/Infra/Persistence/Eloquent/Queries/EloquentListSamplingOpenTrunksQueryTest.php'),
        resource_path('js/types/sampling-trunk.ts'),
        resource_path('js/types/sampling-open-trunk.ts'),
    ]);

    rewriteSharedFile(resource_path('js/types/index.ts'), fn (string $barrel): string => str_replace("export type * from './sampling-open-trunk';\n", '', $barrel));
}

describe('a list replacement', function () {
    beforeEach(function () {
        forgetSamplingRelist();
        forgetSamplingApply();
        writeSamplingRelistFixtures();
        writeSamplingApplyManifests();
        app()->instance(StructureMarkers::class, new StructureMarkers(samplingApplyMarkersRoot()));
        Process::fake();
    });

    afterEach(function () {
        forgetSamplingRelist();
        forgetSamplingApply();
    });

    it('writes the new list\'s types beside the old ones, then points the HTTP layer and the pages at it', function () {
        $context = SAMPLING_RELIST_CONTEXT;
        $folder = app_path("Application/{$context}/UseCases/ListSamplingOpenTrunks");
        $page = resource_path('js/pages/sampling-trunks/index.tsx');

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain('fill in the keys of the ListSamplingOpenTrunks Row first')
            ->assertSuccessful();

        expect(File::get(samplingApplyMarkersRoot().'/app/Providers/AppServiceProvider.php'))->toContain('ListSamplingOpenTrunksQuery::class => \\App\\Infra\\Persistence\\Eloquent\\Queries\\EloquentListSamplingOpenTrunksQuery::class')
            ->and(File::get($page))->toContain('SamplingTrunkRow');

        File::put("{$folder}/ListSamplingOpenTrunksCommand.php", preg_replace('/public function __construct\((.*?)\) \{\}/s', "public function __construct(public readonly string \$search = '') {}", File::get("{$folder}/ListSamplingOpenTrunksCommand.php")));
        File::put("{$folder}/SamplingOpenTrunkListRow.php", str_replace(
            ['        // describe the rest of the row here', 'array{id: string}', "            'id' => \$this->id,"],
            ['        public readonly string $label,', 'array{id: string, label: string}', "            'id' => \$this->id,\n            'label' => \$this->label,"],
            File::get("{$folder}/SamplingOpenTrunkListRow.php"),
        ));

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain("Swapped in 2 file(s): app/Http/Controllers/{$context}Controller.php, resources/js/pages/sampling-trunks/index.tsx")
            ->assertSuccessful();

        expect(File::get(resource_path('js/types/sampling-open-trunk.ts')))
            ->toContain('export type SamplingOpenTrunkRow = {')
            ->toContain('label: string;')
            ->toContain('export type SamplingOpenTrunksQuery')
            ->and(File::get(resource_path('js/types/sampling-trunk.ts')))->toContain('export type SamplingTrunkRow')
            ->and(File::get($page))->toBe("import type { SamplingOpenTrunkFilters, SamplingOpenTrunkRow, SamplingOpenTrunksQuery } from '@/types';\n\nexport type Props = { rows: SamplingOpenTrunkRow[]; filters: SamplingOpenTrunkFilters; query: SamplingOpenTrunksQuery };\n")
            ->and(File::get(app_path("Http/Controllers/{$context}Controller.php")))->toContain('ListSamplingOpenTrunksHandler $listTrunks')->not->toContain('ListSamplingTrunksHandler');

        Process::assertRan(fn ($process): bool => is_array($process->command) && $process->command[0] === 'node_modules/.bin/prettier' && in_array('resources/js/pages/sampling-trunks/index.tsx', $process->command, true));
    });
});

/**
 * The scratch context of the service swap case: the domain service PackBin, which a handler calls,
 * a controller catches and a handler test names, replaced by PackBinTightly, which kit:apply
 * builds. PackBin's own BinLabel is named outside its folder, so the new service needs one too.
 */
const SAMPLING_RESERVICE_CONTEXT = 'SamplingReservice';

function writeSamplingReserviceFixtures(): void
{
    $context = SAMPLING_RESERVICE_CONTEXT;
    $service = "App\\Domain\\{$context}\\Services\\PackBin";
    $fixtures = [
        "app/Domain/{$context}/Services/PackBin/PackBinService.php" => "<?php\n\nnamespace {$service};\n\nfinal class PackBinService\n{\n    public function handle(PackBinData \$data): PackBinResult\n    {\n        return new PackBinResult;\n    }\n}\n",
        "app/Domain/{$context}/Services/PackBin/PackBinData.php" => "<?php\n\nnamespace {$service};\n\nfinal readonly class PackBinData\n{\n    public function __construct(public string \$binId) {}\n}\n",
        "app/Domain/{$context}/Services/PackBin/PackBinResult.php" => "<?php\n\nnamespace {$service};\n\nfinal class PackBinResult {}\n",
        "app/Domain/{$context}/Services/PackBin/PackBinException.php" => "<?php\n\nnamespace {$service};\n\nuse App\\Domain\\Shared\\DomainException;\n\nfinal class PackBinException extends DomainException {}\n",
        "app/Domain/{$context}/Services/PackBin/BinLabel.php" => "<?php\n\nnamespace {$service};\n\nfinal class BinLabel {}\n",
        "app/Application/{$context}/UseCases/PackBinHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases;\n\nuse {$service}\\BinLabel;\nuse {$service}\\PackBinData;\nuse {$service}\\PackBinService;\n\nfinal class PackBinHandler\n{\n    public function __construct(private readonly PackBinService \$packBin) {}\n\n    public function __invoke(string \$binId): BinLabel\n    {\n        \$this->packBin->handle(new PackBinData(binId: \$binId));\n\n        return new BinLabel;\n    }\n}\n",
        "app/Http/Controllers/{$context}Controller.php" => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse {$service}\\PackBinException;\n\nclass {$context}Controller\n{\n    public function store(): void\n    {\n        try {\n        } catch (PackBinException) {\n        }\n    }\n}\n",
        "tests/Feature/Application/{$context}/UseCases/PackBinHandlerTest.php" => "<?php\n\nuse {$service}\\PackBinData;\n",
        "tests/Unit/Domain/{$context}/Services/PackBin/PackBinServiceTest.php" => "<?php\n\nuse {$service}\\PackBinService;\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $contents);
    }

    (new StructureFiles(base_path()))->write([
        'context' => $context,
        'aggregates' => [],
        'services' => [
            'PackBin' => ['shape' => 'data', 'creates' => null, 'repositories' => []],
            'PackBinTightly' => ['shape' => 'data', 'creates' => null, 'repositories' => [], 'replaces' => 'PackBin'],
        ],
        'ports' => [],
        'useCases' => [
            'PackBin' => ['shape' => 'plain', 'returns' => 'BinLabel', 'creates' => false, 'query' => false, 'repositories' => []],
        ],
    ]);
}

function forgetSamplingReservice(): void
{
    $context = SAMPLING_RESERVICE_CONTEXT;

    foreach (["app/Domain/{$context}", "app/Application/{$context}", "tests/Feature/Application/{$context}", "tests/Unit/Domain/{$context}", "tests/Feature/Domain/{$context}"] as $folder) {
        File::deleteDirectory(base_path($folder));
    }

    File::delete([
        base_path(".kit/structure/{$context}.json"),
        app_path("Http/Controllers/{$context}Controller.php"),
    ]);
}

describe('a domain service replacement', function () {
    beforeEach(function () {
        forgetSamplingReservice();
        writeSamplingReserviceFixtures();
        Process::fake();
    });

    afterEach(fn () => forgetSamplingReservice());

    it('builds the new service, waits until it can stand in, then points every caller at it', function () {
        $context = SAMPLING_RESERVICE_CONTEXT;
        $folder = app_path("Domain/{$context}/Services/PackBinTightly");

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain('write PackBinTightlyService::handle() first')
            ->assertSuccessful();

        expect(File::exists("{$folder}/PackBinTightlyException.php"))->toBeTrue()
            ->and(File::get(app_path("Application/{$context}/UseCases/PackBinHandler.php")))->toContain('PackBinService $packBin');

        File::put("{$folder}/PackBinTightlyService.php", str_replace("throw new LogicException('PackBinTightlyService::handle() is not implemented yet.');", 'return new PackBinTightlyResult;', File::get("{$folder}/PackBinTightlyService.php")));

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain('fill in the fields of PackBinTightlyData first, as PackBinData has them')
            ->assertSuccessful();

        File::put("{$folder}/PackBinTightlyData.php", preg_replace('/public function __construct\((.*?)\) \{\}/s', 'public function __construct(public string $binId) {}', File::get("{$folder}/PackBinTightlyData.php")));

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain('write PackBinTightly/BinLabel first, as PackBin/BinLabel is still named outside its folder')
            ->assertSuccessful();

        File::put("{$folder}/BinLabel.php", "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\PackBinTightly;\n\nfinal class BinLabel {}\n");

        $this->artisan('kit:apply', ['--context' => [$context]])
            ->expectsOutputToContain("Swapped in 3 file(s): app/Application/{$context}/UseCases/PackBinHandler.php, app/Http/Controllers/{$context}Controller.php, tests/Feature/Application/{$context}/UseCases/PackBinHandlerTest.php")
            ->assertSuccessful();

        expect(File::get(app_path("Application/{$context}/UseCases/PackBinHandler.php")))
            ->toContain("use App\\Domain\\{$context}\\Services\\PackBinTightly\\BinLabel;")
            ->toContain('PackBinTightlyService $packBin')
            ->toContain('new PackBinTightlyData(')
            ->not->toContain('Services\\PackBin\\')
            ->and(File::get(app_path("Http/Controllers/{$context}Controller.php")))->toContain('catch (PackBinTightlyException)')
            ->and(File::get(base_path("tests/Feature/Application/{$context}/UseCases/PackBinHandlerTest.php")))->toContain('PackBinTightly\\PackBinTightlyData')
            ->and(File::get(base_path("tests/Unit/Domain/{$context}/Services/PackBin/PackBinServiceTest.php")))->toContain('PackBin\\PackBinService;')
            ->and(File::get(app_path("Domain/{$context}/Services/PackBin/PackBinService.php")))->toContain('final class PackBinService');
    });
});
