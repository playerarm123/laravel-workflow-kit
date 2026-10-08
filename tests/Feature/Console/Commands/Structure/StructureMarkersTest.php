<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;

/**
 * A project of its own under storage, so no case ever writes into the workbench app's providers or routes.
 */
function samplingMarkersRoot(): string
{
    return storage_path('framework/testing/sampling-markers');
}

function writeSamplingMarkersFile(string $relative, string $contents): void
{
    $path = samplingMarkersRoot().'/'.$relative;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

function samplingMarkersProvider(): string
{
    return "<?php\n\nclass AppServiceProvider\n{\n    public array \$bindings = [\n        A::class => B::class,\n        // kit:bindings\n    ];\n}\n";
}

beforeEach(function () {
    File::deleteDirectory(samplingMarkersRoot());
    writeSamplingMarkersFile('app/Providers/AppServiceProvider.php', samplingMarkersProvider());
    writeSamplingMarkersFile('routes/web.php', "<?php\n\nRoute::group(function () {\n    // kit:routes\n});\n");
});

afterEach(fn () => File::deleteDirectory(samplingMarkersRoot()));

describe('StructureMarkers', function () {
    describe('insert', function () {
        it('writes the line above its marker with the marker\'s indent', function () {
            $markers = new StructureMarkers(samplingMarkersRoot());

            expect($markers->insert(StructureMarkers::BINDINGS, '\\C::class => \\D::class,'))->toBe(StructureMarkers::WRITTEN)
                ->and($markers->insert(StructureMarkers::ROUTES, "Route::get('x', \\X::class);"))->toBe(StructureMarkers::WRITTEN)
                ->and(File::get(samplingMarkersRoot().'/app/Providers/AppServiceProvider.php'))->toContain("        A::class => B::class,\n        \\C::class => \\D::class,\n        // kit:bindings\n")
                ->and(File::get(samplingMarkersRoot().'/routes/web.php'))->toContain("    Route::get('x', \\X::class);\n    // kit:routes\n");
        });

        it('leaves a line that is already there alone', function () {
            $markers = new StructureMarkers(samplingMarkersRoot());

            expect($markers->insert(StructureMarkers::BINDINGS, 'A::class => B::class,'))->toBe(StructureMarkers::PRESENT)
                ->and(File::get(samplingMarkersRoot().'/app/Providers/AppServiceProvider.php'))->toBe(samplingMarkersProvider());
        });

        it('writes nothing when no file holds the marker', function () {
            File::put(samplingMarkersRoot().'/routes/web.php', "<?php\n");

            expect((new StructureMarkers(samplingMarkersRoot()))->insert(StructureMarkers::ROUTES, "Route::get('x', \\X::class);"))->toBe(StructureMarkers::NO_MARKER)
                ->and(File::get(samplingMarkersRoot().'/routes/web.php'))->toBe("<?php\n");
        });

        it('writes nothing when more than one file holds the marker', function () {
            writeSamplingMarkersFile('app/Infra/OtherServiceProvider.php', samplingMarkersProvider());

            expect((new StructureMarkers(samplingMarkersRoot()))->insert(StructureMarkers::BINDINGS, '\\C::class => \\D::class,'))->toBe(StructureMarkers::MANY_MARKERS)
                ->and(File::get(samplingMarkersRoot().'/app/Providers/AppServiceProvider.php'))->toBe(samplingMarkersProvider());
        });
    });

    describe('contains and matches', function () {
        it('looks through every file the marker may sit in', function () {
            writeSamplingMarkersFile('routes/admin.php', "<?php\n\nRoute::resource('boxes', \\App\\Http\\Controllers\\BoxController::class);\n");
            $markers = new StructureMarkers(samplingMarkersRoot());

            expect($markers->contains(StructureMarkers::ROUTES, "Route::resource('boxes'"))->toBeTrue()
                ->and($markers->matches(StructureMarkers::ROUTES, '/\bBoxController::class/'))->toBeTrue()
                ->and($markers->matches(StructureMarkers::ROUTES, '/\bCrateController::class/'))->toBeFalse()
                ->and($markers->contains(StructureMarkers::BINDINGS, 'A::class => B::class,'))->toBeTrue();
        });
    });

    describe('fileOf', function () {
        it('names the one file that holds the marker', function () {
            expect((new StructureMarkers(samplingMarkersRoot()))->fileOf(StructureMarkers::ROUTES))->toBe('routes/web.php');
        });

        it('names none when no file holds it', function () {
            File::put(samplingMarkersRoot().'/routes/web.php', "<?php\n");

            expect((new StructureMarkers(samplingMarkersRoot()))->fileOf(StructureMarkers::ROUTES))->toBeNull();
        });
    });
});
