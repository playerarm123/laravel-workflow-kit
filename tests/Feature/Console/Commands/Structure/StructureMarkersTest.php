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

        it('writes a menu entry above // kit:menu in the main menu under app/Http, and only once', function () {
            $navigation = "<?php\n\nfinal class Navigation\n{\n    private function items(): array\n    {\n        return array_values(array_filter([\n            \$this->item('common.dashboard', 'dashboard', 'layout-grid'),\n            // kit:menu\n        ]));\n    }\n}\n";
            writeSamplingMarkersFile('app/Http/Navigation.php', $navigation);
            writeSamplingMarkersFile('app/Http/Middleware/Other.php', "<?php\n// kit:menu is mentioned here, not marked\n");
            $markers = new StructureMarkers(samplingMarkersRoot());
            $line = "\$this->item('crates.title', 'crates.index', 'package', \\App\\Models\\Crate::class),";

            expect($markers->insert(StructureMarkers::MENU, $line))->toBe(StructureMarkers::WRITTEN)
                ->and($markers->insert(StructureMarkers::MENU, $line))->toBe(StructureMarkers::PRESENT)
                ->and($markers->fileOf(StructureMarkers::MENU))->toBe('app/Http/Navigation.php')
                ->and(File::get(samplingMarkersRoot().'/app/Http/Navigation.php'))->toContain("            \$this->item('common.dashboard', 'dashboard', 'layout-grid'),\n            {$line}\n            // kit:menu\n");
        });

        it('writes no menu entry where there is no main menu', function () {
            expect((new StructureMarkers(samplingMarkersRoot()))->insert(StructureMarkers::MENU, "\$this->item('x.title', 'x.index', 'box'),"))->toBe(StructureMarkers::NO_MARKER);
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

    describe('a resource route', function () {
        it('widens the only() of a resource a route file registers, in resource order, and leaves one that covers every method', function () {
            File::put(samplingMarkersRoot().'/routes/web.php', "<?php\n\nRoute::group(function () {\n    Route::resource('bins', \\App\\Http\\Controllers\\BinController::class)->only(['index', 'store'])->names('bins');\n    // kit:routes\n});\n");
            $markers = new StructureMarkers(samplingMarkersRoot());
            $line = "Route::resource('bins', \\App\\Http\\Controllers\\BinController::class)->only(['index', 'create', 'store', 'edit', 'update']);";

            expect($markers->resourceMethods('BinController'))->toBe(['index', 'store'])
                ->and($markers->insert(StructureMarkers::ROUTES, $line))->toBe(StructureMarkers::WIDENED)
                ->and(File::get(samplingMarkersRoot().'/routes/web.php'))->toContain("->only(['index', 'create', 'store', 'edit', 'update'])->names('bins');\n    // kit:routes")
                ->and($markers->resourceMethods('BinController'))->toBe(['index', 'create', 'store', 'edit', 'update'])
                ->and($markers->insert(StructureMarkers::ROUTES, $line))->toBe(StructureMarkers::PRESENT);
        });

        it('reads a resource with no only() as every method, and leaves one an except() holds back to a person', function () {
            File::put(samplingMarkersRoot().'/routes/web.php', "<?php\n\nRoute::resource('bins', BinController::class)->except(['destroy']);\n// kit:routes\n");
            $markers = new StructureMarkers(samplingMarkersRoot());

            expect($markers->resourceMethods('BinController'))->toBe(['index', 'create', 'store', 'show', 'edit', 'update'])
                ->and($markers->insert(StructureMarkers::ROUTES, "Route::resource('bins', \\App\\Http\\Controllers\\BinController::class)->only(['index', 'destroy']);"))->toBe(StructureMarkers::BY_HAND)
                ->and($markers->resourceMethods('OtherController'))->toBeNull();
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
