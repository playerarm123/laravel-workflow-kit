<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;

/**
 * The scratch context of this file, caught right after kit:apply swapped two replacements in:
 * SortBins by SortBinsTwice, which the controller now calls, and the Funnel port's NarrowFunnel by
 * WideFunnel, which the provider now binds. It carries `Sampling`, so no Architecture check reads
 * it, and differs from every other test file's. The test run kit:retire starts is faked.
 */
const SAMPLING_RETIRE_CONTEXT = 'SamplingRetire';

/**
 * @return array<string, string> each fixture's path from the project root, and its contents
 */
function samplingRetireFixtures(): array
{
    $context = SAMPLING_RETIRE_CONTEXT;
    $useCases = "App\\Application\\{$context}\\UseCases";
    $useCase = fn (string $name): array => [
        "app/Application/{$context}/UseCases/{$name}/{$name}Command.php" => "<?php\n\nnamespace {$useCases}\\{$name};\n\nfinal class {$name}Command {}\n",
        "app/Application/{$context}/UseCases/{$name}/{$name}Handler.php" => "<?php\n\nnamespace {$useCases}\\{$name};\n\nfinal class {$name}Handler\n{\n    public function __invoke({$name}Command \$command): void {}\n}\n",
        "tests/Feature/Application/{$context}/UseCases/{$name}/{$name}HandlerTest.php" => "<?php\n",
    ];
    $adapter = fn (string $name): array => [
        "app/Infra/{$context}/{$name}.php" => "<?php\n\nnamespace App\\Infra\\{$context};\n\nuse App\\Domain\\{$context}\\Ports\\Funnel;\n\nfinal class {$name} implements Funnel {}\n",
        "tests/Feature/Infra/{$context}/{$name}Test.php" => "<?php\n",
    ];

    return [
        ...$useCase('SortBins'),
        ...$useCase('SortBinsTwice'),
        ...$adapter('NarrowFunnel'),
        ...$adapter('WideFunnel'),
        "app/Domain/{$context}/Ports/Funnel.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Ports;\n\ninterface Funnel {}\n",
        "app/Http/Controllers/{$context}Controller.php" => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse {$useCases}\\SortBinsTwice\\SortBinsTwiceHandler;\n\nclass {$context}Controller\n{\n    public function store(SortBinsTwiceHandler \$sortBins): void {}\n}\n",
        "app/Providers/{$context}ServiceProvider.php" => "<?php\n\nnamespace App\\Providers;\n\nuse App\\Domain\\{$context}\\Ports\\Funnel;\nuse App\\Infra\\{$context}\\WideFunnel;\nuse Illuminate\\Support\\ServiceProvider;\n\nclass {$context}ServiceProvider extends ServiceProvider\n{\n    public array \$bindings = [\n        Funnel::class => WideFunnel::class,\n    ];\n}\n",
    ];
}

function writeSamplingRetireFixtures(): void
{
    $context = SAMPLING_RETIRE_CONTEXT;

    foreach (samplingRetireFixtures() as $relative => $contents) {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $contents);
    }

    (new StructureFiles(base_path()))->write([
        'context' => $context,
        'aggregates' => [],
        'services' => [],
        'ports' => ['Funnel' => ['layer' => 'domain', 'adapter' => "Infra/{$context}/WideFunnel", 'replaces' => "Infra/{$context}/NarrowFunnel"]],
        'useCases' => [
            'SortBins' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => []],
            'SortBinsTwice' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => [], 'replaces' => 'SortBins'],
        ],
    ]);
}

function forgetSamplingRetire(): void
{
    $context = SAMPLING_RETIRE_CONTEXT;

    foreach (["app/Domain/{$context}", "app/Application/{$context}", "app/Infra/{$context}", "tests/Feature/Application/{$context}", "tests/Feature/Infra/{$context}"] as $folder) {
        File::deleteDirectory(base_path($folder));
    }

    File::delete([
        base_path(".kit/structure/{$context}.json"),
        app_path("Http/Controllers/{$context}Controller.php"),
        app_path("Providers/{$context}ServiceProvider.php"),
    ]);
}

/**
 * @return array<string, mixed>
 */
function samplingRetireManifest(): array
{
    return json_decode(File::get(base_path('.kit/structure/'.SAMPLING_RETIRE_CONTEXT.'.json')), true);
}

beforeEach(function () {
    forgetSamplingRetire();
    writeSamplingRetireFixtures();
});

afterEach(fn () => forgetSamplingRetire());

