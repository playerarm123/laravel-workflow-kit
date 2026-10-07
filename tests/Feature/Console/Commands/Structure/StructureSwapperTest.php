<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureSwapper;

/**
 * A project of its own under storage, so no case touches motto's code.
 */
function samplingSwapRoot(): string
{
    return storage_path('framework/testing/sampling-swap');
}

function writeSamplingSwapFile(string $relative, string $contents): void
{
    File::ensureDirectoryExists(dirname(samplingSwapRoot().'/'.$relative));
    File::put(samplingSwapRoot().'/'.$relative, $contents);
}

function samplingSwapFile(string $relative): string
{
    return File::get(samplingSwapRoot().'/'.$relative);
}

const SAMPLING_SWAP_OLD = 'App\\Application\\Ship\\UseCases\\PackCrate\\PackCrateHandler';

const SAMPLING_SWAP_NEW = 'App\\Application\\Ship\\UseCases\\PackCrateTwice\\PackCrateTwiceHandler';

beforeEach(function () {
    File::deleteDirectory(samplingSwapRoot());
    writeSamplingSwapFile('app/Http/Controllers/CrateController.php', "<?php\n\nuse App\\Application\\Ship\\UseCases\\PackCrate\\PackCrateHandler;\nuse App\\Application\\Ship\\UseCases\\PackCrateLater\\PackCrateLaterHandler;\n\nclass CrateController\n{\n    public function store(PackCrateHandler \$packCrate, PackCrateLaterHandler \$later): void {}\n}\n");
    writeSamplingSwapFile('app/Http/Controllers/CrateInlineController.php', "<?php\n\nclass CrateInlineController\n{\n    public function store(\\App\\Application\\Ship\\UseCases\\PackCrate\\PackCrateHandler \$packCrate): void {}\n}\n");
    writeSamplingSwapFile('app/Http/Controllers/BoxController.php', "<?php\n\nclass BoxController {}\n");
    writeSamplingSwapFile('app/Application/Ship/UseCases/PackCrate/PackCrateHandler.php', "<?php\n\nnamespace App\\Application\\Ship\\UseCases\\PackCrate;\n\nfinal class PackCrateHandler {}\n");
});

afterEach(fn () => File::deleteDirectory(samplingSwapRoot()));

