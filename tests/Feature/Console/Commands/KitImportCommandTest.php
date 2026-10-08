<?php

use Illuminate\Support\Facades\File;

/**
 * The scratch context of this file. It carries `Sampling`, so no Architecture check reads it or
 * its manifest, and differs from every other generator test file's, so --parallel never deletes it
 * mid-run. StructureReaderTest covers every kind and shape the reader reads; this file covers what
 * the command does with the files.
 */
const SAMPLING_IMPORT_CONTEXT = 'SamplingImport';

function samplingImportManifest(): string
{
    return base_path('.kit/structure/'.SAMPLING_IMPORT_CONTEXT.'.json');
}

function writeSamplingImportFixture(): void
{
    $path = app_path('Domain/'.SAMPLING_IMPORT_CONTEXT.'/Bin/BinEntity.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, "<?php\n\nnamespace App\\Domain\\".SAMPLING_IMPORT_CONTEXT."\\Bin;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class BinEntity extends AggregateRoot\n{\n    public function id(): string { return 'bin'; }\n\n    public static function entityName(): string { return 'Bin'; }\n}\n");
}

/**
 * A controller named after no model, so it is an HTTP resource of its own.
 */
function writeSamplingImportController(): void
{
    File::put(app_path('Http/Controllers/'.SAMPLING_IMPORT_CONTEXT.'Controller.php'), "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass ".SAMPLING_IMPORT_CONTEXT."Controller\n{\n    public function index(): void {}\n}\n");
}

function samplingImportResourceManifest(): string
{
    return base_path('.kit/structure/http/'.SAMPLING_IMPORT_CONTEXT.'.json');
}

function forgetSamplingImport(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_IMPORT_CONTEXT));
    File::delete([samplingImportManifest(), samplingImportResourceManifest(), app_path('Http/Controllers/'.SAMPLING_IMPORT_CONTEXT.'Controller.php')]);
}

beforeEach(function () {
    forgetSamplingImport();
    writeSamplingImportFixture();
    writeSamplingImportController();
});

afterEach(fn () => forgetSamplingImport());

it('writes the manifest of a context from its code', function () {
    $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT]])
        ->expectsOutputToContain('.kit/structure/'.SAMPLING_IMPORT_CONTEXT.'.json] written')
        ->assertSuccessful();

    expect(json_decode(File::get(samplingImportManifest()), true))->toBe([
        'context' => SAMPLING_IMPORT_CONTEXT,
        'aggregates' => ['Bin' => ['children' => [], 'repository' => false]],
        'services' => [],
        'ports' => [],
        'useCases' => [],
        'enums' => [],
        'valueObjects' => [],
        'exceptions' => [],
        'entities' => [],
    ])->and(File::get(samplingImportManifest()))->toEndWith("}\n");
});

it('keeps a manifest that exists, since it may hold design not built yet', function () {
    File::ensureDirectoryExists(dirname(samplingImportManifest()));
    File::put(samplingImportManifest(), 'designed by hand');

    $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT]])
        ->expectsOutputToContain('already exists, kept as it is')
        ->assertSuccessful();

    expect(File::get(samplingImportManifest()))->toBe('designed by hand');
});

it('reads an existing manifest back from the code when forced', function () {
    File::ensureDirectoryExists(dirname(samplingImportManifest()));
    File::put(samplingImportManifest(), 'designed by hand');

    $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT], '--force' => true])->assertSuccessful();

    expect(json_decode(File::get(samplingImportManifest()), true)['aggregates'])->toBe(['Bin' => ['children' => [], 'repository' => false]]);
});

it('writes the manifest of an HTTP resource, and only what was named', function () {
    $this->artisan('kit:import', ['--resource' => [SAMPLING_IMPORT_CONTEXT]])
        ->expectsOutputToContain('.kit/structure/http/'.SAMPLING_IMPORT_CONTEXT.'.json] written')
        ->assertSuccessful();

    expect(json_decode(File::get(samplingImportResourceManifest()), true))->toBe([
        'resource' => SAMPLING_IMPORT_CONTEXT,
        'model' => null,
        'controller' => ['index' => []],
        'actions' => [],
        'policy' => null,
        'pages' => [],
    ])->and(File::exists(samplingImportManifest()))->toBeFalse();
});