it('removes what each finished replacement took over once the tests pass', function () {
    $context = SAMPLING_RETIRE_CONTEXT;
    Process::fake(['*' => Process::result('Tests: 12 passed')]);

    $this->artisan('kit:retire', ['--context' => [$context]])
        ->expectsOutputToContain("Retired App\\Infra\\{$context}\\NarrowFunnel, replaced by App\\Infra\\{$context}\\WideFunnel.")
        ->expectsOutputToContain("Retired {$context}/SortBins, replaced by {$context}/SortBinsTwice.")
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && in_array('--testsuite=Architecture,Unit,Feature', $process->command, true)
        && ($process->environment['APP_ENV'] ?? null) === false
        && ($process->environment['DB_DATABASE'] ?? null) === false);

    expect(File::isDirectory(app_path("Application/{$context}/UseCases/SortBins")))->toBeFalse()
        ->and(File::isDirectory(base_path("tests/Feature/Application/{$context}/UseCases/SortBins")))->toBeFalse()
        ->and(File::exists(app_path("Infra/{$context}/NarrowFunnel.php")))->toBeFalse()
        ->and(File::exists(base_path("tests/Feature/Infra/{$context}/NarrowFunnelTest.php")))->toBeFalse()
        ->and(File::exists(app_path("Application/{$context}/UseCases/SortBinsTwice/SortBinsTwiceHandler.php")))->toBeTrue()
        ->and(samplingRetireManifest()['useCases'])->toBe(['SortBinsTwice' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => []]])
        ->and(samplingRetireManifest()['ports'])->toBe(['Funnel' => ['layer' => 'domain', 'adapter' => "Infra/{$context}/WideFunnel"]]);
});

it('removes nothing when the tests fail', function () {
    $context = SAMPLING_RETIRE_CONTEXT;
    Process::fake(['*' => Process::result(output: 'FAILED  Tests\\Feature\\SomethingTest', exitCode: 1)]);

    $this->artisan('kit:retire', ['--context' => [$context]])
        ->expectsOutputToContain('The tests do not pass, so nothing was removed.')
        ->expectsOutputToContain('FAILED  Tests\\Feature\\SomethingTest')
        ->assertFailed();

    expect(File::isDirectory(app_path("Application/{$context}/UseCases/SortBins")))->toBeTrue()
        ->and(File::exists(app_path("Infra/{$context}/NarrowFunnel.php")))->toBeTrue()
        ->and(samplingRetireManifest()['useCases']['SortBinsTwice'])->toHaveKey('replaces');
});

it('keeps a replaced piece that something still names, and says where', function () {
    $context = SAMPLING_RETIRE_CONTEXT;
    File::put(app_path("Infra/{$context}/BinReport.php"), "<?php\n\nnamespace App\\Infra\\{$context};\n\nuse App\\Application\\{$context}\\UseCases\\SortBins\\SortBinsHandler;\n\nclass BinReport {}\n");
    Process::fake(['*' => Process::result('Tests: 12 passed')]);

    $this->artisan('kit:retire', ['--context' => [$context]])
        ->expectsOutputToContain("{$context}/SortBins is still named in app/Infra/{$context}/BinReport.php")
        ->expectsOutputToContain("Retired App\\Infra\\{$context}\\NarrowFunnel")
        ->assertSuccessful();

    expect(File::isDirectory(app_path("Application/{$context}/UseCases/SortBins")))->toBeTrue()
        ->and(File::exists(app_path("Infra/{$context}/NarrowFunnel.php")))->toBeFalse();
});

it('has nothing to retire while no replacement is swapped in', function () {
    $context = SAMPLING_RETIRE_CONTEXT;
    File::put(app_path("Http/Controllers/{$context}Controller.php"), "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Application\\{$context}\\UseCases\\SortBins\\SortBinsHandler;\n\nclass {$context}Controller {}\n");
    File::put(app_path("Providers/{$context}ServiceProvider.php"), "<?php\n\nnamespace App\\Providers;\n\nuse App\\Infra\\{$context}\\NarrowFunnel;\n\nclass {$context}ServiceProvider {}\n");
    Process::fake();

    $this->artisan('kit:retire', ['--context' => [$context]])
        ->expectsOutputToContain('Nothing to retire.')
        ->assertSuccessful();

    Process::assertNothingRan();
});

/**
 * The scratch context of the list cases, caught right after kit:apply swapped ListSamplingBarrels
 * for ListSamplingOakBarrels: the controller and the page read the new list, while the old one
 * still has its query adapter, its binding, its sort enum and its TypeScript twins.
 */
const SAMPLING_DELIST_CONTEXT = 'SamplingDelist';

