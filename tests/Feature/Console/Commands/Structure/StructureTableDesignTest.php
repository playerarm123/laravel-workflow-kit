<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureComparer;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureEditor;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureGraph;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTableDesign;

/**
 * The scratch context of this file, with its own migrations folder. It carries `Sampling`, so no
 * Architecture check reads it, and differs from every other test file's.
 */
const SAMPLING_PIERS_CONTEXT = 'SamplingPiers';

function samplingPiersMigrations(): string
{
    return storage_path('framework/testing/'.SAMPLING_PIERS_CONTEXT.'-migrations');
}

function samplingPiersEditor(): StructureEditor
{
    return new StructureEditor(new StructureFiles(base_path()), new StructureReader(base_path(), samplingPiersMigrations()));
}

function samplingPiersVersion(): string
{
    return (new StructureFiles(base_path()))->version(SAMPLING_PIERS_CONTEXT);
}

/**
 * @return array<string, mixed>
 */
function samplingPiersManifest(): array
{
    return (array) (new StructureFiles(base_path()))->read(SAMPLING_PIERS_CONTEXT);
}

/**
 * A Pier root with a Berth child, whose state covers each way a property is proposed, and a
 * Vessel the pier refers to by id.
 */
function writeSamplingPiers(): void
{
    $entity = fn (string $aggregate, array $state): array => ['aggregate' => $aggregate, 'state' => $state, 'behaviours' => [], 'assertions' => []];

    (new StructureFiles(base_path()))->write([
        'context' => SAMPLING_PIERS_CONTEXT,
        'aggregates' => ['Pier' => ['children' => ['Berth'], 'repository' => true], 'Vessel' => ['children' => [], 'repository' => true]],
        'services' => [],
        'applicationServices' => [],
        'ports' => [],
        'useCases' => [],
        'enums' => [
            'PierStatus' => ['aggregate' => 'Pier', 'backing' => 'string', 'cases' => ['Open' => 'open', 'UnderRepair' => 'under_repair'], 'transitions' => null],
            'PierSide' => ['aggregate' => 'Pier', 'backing' => null, 'cases' => ['North' => null, 'South' => null], 'transitions' => null],
        ],
        'valueObjects' => [],
        'exceptions' => [],
        'entities' => [
            'Pier' => $entity('Pier', [
                'name' => 'string',
                'status' => 'PierStatus',
                'fee' => 'Shared/Money',
                'openedAt' => '?DateTimeImmutable',
                'opensOn' => 'Shared/CalendarDate',
                'vesselId' => 'string',
                'details' => 'array',
                'weight' => 'float',
                'side' => 'PierSide',
                'berths' => 'array',
            ]),
            'Berth' => $entity('Pier', ['label' => 'string', 'position' => 'int']),
        ],
        'tables' => [],
    ]);
}

function forgetSamplingPiers(): void
{
    File::deleteDirectory(samplingPiersMigrations());
    File::delete(base_path(StructureFiles::DIRECTORY.'/'.SAMPLING_PIERS_CONTEXT.'.json'));
}

beforeEach(function () {
    forgetSamplingPiers();
    File::ensureDirectoryExists(samplingPiersMigrations());
    writeSamplingPiers();
});

afterEach(fn () => forgetSamplingPiers());

describe('proposedTable', function () {
    it('proposes the root\'s table from its state the way migrations.md stores each type', function () {
        expect(samplingPiersEditor()->proposedTable(SAMPLING_PIERS_CONTEXT, 'Pier'))->toBe([
            'table' => [
                'name' => 'piers',
                'model' => 'Pier',
                'columns' => [
                    'name' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'length' => 20, 'enum' => 'PierStatus'],
                    'fee' => ['type' => 'unsignedBigInteger', 'comment' => 'in minor units'],
                    'opened_at' => ['type' => 'timestamp', 'nullable' => true, 'comment' => StructureTableDesign::NULL_TODO],
                    'opens_on' => ['type' => 'date'],
                    'details' => ['type' => 'jsonb'],
                ],
                'foreignKeys' => ['vessel_id' => ['table' => 'vessels', 'index' => true]],
                'indexes' => [],
                'timestamps' => true,
                'softDeletes' => false,
            ],
            'unmapped' => [
                'weight' => 'a float cannot be stored exactly: hold it as Money, a Percent or a scaled integer',
                'side' => 'a pure enum has no value to store',
            ],
        ]);
    });

    it('proposes a child\'s table with a foreign key to the root that cascades', function () {
        expect(samplingPiersEditor()->proposedTable(SAMPLING_PIERS_CONTEXT, 'Berth')['table'])->toMatchArray([
            'name' => 'berths',
            'model' => 'Berth',
            'columns' => ['label' => ['type' => 'string'], 'position' => ['type' => 'integer']],
            'foreignKeys' => ['pier_id' => ['table' => 'piers', 'onDelete' => 'cascade', 'index' => true]],
        ])->and(samplingPiersEditor()->proposedTable(SAMPLING_PIERS_CONTEXT, 'Dock'))->toBeNull();
    });
});