describe('StructureSwapper', function () {
    describe('swap', function () {
        it('points the use line, the short name and the inline name at the replacement', function () {
            $changed = (new StructureSwapper(samplingSwapRoot()))->swap([SAMPLING_SWAP_OLD => SAMPLING_SWAP_NEW], ['app/Http']);

            expect($changed)->toBe(['app/Http/Controllers/CrateController.php', 'app/Http/Controllers/CrateInlineController.php'])
                ->and(samplingSwapFile('app/Http/Controllers/CrateController.php'))
                ->toContain('use '.SAMPLING_SWAP_NEW.';')
                ->toContain('PackCrateTwiceHandler $packCrate')
                ->toContain('use App\\Application\\Ship\\UseCases\\PackCrateLater\\PackCrateLaterHandler;')
                ->toContain('PackCrateLaterHandler $later')
                ->not->toContain('use '.SAMPLING_SWAP_OLD.';')
                ->and(samplingSwapFile('app/Http/Controllers/CrateInlineController.php'))->toContain('\\'.SAMPLING_SWAP_NEW.' $packCrate')
                ->and(samplingSwapFile('app/Http/Controllers/BoxController.php'))->toBe("<?php\n\nclass BoxController {}\n");
        });

        it('leaves the old class\'s own files alone', function () {
            $changed = (new StructureSwapper(samplingSwapRoot()))->swap([SAMPLING_SWAP_OLD => SAMPLING_SWAP_NEW], ['app'], ['app/Application/Ship/UseCases/PackCrate']);

            expect($changed)->not->toContain('app/Application/Ship/UseCases/PackCrate/PackCrateHandler.php')
                ->and(samplingSwapFile('app/Application/Ship/UseCases/PackCrate/PackCrateHandler.php'))->toContain('final class PackCrateHandler');
        });
    });

    describe('references', function () {
        it('names the files that still name a class, outside the paths left alone', function () {
            $swapper = new StructureSwapper(samplingSwapRoot());

            expect($swapper->references([SAMPLING_SWAP_OLD], ['app'], ['app/Application/Ship/UseCases/PackCrate']))
                ->toBe(['app/Http/Controllers/CrateController.php', 'app/Http/Controllers/CrateInlineController.php']);
        });
    });

    describe('references with bindings', function () {
        it('reads past the line that binds the old piece\'s port, written with short or full names', function () {
            $port = 'App\\Application\\Ship\\UseCases\\ListCrates\\ListCratesQuery';
            $adapter = 'App\\Infra\\Persistence\\Eloquent\\Queries\\EloquentListCratesQuery';
            writeSamplingSwapFile('app/Providers/ShipServiceProvider.php', "<?php\n\nuse {$port};\nuse {$adapter};\n\nclass ShipServiceProvider\n{\n    public array \$bindings = [\n        ListCratesQuery::class => EloquentListCratesQuery::class,\n        \\{$port}::class => \\{$adapter}::class,\n    ];\n}\n");
            $swapper = new StructureSwapper(samplingSwapRoot());

            expect($swapper->references([$port], ['app/Providers']))->toBe(['app/Providers/ShipServiceProvider.php'])
                ->and($swapper->references([$port, $adapter], ['app/Providers'], [], [$port => $adapter]))->toBe([]);
        });
    });

    describe('unbind', function () {
        it('takes the binding line out, with the use lines nothing else names', function () {
            $port = 'App\\Application\\Ship\\UseCases\\ListCrates\\ListCratesQuery';
            $adapter = 'App\\Infra\\Persistence\\Eloquent\\Queries\\EloquentListCratesQuery';
            writeSamplingSwapFile('app/Providers/ShipServiceProvider.php', "<?php\n\nuse App\\Domain\\Ship\\Ports\\Scale;\nuse {$port};\nuse {$adapter};\n\nclass ShipServiceProvider\n{\n    public array \$bindings = [\n        Scale::class => DigitalScale::class,\n        ListCratesQuery::class => EloquentListCratesQuery::class,\n    ];\n}\n");

            expect((new StructureSwapper(samplingSwapRoot()))->unbind($port, $adapter, ['app']))->toBe(['app/Providers/ShipServiceProvider.php'])
                ->and(samplingSwapFile('app/Providers/ShipServiceProvider.php'))->toBe("<?php\n\nuse App\\Domain\\Ship\\Ports\\Scale;\n\nclass ShipServiceProvider\n{\n    public array \$bindings = [\n        Scale::class => DigitalScale::class,\n    ];\n}\n");
        });

        it('keeps a use line the file still names elsewhere', function () {
            $port = 'App\\Application\\Ship\\UseCases\\ListCrates\\ListCratesQuery';
            $adapter = 'App\\Infra\\Persistence\\Eloquent\\Queries\\EloquentListCratesQuery';
            $provider = "<?php\n\nuse {$port};\nuse {$adapter};\n\nclass ShipServiceProvider\n{\n    public array \$bindings = [\n        ListCratesQuery::class => EloquentListCratesQuery::class,\n    ];\n\n    public array \$singletons = [ListCratesQuery::class];\n}\n";

            expect(StructureSwapper::unbound($provider, $port, $adapter))->toContain("use {$port};")->not->toContain("use {$adapter};")->not->toContain('=> EloquentListCratesQuery');
        });
    });

    describe('swapNames', function () {
        it('renames whole names in the TypeScript files only', function () {
            writeSamplingSwapFile('resources/js/pages/crates/index.tsx', "import type { CrateFilters, CrateRow, CrateRowActions } from '@/types';\n\nconst rows: CrateRow[] = [];\n");
            writeSamplingSwapFile('resources/js/pages/crates/notes.php', "<?php // CrateRow\n");

            expect((new StructureSwapper(samplingSwapRoot()))->swapNames(['CrateRow' => 'OpenCrateRow', 'CrateFilters' => 'OpenCrateFilters'], ['resources/js/pages']))->toBe(['resources/js/pages/crates/index.tsx'])
                ->and(samplingSwapFile('resources/js/pages/crates/index.tsx'))->toBe("import type { OpenCrateFilters, OpenCrateRow, CrateRowActions } from '@/types';\n\nconst rows: OpenCrateRow[] = [];\n")
                ->and(samplingSwapFile('resources/js/pages/crates/notes.php'))->toBe("<?php // CrateRow\n");
        });

        it('names the files that still name one of the names', function () {
            writeSamplingSwapFile('resources/js/pages/crates/index.tsx', "const rows: CrateRow[] = [];\n");
            writeSamplingSwapFile('resources/js/pages/bins/index.tsx', "const rows: CrateRowActions[] = [];\n");

            expect((new StructureSwapper(samplingSwapRoot()))->nameReferences(['CrateRow'], ['resources/js']))->toBe(['resources/js/pages/crates/index.tsx']);
        });
    });

    describe('removeTypes', function () {
        it('takes each type out with its docblock and the imports nothing else names', function () {
            writeSamplingSwapFile('resources/js/types/crate.ts', "import type { DtQuery } from '@/hooks/use-data-table';\nimport type { DtFilters } from './data-table';\n\n/** @see Crate */\nexport type CrateStatus = 'open' | 'shut';\n\n/**\n * @see CrateListRow\n */\nexport type CrateRow = {\n    id: string;\n    status: CrateStatus;\n};\n\n/** @see ListCratesCriteria */\nexport type CrateFilters = DtFilters & {\n    status: string | null;\n};\n\nexport type CratesQuery = CrateFilters & DtQuery;\n\n/** @see OpenCrateListRow */\nexport type OpenCrateRow = {\n    id: string;\n};\n");
            $swapper = new StructureSwapper(samplingSwapRoot());

            expect($swapper->declaringFile('CrateFilters', 'resources/js/types'))->toBe('resources/js/types/crate.ts')
                ->and($swapper->removeTypes('resources/js/types/crate.ts', ['CrateRow', 'CrateFilters', 'CratesQuery']))->toBe(['resources/js/types/crate.ts'])
                ->and(samplingSwapFile('resources/js/types/crate.ts'))->toBe("/** @see Crate */\nexport type CrateStatus = 'open' | 'shut';\n\n/** @see OpenCrateListRow */\nexport type OpenCrateRow = {\n    id: string;\n};\n")
                ->and($swapper->declaringFile('CrateFilters', 'resources/js/types'))->toBeNull();
        });

        it('removes a file left with no export, and its line in the barrel', function () {
            writeSamplingSwapFile('resources/js/types/crate.ts', "import type { DtFilters } from './data-table';\n\nexport type CrateRow = {\n    id: string;\n};\n\nexport type CrateFilters = DtFilters;\n");
            writeSamplingSwapFile('resources/js/types/index.ts', "export type * from './bin';\nexport type * from './crate';\n");

            expect((new StructureSwapper(samplingSwapRoot()))->removeTypes('resources/js/types/crate.ts', ['CrateRow', 'CrateFilters']))->toBe(['resources/js/types/crate.ts', 'resources/js/types/index.ts'])
                ->and(File::exists(samplingSwapRoot().'/resources/js/types/crate.ts'))->toBeFalse()
                ->and(samplingSwapFile('resources/js/types/index.ts'))->toBe("export type * from './bin';\n");
        });
    });
});