/**
 * @return array<string, string> each fixture's path from the project root, and its contents
 */
function samplingDelistFixtures(): array
{
    $context = SAMPLING_DELIST_CONTEXT;
    $useCases = "App\\Application\\{$context}\\UseCases";
    $queries = 'App\\Infra\\Persistence\\Eloquent\\Queries';
    $list = fn (string $name): array => [
        "app/Application/{$context}/UseCases/{$name}/{$name}Command.php" => "<?php\n\nnamespace {$useCases}\\{$name};\n\nfinal class {$name}Command {}\n",
        "app/Application/{$context}/UseCases/{$name}/{$name}Handler.php" => "<?php\n\nnamespace {$useCases}\\{$name};\n\nfinal class {$name}Handler\n{\n    public function __construct(private readonly {$name}Query \$query) {}\n\n    public function __invoke({$name}Command \$command): void {}\n}\n",
        "app/Application/{$context}/UseCases/{$name}/{$name}Query.php" => "<?php\n\nnamespace {$useCases}\\{$name};\n\ninterface {$name}Query {}\n",
        "app/Infra/Persistence/Eloquent/Queries/Eloquent{$name}Query.php" => "<?php\n\nnamespace {$queries};\n\nuse {$useCases}\\{$name}\\{$name}Query;\n\nfinal class Eloquent{$name}Query implements {$name}Query {}\n",
        "tests/Feature/Infra/Persistence/Eloquent/Queries/Eloquent{$name}QueryTest.php" => "<?php\n",
        "tests/Feature/Application/{$context}/UseCases/{$name}/{$name}HandlerTest.php" => "<?php\n",
    ];

    return [
        ...$list('ListSamplingBarrels'),
        ...$list('ListSamplingOakBarrels'),
        "app/Application/{$context}/SamplingBarrelListSort.php" => "<?php\n\nnamespace App\\Application\\{$context};\n\nenum SamplingBarrelListSort: string\n{\n    case CreatedAt = 'created_at';\n}\n",
        "app/Http/Controllers/{$context}Controller.php" => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse {$useCases}\\ListSamplingOakBarrels\\ListSamplingOakBarrelsHandler;\n\nclass {$context}Controller\n{\n    public function index(ListSamplingOakBarrelsHandler \$listBarrels): void {}\n}\n",
        "app/Providers/{$context}ServiceProvider.php" => "<?php\n\nnamespace App\\Providers;\n\nuse {$useCases}\\ListSamplingBarrels\\ListSamplingBarrelsQuery;\nuse {$queries}\\EloquentListSamplingBarrelsQuery;\nuse Illuminate\\Support\\ServiceProvider;\n\nclass {$context}ServiceProvider extends ServiceProvider\n{\n    public array \$bindings = [\n        ListSamplingBarrelsQuery::class => EloquentListSamplingBarrelsQuery::class,\n        \\{$useCases}\\ListSamplingOakBarrels\\ListSamplingOakBarrelsQuery::class => \\{$queries}\\EloquentListSamplingOakBarrelsQuery::class,\n    ];\n}\n",
        'resources/js/types/sampling-barrel.ts' => "import type { DtFilters } from './data-table';\n\nexport type SamplingBarrelKind = 'oak' | 'steel';\n\n/** @see SamplingBarrelListRow */\nexport type SamplingBarrelRow = {\n    id: string;\n    kind: SamplingBarrelKind;\n};\n\nexport type SamplingBarrelFilters = DtFilters & {\n    kind: string | null;\n};\n\nexport type SamplingBarrelsQuery = SamplingBarrelFilters;\n",
        'resources/js/types/sampling-oak-barrel.ts' => "export type SamplingOakBarrelRow = {\n    id: string;\n};\n",
        'resources/js/pages/sampling-barrels/index.tsx' => "import type { SamplingOakBarrelRow } from '@/types';\n\nexport type Props = { rows: SamplingOakBarrelRow[] };\n",
    ];
}

function writeSamplingDelistFixtures(): void
{
    foreach (samplingDelistFixtures() as $relative => $contents) {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $contents);
    }

    (new StructureFiles(base_path()))->write([
        'context' => SAMPLING_DELIST_CONTEXT,
        'aggregates' => [],
        'services' => [],
        'ports' => [],
        'useCases' => [
            'ListSamplingBarrels' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => true, 'repositories' => []],
            'ListSamplingOakBarrels' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => true, 'repositories' => [], 'replaces' => 'ListSamplingBarrels'],
        ],
    ]);
}