it('refuses a context that has no folder', function () {
    $this->artisan('kit:import', ['--context' => ['SamplingImportNowhere']])
        ->expectsOutputToContain('No context or HTTP resource named SamplingImportNowhere')
        ->assertFailed();

    expect(File::exists(base_path('.kit/structure/SamplingImportNowhere.json')))->toBeFalse();
});

describe('--sync', function () {
    beforeEach(function () {
        File::ensureDirectoryExists(dirname(samplingImportManifest()));
        File::put(samplingImportManifest(), (string) json_encode([
            'context' => SAMPLING_IMPORT_CONTEXT,
            'aggregates' => [
                'Bin' => ['children' => [], 'repository' => true],
                'Crate' => ['children' => [], 'repository' => false],
            ],
            'services' => [], 'ports' => [], 'useCases' => [], 'enums' => [], 'valueObjects' => [], 'exceptions' => [], 'entities' => [],
        ]));
    });

    it('takes what the code has, keeps what is not built yet, and says which is which', function () {
        $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT], '--sync' => true])
            ->expectsOutputToContain('.kit/structure/'.SAMPLING_IMPORT_CONTEXT.'.json] synced: 1 change')
            ->expectsOutputToContain('aggregates.Bin: repository true → false')
            ->expectsOutputToContain('Kept in [.kit/structure/'.SAMPLING_IMPORT_CONTEXT.'.json] but not in the code')
            ->expectsOutputToContain('aggregates.Crate')
            ->assertSuccessful();

        expect(json_decode(File::get(samplingImportManifest()), true)['aggregates'])->toBe([
            'Bin' => ['children' => [], 'repository' => false],
            'Crate' => ['children' => [], 'repository' => false],
        ]);
    });

    it('writes nothing on a dry run', function () {
        $before = File::get(samplingImportManifest());

        $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT], '--sync' => true, '--dry-run' => true])
            ->expectsOutputToContain('would be synced: 1 change')
            ->assertSuccessful();

        expect(File::get(samplingImportManifest()))->toBe($before);
    });

    it('takes out what the code does not have when it prunes', function () {
        $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT], '--sync' => true, '--prune' => true])
            ->expectsOutputToContain('aggregates.Crate: removed')
            ->assertSuccessful();

        expect(json_decode(File::get(samplingImportManifest()), true)['aggregates'])->toBe(['Bin' => ['children' => [], 'repository' => false]]);
    });

    it('says when nothing changes, and still writes a manifest that did not exist', function () {
        $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT], '--sync' => true, '--prune' => true])->assertSuccessful();

        $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT], '--resource' => [SAMPLING_IMPORT_CONTEXT], '--sync' => true])
            ->expectsOutputToContain('.kit/structure/'.SAMPLING_IMPORT_CONTEXT.'.json] unchanged')
            ->expectsOutputToContain('.kit/structure/http/'.SAMPLING_IMPORT_CONTEXT.'.json] written')
            ->assertSuccessful();
    });

    it('takes an HTTP resource\'s entries from the code', function () {
        File::ensureDirectoryExists(dirname(samplingImportResourceManifest()));
        File::put(samplingImportResourceManifest(), (string) json_encode([
            'resource' => SAMPLING_IMPORT_CONTEXT, 'model' => null, 'controller' => ['index' => ['Billing/ListInvoices']], 'actions' => [], 'policy' => null, 'pages' => [],
        ]));

        $this->artisan('kit:import', ['--resource' => [SAMPLING_IMPORT_CONTEXT], '--sync' => true])
            ->expectsOutputToContain('controller.index: ["Billing/ListInvoices"] → []')
            ->assertSuccessful();

        expect(json_decode(File::get(samplingImportResourceManifest()), true)['controller'])->toBe(['index' => []]);
    });

    it('refuses --sync with --force, and --prune or --dry-run without --sync', function (array $options, string $message) {
        $before = File::get(samplingImportManifest());

        $this->artisan('kit:import', ['--context' => [SAMPLING_IMPORT_CONTEXT], ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(File::get(samplingImportManifest()))->toBe($before);
    })->with([
        'sync and force' => [['--sync' => true, '--force' => true], 'Pick one: --force reads the whole file back from the code, --sync merges the code into it.'],
        'prune alone' => [['--prune' => true], '--prune and --dry-run go with --sync.'],
        'dry run alone' => [['--dry-run' => true], '--prune and --dry-run go with --sync.'],
    ]);
});
