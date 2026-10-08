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