function forgetSamplingDelist(): void
{
    $context = SAMPLING_DELIST_CONTEXT;

    foreach (["app/Application/{$context}", "tests/Feature/Application/{$context}", 'resources/js/pages/sampling-barrels'] as $folder) {
        File::deleteDirectory(base_path($folder));
    }

    File::delete([
        ...array_map(base_path(...), array_keys(samplingDelistFixtures())),
        base_path(".kit/structure/{$context}.json"),
        resource_path('js/hooks/use-sampling-barrels.ts'),
    ]);
}

describe('a list replacement', function () {
    beforeEach(function () {
        forgetSamplingDelist();
        writeSamplingDelistFixtures();
        Process::fake(['*' => Process::result('Tests: 12 passed')]);
    });

    afterEach(fn () => forgetSamplingDelist());

    it('removes the old list with its adapter, binding, sort enum and TypeScript twins', function () {
        $context = SAMPLING_DELIST_CONTEXT;

        $this->artisan('kit:retire', ['--context' => [$context]])
            ->expectsOutputToContain("Retired {$context}/ListSamplingBarrels, replaced by {$context}/ListSamplingOakBarrels.")
            ->assertSuccessful();

        expect(File::isDirectory(app_path("Application/{$context}/UseCases/ListSamplingBarrels")))->toBeFalse()
            ->and(File::isDirectory(base_path("tests/Feature/Application/{$context}/UseCases/ListSamplingBarrels")))->toBeFalse()
            ->and(File::exists(app_path('Infra/Persistence/Eloquent/Queries/EloquentListSamplingBarrelsQuery.php')))->toBeFalse()
            ->and(File::exists(base_path('tests/Feature/Infra/Persistence/Eloquent/Queries/EloquentListSamplingBarrelsQueryTest.php')))->toBeFalse()
            ->and(File::exists(app_path("Application/{$context}/SamplingBarrelListSort.php")))->toBeFalse()
            ->and(File::exists(app_path('Infra/Persistence/Eloquent/Queries/EloquentListSamplingOakBarrelsQuery.php')))->toBeTrue()
            ->and(File::get(app_path("Providers/{$context}ServiceProvider.php")))
            ->not->toContain('ListSamplingBarrelsQuery')
            ->toContain('ListSamplingOakBarrelsQuery::class')
            ->and(File::get(resource_path('js/types/sampling-barrel.ts')))->toBe("export type SamplingBarrelKind = 'oak' | 'steel';\n")
            ->and(samplingDelistManifest()['useCases'])->toBe(['ListSamplingOakBarrels' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => true, 'repositories' => []]]);

        Process::assertRan(fn ($process): bool => is_array($process->command) && $process->command[0] === 'vendor/bin/pint' && in_array("app/Providers/{$context}ServiceProvider.php", $process->command, true));
    });

    it('keeps the sort enum while another list sorts by it', function () {
        $context = SAMPLING_DELIST_CONTEXT;
        File::put(app_path("Application/{$context}/UseCases/ListSamplingOakBarrels/ListSamplingOakBarrelsCriteria.php"), "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\ListSamplingOakBarrels;\n\nuse App\\Application\\{$context}\\SamplingBarrelListSort;\n\nfinal class ListSamplingOakBarrelsCriteria\n{\n    public function __construct(public readonly SamplingBarrelListSort \$sort) {}\n}\n");

        $this->artisan('kit:retire', ['--context' => [$context]])->assertSuccessful();

        expect(File::isDirectory(app_path("Application/{$context}/UseCases/ListSamplingBarrels")))->toBeFalse()
            ->and(File::exists(app_path("Application/{$context}/SamplingBarrelListSort.php")))->toBeTrue();
    });

    it('keeps the old list while the frontend still reads its TypeScript twins, and says where', function () {
        $context = SAMPLING_DELIST_CONTEXT;
        File::put(resource_path('js/hooks/use-sampling-barrels.ts'), "import type { SamplingBarrelFilters } from '@/types';\n\nexport type Filters = SamplingBarrelFilters;\n");

        $this->artisan('kit:retire', ['--context' => [$context]])
            ->expectsOutputToContain("{$context}/ListSamplingBarrels is still named in resources/js/hooks/use-sampling-barrels.ts")
            ->expectsOutputToContain('Nothing to retire.')
            ->assertSuccessful();

        expect(File::isDirectory(app_path("Application/{$context}/UseCases/ListSamplingBarrels")))->toBeTrue()
            ->and(File::get(resource_path('js/types/sampling-barrel.ts')))->toContain('export type SamplingBarrelRow');
    });
});