describe('saveTable', function () {
    it('refuses what migrations.md refuses before anything is written', function () {
        $table = samplingPiersEditor()->proposedTable(SAMPLING_PIERS_CONTEXT, 'Pier')['table'];
        $table['columns'] += [
            'rate' => ['type' => 'float'],
            'kind' => ['type' => 'enum'],
            'notes' => ['type' => 'json'],
            'code' => ['type' => 'string', 'length' => 5, 'enum' => 'PierStatus'],
            'price' => ['type' => 'decimal'],
            'side' => ['type' => 'string', 'enum' => 'PierSide'],
        ];
        $table['foreignKeys'] += ['harbour' => ['table' => 'harbours', 'onDelete' => 'null']];

        expect(samplingPiersEditor()->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Pier', null, $table))->toBe([
            'columns.opened_at' => ['Make a column nullable only when null means something, and say what in its comment.'],
            'columns.rate' => ['A float cannot hold money or a rate exactly: store it as an integer in minor units, or a scaled integer.'],
            'columns.kind' => ['Store a backed enum\'s value in a string with a length that fits every case, and name the enum it casts to.'],
            'columns.notes' => ['Store json in jsonb, which Laravel writes as json on mysql and mariadb.'],
            'columns.code' => ['A length of 5 is too short for every case of PierStatus: the longest is 12.'],
            'columns.price' => ['A decimal names its precision and scale.'],
            'columns.side' => ['PierSide is a pure enum, which has no value to store.'],
            'foreignKeys.vessel_id' => [
                'No manifest designs the table vessels, and no migration builds it.',
                'Choose what a delete of the parent does: cascade for rows it owns, restrict for a reference to another aggregate, null for an optional one.',
            ],
            'foreignKeys.harbour' => [
                'A foreign key column is named {x}_id.',
                'No manifest designs the table harbours, and no migration builds it.',
                'A foreign key set to null on delete is nullable.',
                'Give the foreign key an index it leads: Postgres never indexes one by itself.',
            ],
        ])->and(samplingPiersManifest()['entities']['Pier'])->not->toHaveKey('table');
    });

    it('writes an entity\'s table and a context table, keeps a table name to one place, and removes one not built yet', function () {
        $editor = samplingPiersEditor();
        $vessel = $editor->proposedTable(SAMPLING_PIERS_CONTEXT, 'Vessel')['table'];

        expect($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', null, $vessel))->toBe([]);

        $pier = $editor->proposedTable(SAMPLING_PIERS_CONTEXT, 'Pier')['table'];
        $pier['columns']['opened_at']['comment'] = 'null means not opened yet';
        $pier['foreignKeys']['vessel_id']['onDelete'] = 'restrict';

        expect($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Pier', null, $pier))->toBe([])
            ->and(samplingPiersManifest()['entities']['Pier']['table'])->toEqual([...$pier, 'columns' => [...$pier['columns'], 'opened_at' => ['type' => 'timestamp', 'nullable' => true, 'comment' => 'null means not opened yet']]])
            ->and($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Pier', null, $pier))->toBe(['name' => ['Pier is stored in piers already: change that table.']]);

        $tags = ['name' => 'piers', 'model' => null, 'columns' => ['tag' => ['type' => 'string', 'length' => 30]], 'foreignKeys' => ['pier_id' => ['table' => 'piers', 'onDelete' => 'cascade']], 'indexes' => [['columns' => ['pier_id', 'tag'], 'unique' => true]], 'timestamps' => false, 'softDeletes' => false];

        expect($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), null, null, $tags))->toBe(['name' => ['A manifest already designs the table piers: a table belongs to one place.']])
            ->and($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), null, null, [...$tags, 'name' => 'pier_tags']))->toBe([])
            ->and($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), null, 'pier_tags', [...$tags, 'name' => 'pier_labels']))->toBe([])
            ->and(array_keys(samplingPiersManifest()['tables']))->toBe(['pier_labels'])
            ->and($editor->removeTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), null, 'pier_labels'))->toBe([])
            ->and(samplingPiersManifest()['tables'])->toBe([])
            ->and($editor->removeTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Pier', 'piers'))->toBe([])
            ->and(samplingPiersManifest()['entities']['Pier'])->not->toHaveKey('table')
            ->and($editor->removeTable(SAMPLING_PIERS_CONTEXT, 'stale', 'Vessel', 'vessels'))->toHaveKey('version');
    });

    it('keeps what the migrations build of a table, and takes what it gains', function () {
        $editor = samplingPiersEditor();
        $vessel = $editor->proposedTable(SAMPLING_PIERS_CONTEXT, 'Vessel')['table'];
        $vessel['columns'] = ['call_sign' => ['type' => 'string', 'length' => 12]];

        expect($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', null, $vessel))->toBe([]);

        $this->artisan('make:table-migration', ['table' => 'vessels', '--path' => samplingPiersMigrations()])->assertSuccessful();

        $editor = samplingPiersEditor();
        $changed = [...$vessel, 'name' => 'ships', 'columns' => ['call_sign' => ['type' => 'string', 'length' => 20]], 'timestamps' => false];

        expect($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', 'vessels', $changed))->toBe([
            'name' => ['The migrations build vessels, so it keeps its name.'],
            'columns.call_sign' => ['The migrations build vessels.call_sign, so the screen leaves it as it is. Change it with a migration of your own.'],
            'timestamps' => ['The migrations build vessels with timestamps, so it keeps them.'],
        ])->and($editor->removeTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', 'vessels'))->toBe(['name' => ['The migrations build vessels, so the design keeps it. Drop it with a migration of your own.']])
            ->and($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', 'vessels', [...$vessel, 'columns' => [...$vessel['columns'], 'flag' => ['type' => 'string', 'length' => 2]]]))->toBe([])
            ->and(array_keys(samplingPiersManifest()['entities']['Vessel']['table']['columns']))->toBe(['call_sign', 'flag']);
    });

    it('lets a foreign key point at a table Laravel\'s own migrations build', function () {
        File::put(samplingPiersMigrations().'/0001_01_01_000000_create_users_table.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('users', function (Blueprint \$table) {\n            \$table->uuid('id')->primary();\n        });\n    }\n};\n");
        $vessel = samplingPiersEditor()->proposedTable(SAMPLING_PIERS_CONTEXT, 'Vessel')['table'];
        $vessel['foreignKeys'] = ['owner_id' => ['table' => 'users', 'onDelete' => 'restrict', 'index' => true]];

        expect(samplingPiersEditor()->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', null, $vessel))->toBe([]);

        $this->artisan('make:table-migration', ['table' => 'vessels', '--path' => samplingPiersMigrations()])->assertSuccessful();
    });
});

describe('StructureGraph', function () {
    it('gives each designed table the status of the steps that build it, for the table page', function () {
        $editor = samplingPiersEditor();
        $vessel = $editor->proposedTable(SAMPLING_PIERS_CONTEXT, 'Vessel')['table'];
        $vessel['columns'] = ['call_sign' => ['type' => 'string', 'length' => 12]];
        $tags = ['name' => 'vessel_tags', 'model' => null, 'columns' => ['tag' => ['type' => 'string', 'length' => 30]], 'foreignKeys' => ['vessel_id' => ['table' => 'vessels', 'onDelete' => 'cascade', 'index' => true]], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false];

        expect($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', null, $vessel))->toBe([])
            ->and($editor->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), null, null, $tags))->toBe([]);

        $this->artisan('make:table-migration', ['table' => 'vessels', '--path' => samplingPiersMigrations()])->assertSuccessful();
        $vessel['columns']['class'] = ['type' => 'string', 'length' => 20, 'enum' => SAMPLING_PIERS_CONTEXT.'/Pier/PierStatus'];

        expect(samplingPiersEditor()->saveTable(SAMPLING_PIERS_CONTEXT, samplingPiersVersion(), 'Vessel', 'vessels', $vessel))->toBe([]);

        $reader = fn (): StructureReader => new StructureReader(base_path(), samplingPiersMigrations());
        $graph = (new StructureGraph(
            $reader(),
            new StructureFiles(base_path()),
            new StructurePlanner($reader(), new StructureFiles(base_path()), new StructureMarkers(base_path())),
            new StructureComparer($reader(), new StructureFiles(base_path()), fn (string $name): bool => str_contains($name, 'Sampling') && $name !== SAMPLING_PIERS_CONTEXT),
        ))->graph();

        $context = SAMPLING_PIERS_CONTEXT;

        expect($graph['tables'][$context])->toBe([
            'vessels' => [
                'entity' => 'Vessel', 'built' => true, 'builtColumns' => ['call_sign'],
                'status' => 'ready', 'reason' => null, 'command' => 'php artisan make:table-migration vessels --path='.samplingPiersMigrations(),
                'schema' => null,
                'model' => ['fillable' => ['call_sign', 'class'], 'casts' => ['class' => 'PierStatus'], 'relations' => []],
                'parents' => [],
                'children' => [['table' => 'vessel_tags', 'column' => 'vessel_id', 'context' => $context, 'entity' => null]],
            ],
            'vessel_tags' => [
                'entity' => null, 'built' => false, 'builtColumns' => [],
                'status' => 'ready', 'reason' => null, 'command' => 'php artisan make:table-migration vessel_tags --path='.samplingPiersMigrations(),
                'schema' => null,
                'model' => null,
                'parents' => [['table' => 'vessels', 'column' => 'vessel_id', 'context' => $context, 'entity' => 'Vessel']],
                'children' => [],
            ],
        ]);
    });
});