/**
 * @return array<string, mixed>
 */
function samplingDelistManifest(): array
{
    return json_decode(File::get(base_path('.kit/structure/'.SAMPLING_DELIST_CONTEXT.'.json')), true);
}

/**
 * The scratch context of the domain service cases, caught right after kit:apply swapped the
 * service SealBox for SealBoxTwice: the handler calls the new one, and the old one still has its
 * folder and its Unit test.
 */
const SAMPLING_UNSERVICE_CONTEXT = 'SamplingUnservice';

function writeSamplingUnserviceFixtures(): void
{
    $context = SAMPLING_UNSERVICE_CONTEXT;
    $service = fn (string $name): array => [
        "app/Domain/{$context}/Services/{$name}/{$name}Service.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\{$name};\n\nfinal class {$name}Service\n{\n    public function handle(): void {}\n}\n",
        "tests/Unit/Domain/{$context}/Services/{$name}/{$name}ServiceTest.php" => "<?php\n",
    ];
    $fixtures = [
        ...$service('SealBox'),
        ...$service('SealBoxTwice'),
        "app/Application/{$context}/UseCases/SealBoxHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases;\n\nuse App\\Domain\\{$context}\\Services\\SealBoxTwice\\SealBoxTwiceService;\n\nfinal class SealBoxHandler\n{\n    public function __construct(private readonly SealBoxTwiceService \$sealBox) {}\n\n    public function __invoke(): void {}\n}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $contents);
    }

    (new StructureFiles(base_path()))->write([
        'context' => $context,
        'aggregates' => [],
        'services' => [
            'SealBox' => ['shape' => 'plain', 'creates' => null, 'repositories' => []],
            'SealBoxTwice' => ['shape' => 'plain', 'creates' => null, 'repositories' => [], 'replaces' => 'SealBox'],
        ],
        'ports' => [],
        'useCases' => [
            'SealBox' => ['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => []],
        ],
    ]);
}

function forgetSamplingUnservice(): void
{
    $context = SAMPLING_UNSERVICE_CONTEXT;

    foreach (["app/Domain/{$context}", "app/Application/{$context}", "tests/Unit/Domain/{$context}"] as $folder) {
        File::deleteDirectory(base_path($folder));
    }

    File::delete(base_path(".kit/structure/{$context}.json"));
}

describe('a domain service replacement', function () {
    beforeEach(function () {
        forgetSamplingUnservice();
        writeSamplingUnserviceFixtures();
        Process::fake(['*' => Process::result('Tests: 12 passed')]);
    });

    afterEach(fn () => forgetSamplingUnservice());

    it('removes the old service\'s folder and its test once nothing names it', function () {
        $context = SAMPLING_UNSERVICE_CONTEXT;

        $this->artisan('kit:retire', ['--context' => [$context]])
            ->expectsOutputToContain("Retired {$context}/SealBox, replaced by {$context}/SealBoxTwice.")
            ->assertSuccessful();

        expect(File::isDirectory(app_path("Domain/{$context}/Services/SealBox")))->toBeFalse()
            ->and(File::isDirectory(base_path("tests/Unit/Domain/{$context}/Services/SealBox")))->toBeFalse()
            ->and(File::exists(app_path("Domain/{$context}/Services/SealBoxTwice/SealBoxTwiceService.php")))->toBeTrue()
            ->and(json_decode(File::get(base_path(".kit/structure/{$context}.json")), true)['services'])
            ->toBe(['SealBoxTwice' => ['shape' => 'plain', 'creates' => null, 'repositories' => [], 'exception' => false]]);
    });

    it('keeps the old service while a test still names it, and says where', function () {
        $context = SAMPLING_UNSERVICE_CONTEXT;
        File::ensureDirectoryExists(base_path("tests/Unit/Domain/{$context}/Services/SealBoxTwice"));
        File::put(base_path("tests/Unit/Domain/{$context}/Services/SealBoxTwice/SealBoxTwiceServiceTest.php"), "<?php\n\nuse App\\Domain\\{$context}\\Services\\SealBox\\SealBoxService;\n");

        $this->artisan('kit:retire', ['--context' => [$context]])
            ->expectsOutputToContain("{$context}/SealBox is still named in tests/Unit/Domain/{$context}/Services/SealBoxTwice/SealBoxTwiceServiceTest.php")
            ->expectsOutputToContain('Nothing to retire.')
            ->assertSuccessful();

        expect(File::isDirectory(app_path("Domain/{$context}/Services/SealBox")))->toBeTrue();
    });
});
