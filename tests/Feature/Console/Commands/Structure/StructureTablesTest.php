<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureComparer;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureGraph;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureSync;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTables;

/**
 * The scratch context of this file. It carries `Sampling` (testing.md), so no Architecture check
 * reads it, and differs from every other test file's, so --parallel never deletes it mid-run. Its
 * migrations live in a folder of their own, never in the workbench's database/migrations, which
 * every other test migrates.
 */
const SAMPLING_TABLES_CONTEXT = 'SamplingTables';

function samplingTablesMigrations(): string
{
    return storage_path('framework/testing/'.SAMPLING_TABLES_CONTEXT.'-migrations');
}

/**
 * An aggregate Hamper, read through its repository's model, with a child Strap, a join table
 * the code places by its foreign key, and the migrations that build them in more than one step.
 */
function writeSamplingTablesFixtures(): void
{
    $context = SAMPLING_TABLES_CONTEXT;
    $migration = fn (string $up): string => "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n{$up}    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('hamper_tags');\n    }\n};\n";

    $app = [
        "Domain/{$context}/Hamper/HamperEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Hamper;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class HamperEntity extends AggregateRoot\n{\n    public function id(): string { return 'hamper'; }\n\n    public static function entityName(): string { return 'Hamper'; }\n}\n",
        "Domain/{$context}/Hamper/Entities/StrapEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Hamper\\Entities;\n\nuse App\\Domain\\Shared\\DomainEntity;\n\nfinal class StrapEntity extends DomainEntity\n{\n    public function id(): string { return 'strap'; }\n\n    public static function entityName(): string { return 'Strap'; }\n}\n",
        "Domain/{$context}/Hamper/Enums/HamperStatus.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Hamper\\Enums;\n\nenum HamperStatus: string\n{\n    case Packed = 'packed';\n}\n",
        'Models/SamplingTablesHamper.php' => "<?php\n\nnamespace App\\Models;\n\nuse App\\Domain\\{$context}\\Hamper\\Enums\\HamperStatus;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass SamplingTablesHamper extends Model\n{\n    protected \$table = 'sampling_hampers';\n\n    protected function casts(): array\n    {\n        return ['status' => HamperStatus::class, 'weight' => 'integer'];\n    }\n}\n",
        'Models/Strap.php' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Strap extends Model {}\n",
        'Infra/Persistence/Eloquent/Repositories/EloquentHamperRepository.php' => "<?php\n\nnamespace App\\Infra\\Persistence\\Eloquent\\Repositories;\n\nuse App\\Models\\SamplingTablesHamper as Row;\nuse Illuminate\\Database\\Eloquent\\Builder;\n\nfinal class EloquentHamperRepository\n{\n    protected function newQuery(): Builder\n    {\n        return Row::query();\n    }\n}\n",
    ];

    foreach ($app as $relative => $contents) {
        File::ensureDirectoryExists(dirname(app_path($relative)));
        File::put(app_path($relative), $contents);
    }

    $migrations = [
        // Laravel's own, which keeps its idioms, and the kit's audit log: neither is read.
        '0001_01_01_000000_create_users_table.php' => $migration("        Schema::create('people', function (Blueprint \$table) {\n            \$table->id();\n        });\n"),
        '2026_01_01_000000_create_audit_entries_table.php' => $migration("        Schema::create('audit_entries', function (Blueprint \$table) {\n            \$table->uuid('id')->primary();\n        });\n"),
        '2026_01_02_000000_create_hampers_table.php' => $migration(<<<'PHP'
        Schema::create('sampling_hampers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            /** unique across every hamper */
            $table->string('code', 40)->unique();
            $table->string('status', 20)->default('packed');
            // null means never weighed;
            // the scale fills it in
            $table->unsignedInteger('weight')->nullable();
            $table->decimal('ratio', 5, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
        });

        Schema::create('straps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hamper_id')->constrained('sampling_hampers')->cascadeOnDelete();
            $table->integer('position')->unsigned();
            $table->timestamps();

            $table->index(['hamper_id', 'position']);
        });

PHP),
        '2026_01_03_000000_create_hamper_tags_table.php' => $migration(<<<'PHP'
        Schema::create('hamper_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            /** the hamper it tags */
            $table->foreignUuid('hamper_id')->index()->constrained('sampling_hampers')->restrictOnDelete();
            $table->foreignUuid('tagger_id')->nullable()->constrained('straps')->nullOnDelete();
            $table->string('tag', 30);
            $table->uuid('owner_id');
            $table->string('owner_type', 40);
            $table->unique(['hamper_id', 'tag']);
            $table->unique(['hamper_id', 'owner_type', 'owner_id']);
            $table->index(['owner_type', 'owner_id']);
            $table->index('owner_id');
        });

        Schema::create('sampling_scratch', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });

PHP),
        '2026_01_04_000000_change_hampers_table.php' => $migration(<<<'PHP'
        Schema::table('sampling_hampers', function (Blueprint $table) {
            $table->renameColumn('name', 'title');
            $table->dropColumn(['ratio']);
            $table->jsonb('details');
        });

        Schema::table('hamper_tags', function (Blueprint $table) {
            $table->dropUnique(['hamper_id', 'tag']);
            $table->index('tag');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->string('nickname');
        });

        Schema::dropIfExists('sampling_scratch');
        Schema::rename('hamper_tags', 'hamper_labels');

PHP),
    ];

    File::ensureDirectoryExists(samplingTablesMigrations());

    foreach ($migrations as $file => $contents) {
        File::put(samplingTablesMigrations().'/'.$file, $contents);
    }
}

function forgetSamplingTablesFixtures(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_TABLES_CONTEXT));
    File::delete([
        app_path('Models/SamplingTablesHamper.php'),
        app_path('Models/Strap.php'),
        app_path('Infra/Persistence/Eloquent/Repositories/EloquentHamperRepository.php'),
        base_path('.kit/structure/'.SAMPLING_TABLES_CONTEXT.'.json'),
    ]);
    File::deleteDirectory(samplingTablesMigrations());
}

function samplingTablesReader(): StructureReader
{
    return new StructureReader(base_path(), samplingTablesMigrations());
}

function samplingTablesFiles(): StructureFiles
{
    return new StructureFiles(base_path(), samplingTablesMigrations());
}

beforeEach(function () {
    forgetSamplingTablesFixtures();
    writeSamplingTablesFixtures();
});

afterEach(fn () => forgetSamplingTablesFixtures());

/**
 * The tables the fixtures' migrations leave, as StructureTables reads them.
 *
 * @return array<string, array<string, mixed>>
 */
function samplingTablesSchema(): array
{
    return [
        'sampling_hampers' => [
            'columns' => [
                'title' => ['type' => 'string', 'length' => 120],
                'code' => ['type' => 'string', 'length' => 40, 'unique' => true, 'comment' => 'unique across every hamper'],
                'status' => ['type' => 'string', 'length' => 20, 'default' => 'packed'],
                'weight' => ['type' => 'unsignedInteger', 'nullable' => true, 'comment' => 'null means never weighed; the scale fills it in'],
                'details' => ['type' => 'jsonb'],
            ],
            'foreignKeys' => [],
            'indexes' => [['columns' => ['created_at'], 'unique' => false]],
            'timestamps' => true,
            'softDeletes' => true,
        ],
        'straps' => [
            'columns' => ['position' => ['type' => 'unsignedInteger']],
            'foreignKeys' => ['hamper_id' => ['table' => 'sampling_hampers', 'onDelete' => 'cascade']],
            'indexes' => [['columns' => ['hamper_id', 'position'], 'unique' => false]],
            'timestamps' => true,
            'softDeletes' => false,
        ],
        'hamper_labels' => [
            'columns' => ['tag' => ['type' => 'string', 'length' => 30, 'index' => true]],
            'foreignKeys' => [
                'hamper_id' => ['table' => 'sampling_hampers', 'onDelete' => 'restrict', 'index' => true, 'comment' => 'the hamper it tags'],
                'tagger_id' => ['table' => 'straps', 'onDelete' => 'null', 'nullable' => true],
            ],
            'indexes' => [],
            'timestamps' => false,
            'softDeletes' => false,
        ],
    ];
}

/**
 * The fixtures' context as kit:import writes it.
 *
 * @return array<string, mixed>
 */
function samplingTablesManifest(): array
{
    $schema = samplingTablesSchema();
    $schema['sampling_hampers']['columns']['status']['enum'] = 'HamperStatus';
    $schema['sampling_hampers']['columns']['status'] = StructureFiles::orderedKeys($schema['sampling_hampers']['columns']['status'], StructureFiles::COLUMN_KEYS);

    return [
        'context' => SAMPLING_TABLES_CONTEXT,
        'aggregates' => ['Hamper' => ['children' => ['Strap'], 'repository' => false]],
        'services' => [],
        'applicationServices' => [],
        'ports' => [],
        'useCases' => [],
        'enums' => ['HamperStatus' => ['aggregate' => 'Hamper', 'backing' => 'string', 'cases' => ['Packed' => 'packed'], 'transitions' => null]],
        'valueObjects' => [],
        'exceptions' => [],
        'entities' => [
            'Hamper' => ['aggregate' => 'Hamper', 'state' => [], 'behaviours' => [], 'assertions' => [], 'table' => ['name' => 'sampling_hampers', 'model' => 'SamplingTablesHamper', ...$schema['sampling_hampers']]],
            'Strap' => ['aggregate' => 'Hamper', 'state' => [], 'behaviours' => [], 'assertions' => [], 'table' => ['name' => 'straps', 'model' => 'Strap', ...$schema['straps']]],
        ],
        'tables' => ['hamper_labels' => ['model' => null, ...$schema['hamper_labels']]],
    ];
}

/**
 * The differences about this file's context, as check and message.
 *
 * @return list<array{0: string, 1: string}>
 */
function samplingTablesDifferences(): array
{
    $comparer = new StructureComparer(samplingTablesReader(), samplingTablesFiles(), fn (string $name): bool => str_contains($name, 'Sampling') && $name !== SAMPLING_TABLES_CONTEXT);

    return array_values(array_map(
        fn (array $difference): array => [$difference['check'], $difference['message']],
        array_filter($comparer->differences(), fn (array $difference): bool => str_contains($difference['subject'], SAMPLING_TABLES_CONTEXT) || str_contains($difference['message'], 'sampling_hampers')),
    ));
}

describe('StructureTables', function () {
    it('replays the migrations in order into the tables they leave, and reads none of Laravel\'s or the kit\'s', function () {
        expect((new StructureTables(base_path(), samplingTablesMigrations()))->schema())->toBe(samplingTablesSchema());
    });

    it('sets a polymorphic pair aside as written by hand', function () {
        $tables = new StructureTables(base_path(), samplingTablesMigrations());

        expect($tables->handWritten('hamper_labels'))->toBe(['owner_type/owner_id'])
            ->and($tables->handWritten('straps'))->toBe([]);
    });

    it('reads the model that maps to each table', function () {
        $tables = new StructureTables(base_path(), samplingTablesMigrations());

        expect($tables->modelOf('sampling_hampers'))->toBe('SamplingTablesHamper')
            ->and($tables->modelOf('straps'))->toBe('Strap')
            ->and($tables->modelOf('hamper_labels'))->toBeNull()
            ->and($tables->models()['SamplingTablesHamper']['casts'])->toMatchArray(['weight' => 'integer']);
    });
});

describe('StructureReader', function () {
    it('places a table under the entity whose model maps to it, a root through its repository, and the rest by their foreign keys', function () {
        $read = samplingTablesReader()->read(SAMPLING_TABLES_CONTEXT);
        $manifest = samplingTablesManifest();

        expect($read['entities'])->toBe($manifest['entities'])
            ->and($read['tables'])->toBe($manifest['tables'])
            ->and(samplingTablesReader()->tablePlacements())->toMatchArray([
                'sampling_hampers' => ['context' => SAMPLING_TABLES_CONTEXT, 'entity' => 'Hamper'],
                'straps' => ['context' => SAMPLING_TABLES_CONTEXT, 'entity' => 'Strap'],
                'hamper_labels' => ['context' => SAMPLING_TABLES_CONTEXT, 'entity' => null],
            ]);
    });
});

describe('StructureFiles', function () {
    it('writes a table in canonical form: its keys in order, the flags that are false left out, columns and indexes as built', function () {
        $manifest = samplingTablesManifest();
        $manifest['entities'] = ['Strap' => ['aggregate' => 'Hamper', 'state' => [], 'behaviours' => [], 'assertions' => [], 'table' => [
            'softDeletes' => false,
            'timestamps' => true,
            'indexes' => [['unique' => false, 'columns' => ['position', 'hamper_id']]],
            'foreignKeys' => ['hamper_id' => ['onDelete' => 'cascade', 'nullable' => false, 'table' => 'sampling_hampers']],
            'columns' => ['position' => ['unique' => false, 'type' => 'unsignedInteger'], 'label' => ['comment' => 'what the strap says', 'length' => 20, 'type' => 'string']],
            'model' => 'Strap',
            'name' => 'straps',
        ]]];
        $manifest['tables'] = ['hamper_labels' => ['model' => null, 'columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false, 'description' => null]];

        $encoded = json_decode(samplingTablesFiles()->encode($manifest));

        expect(json_encode($encoded->entities->Strap->table, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->toBe(<<<'JSON'
{
    "name": "straps",
    "model": "Strap",
    "columns": {
        "position": {
            "type": "unsignedInteger"
        },
        "label": {
            "type": "string",
            "length": 20,
            "comment": "what the strap says"
        }
    },
    "foreignKeys": {
        "hamper_id": {
            "table": "sampling_hampers",
            "onDelete": "cascade"
        }
    },
    "indexes": [
        {
            "columns": [
                "position",
                "hamper_id"
            ],
            "unique": false
        }
    ],
    "timestamps": true,
    "softDeletes": false
}
JSON)
            ->and(samplingTablesFiles()->encode($manifest))->toContain(<<<'JSON'
    "tables": {
        "hamper_labels": {
            "model": null,
            "columns": {},
            "foreignKeys": {},
            "indexes": [],
            "timestamps": false,
            "softDeletes": false
        }
    }
}
JSON)
            ->and(samplingTablesFiles()->encode(['context' => SAMPLING_TABLES_CONTEXT, 'entities' => ['Hamper' => ['aggregate' => 'Hamper', 'state' => ['size' => 'int'], 'behaviours' => [], 'assertions' => [], 'table' => null]]]))->not->toContain('"table"');
    });

    it('says what is wrong with the shape of a table, and leaves the design rules to the editor', function () {
        $manifest = samplingTablesManifest();
        $manifest['entities']['Strap']['table'] = [
            'name' => 'Straps',
            'model' => null,
            'columns' => [
                'id' => ['type' => 'uuid'],
                'Size' => ['type' => 'integer'],
                'hamper_id' => ['type' => 'uuid'],
                'label' => ['type' => 'String', 'length' => 'long', 'nullable' => 'yes', 'shade' => 'dark'],
            ],
            'foreignKeys' => ['hamper_id' => ['onDelete' => 'explode']],
            'indexes' => [['columns' => ['missing', 'created_at']], ['columns' => ['label'], 'unique' => true]],
            'timestamps' => 'yes',
            'softDeletes' => false,
            'engine' => 'innodb',
        ];
        $manifest['tables']['hamper_labels']['foreignKeys']['tagger_id']['onDelete'] = null;
        $manifest['tables']['hamper_labels']['columns']['tag']['nullable'] = true;
        $manifest['tables']['Labels'] = ['model' => 'labels', 'columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false];

        expect(samplingTablesFiles()->problems(SAMPLING_TABLES_CONTEXT, $manifest))->toBe([
            'entities.Strap.table: has an unknown key "engine"',
            'entities.Strap.table: "name" must be a snake_case table name',
            'entities.Strap.table: "model" must name a class of app/Models',
            'entities.Strap.table: "timestamps" must be true or false',
            'entities.Strap.table: lists id, which the uuid key, timestamps or softDeletes already add — leave it out',
            'entities.Strap.table: Size is not a snake_case column name',
            'entities.Strap.table: hamper_id is both a column and a foreign key — a foreign key is its own column',
            'entities.Strap.table.columns.label: has an unknown key "shade"',
            'entities.Strap.table.columns.label: "type" must be the Blueprint method that builds it, such as string or unsignedBigInteger',
            'entities.Strap.table.columns.label: "length" must be a whole number',
            'entities.Strap.table.columns.label: "nullable" must be true or false',
            'entities.Strap.table.foreignKeys.hamper_id: "table" must name the parent table',
            'entities.Strap.table.foreignKeys.hamper_id: "onDelete" must be one of cascade, restrict, null, noAction',
            'entities.Strap.table.indexes[0]: must be {"columns": [names], "unique": true or false}, with an optional "comment"',
            'tables.Labels: the key must be a snake_case table name',
            'tables.Labels: "model" must name a class of app/Models, or be null',
        ])->and(samplingTablesFiles()->problems('Shared', ['context' => 'Shared', 'aggregates' => [], 'services' => [], 'ports' => [], 'useCases' => [], 'enums' => [], 'valueObjects' => [], 'entities' => [], 'tables' => ['hamper_labels' => $manifest['tables']['hamper_labels']]]))
            ->toBe(['"tables" must be empty, because the shared kernel has no tables']);
    });

    it('reads a manifest written before tables were designed as designing the tables the code builds', function () {
        File::ensureDirectoryExists(base_path(StructureFiles::DIRECTORY));
        File::put(base_path('.kit/structure/'.SAMPLING_TABLES_CONTEXT.'.json'), json_encode([
            'context' => SAMPLING_TABLES_CONTEXT,
            'aggregates' => ['Hamper' => ['children' => ['Strap'], 'repository' => false]],
            'services' => [],
            'applicationServices' => [],
            'ports' => [],
            'useCases' => [],
            'enums' => [],
            'valueObjects' => [],
            'exceptions' => [],
            'entities' => ['Hamper' => ['aggregate' => 'Hamper', 'state' => [], 'behaviours' => [], 'assertions' => [], 'description' => 'What goes out']],
        ]));

        $read = samplingTablesFiles()->read(SAMPLING_TABLES_CONTEXT);

        expect($read['tables'])->toBe(samplingTablesManifest()['tables'])
            ->and($read['entities']['Hamper']['table'])->toBe(samplingTablesManifest()['entities']['Hamper']['table'])
            ->and($read['entities']['Hamper']['description'])->toBe('What goes out')
            ->and($read['entities']['Strap'])->toBe(samplingTablesManifest()['entities']['Strap']);
    });
});

describe('StructureComparer', function () {
    it('finds nothing to tell apart in the manifest kit:import writes', function () {
        samplingTablesFiles()->write(samplingTablesManifest());

        expect(samplingTablesDifferences())->toBe([]);
    });

    it('tells each table apart: a column, a foreign key or a table only one side has, a column described another way, another order, a table in the wrong place', function () {
        $manifest = samplingTablesManifest();
        $hamper = $manifest['entities']['Hamper']['table'];
        $hamper['columns'] = ['code' => $hamper['columns']['code'], 'title' => ['type' => 'string', 'length' => 80], 'colour' => ['type' => 'string', 'length' => 20], 'weight' => $hamper['columns']['weight'], 'details' => $hamper['columns']['details']];
        $hamper['softDeletes'] = false;
        $manifest['entities']['Hamper']['table'] = $hamper;
        unset($manifest['entities']['Strap']);
        $manifest['tables']['hamper_labels']['foreignKeys']['tagger_id']['onDelete'] = 'cascade';
        $manifest['tables']['straps'] = ['model' => null, 'columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false];
        $manifest['tables']['hamper_notes'] = ['model' => null, 'columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false];
        samplingTablesFiles()->write($manifest);

        expect(samplingTablesDifferences())->toBe([
            ['matches', 'entities.Hamper.table.softDeletes is true in the code but false in the manifest'],
            ['matches', 'entities.Hamper.table.columns.title is {"type":"string","length":120} in the code but {"type":"string","length":80} in the manifest'],
            ['in-json', 'does not list entities.Hamper.table.columns.status, which the migrations build as {"type":"string","length":20,"enum":"HamperStatus","default":"packed"} — add it (`php artisan kit:import --context=SamplingTables --sync` adds it, keeping what is not built yet)'],
            ['in-code', 'lists entities.Hamper.table.columns.colour, which the migrations do not build yet — build it, or take it out of the manifest'],
            ['matches', 'entities.Hamper.table.columns is in the order title, code, weight, details in the code but code, title, weight, details in the manifest'],
            ['matches', 'tables.hamper_labels.foreignKeys.tagger_id is {"table":"straps","onDelete":"null","nullable":true} in the code but {"table":"straps","onDelete":"cascade","nullable":true} in the manifest'],
            ['in-code', 'lists tables.hamper_notes, which no migration builds yet — build it, or take it out of the manifest'],
            ['matches', 'lists tables.straps, but in the code it is the table of SamplingTables entities.Strap — move it under entities.Strap.table'],
        ]);
    });

    it('names a table the code maps an entity to, or places in the context, that the manifest leaves out, and one designed in two places', function () {
        $manifest = samplingTablesManifest();
        unset($manifest['entities']['Strap'], $manifest['tables']['hamper_labels']);
        $manifest['entities']['Hamper']['table']['name'] = 'sampling_bins';
        $manifest['tables']['sampling_bins'] = ['model' => null, 'columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false];
        samplingTablesFiles()->write($manifest);

        expect(samplingTablesDifferences())->toBe([
            ['matches', 'entities.Hamper.table.name is "sampling_hampers" in the code but "sampling_bins" in the manifest'],
            ['in-json', 'maps to the table straps, which .kit/structure/SamplingTables.json does not list — add it under "entities.Strap.table" (`php artisan kit:import --context=SamplingTables --sync` adds it, keeping what is not built yet)'],
            ['files', 'designs the table sampling_bins in SamplingTables entities.Hamper.table and SamplingTables tables.sampling_bins — a table belongs to one place, so keep one'],
            ['in-json', 'does not list the table hamper_labels, which the migrations build — add it under "tables" (`php artisan kit:import --context=SamplingTables --sync` adds it, keeping what is not built yet)'],
            ['in-code', 'lists tables.sampling_bins, which no migration builds yet — build it, or take it out of the manifest'],
        ]);
    });
});

it('names a table that leads to no context while the project has more than one, under the migrations', function () {
    File::put(samplingTablesMigrations().'/2026_01_05_000000_create_loose_ends_table.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class\n{\n    public function up(): void\n    {\n        Schema::create('loose_ends', function (\$table) {\n            \$table->uuid('id')->primary();\n        });\n    }\n};\n");
    $comparer = new StructureComparer(samplingTablesReader(), samplingTablesFiles(), fn (string $name): bool => false);

    expect(samplingTablesReader()->tablePlacements()['loose_ends'])->toBeNull()
        ->and(array_values(array_filter($comparer->differences(), fn (array $difference): bool => str_contains($difference['message'], 'loose_ends'))))->toBe([
            ['check' => 'in-json', 'subject' => 'database/migrations', 'message' => 'build the table loose_ends, which no manifest lists — add it under "tables" of the context it belongs to', 'node' => null],
        ]);

    $manifest = samplingTablesManifest();
    $manifest['tables']['loose_ends'] = ['description' => 'Ends nobody tied', 'model' => null, 'columns' => ['knot' => ['type' => 'string']], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => true, 'softDeletes' => false];
    samplingTablesFiles()->write($manifest);
    $synced = (new StructureSync(samplingTablesFiles(), samplingTablesReader()))->syncContext(SAMPLING_TABLES_CONTEXT);

    expect(array_values(array_filter($comparer->differences(), fn (array $difference): bool => str_contains($difference['message'], 'loose_ends'))))->toHaveCount(2)
        ->and($synced['changes'])->toBe(['tables.loose_ends: columns.knot {"type":"string"} → null, timestamps true → false'])
        ->and($synced['manifest']['tables']['loose_ends'])->toBe(['model' => null, 'columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false, 'description' => 'Ends nobody tied']);
});

describe('StructureSync', function () {
    it('takes every table from the code, keeps one only the manifest designs, and prunes it when asked', function () {
        $manifest = samplingTablesManifest();
        unset($manifest['entities']['Strap'], $manifest['tables']['hamper_labels']);
        $manifest['entities']['Hamper']['table']['columns']['title']['length'] = 80;
        $manifest['tables']['hamper_notes'] = ['description' => 'Notes on a hamper', 'model' => null, 'columns' => [], 'foreignKeys' => [], 'indexes' => [], 'timestamps' => false, 'softDeletes' => false];
        samplingTablesFiles()->write($manifest);
        $sync = new StructureSync(samplingTablesFiles(), samplingTablesReader());

        $synced = $sync->syncContext(SAMPLING_TABLES_CONTEXT);

        expect($synced['changes'])->toBe([
            'entities.Hamper.table: columns.title {"type":"string","length":80} → {"type":"string","length":120}',
            'entities.Strap.table: added',
            'tables.hamper_labels: added',
        ])->and($synced['kept'])->toBe(['tables.hamper_notes'])
            ->and($synced['manifest']['entities'])->toBe(samplingTablesManifest()['entities'])
            ->and($synced['manifest']['tables'])->toBe([...samplingTablesManifest()['tables'], 'hamper_notes' => $manifest['tables']['hamper_notes']])
            ->and($sync->syncContext(SAMPLING_TABLES_CONTEXT, prune: true)['manifest']['tables'])->toBe(samplingTablesManifest()['tables']);
    });
});

describe('StructureGraph', function () {
    it('names the table on the entity\'s card, which shows where the table differs', function () {
        $manifest = samplingTablesManifest();
        $manifest['entities']['Hamper']['table']['softDeletes'] = false;
        samplingTablesFiles()->write($manifest);

        $view = (new StructureGraph(
            samplingTablesReader(),
            samplingTablesFiles(),
            new StructurePlanner(samplingTablesReader(), samplingTablesFiles(), new StructureMarkers(storage_path('framework/testing/sampling-tables'))),
            new StructureComparer(samplingTablesReader(), samplingTablesFiles(), fn (string $name): bool => str_contains($name, 'Sampling') && $name !== SAMPLING_TABLES_CONTEXT),
        ))->graph()['contexts'][SAMPLING_TABLES_CONTEXT];
        $node = fn (string $id): array => array_values(array_filter($view['nodes'], fn (array $node): bool => $node['id'] === $id))[0];

        expect($node('entity:'.SAMPLING_TABLES_CONTEXT.'/Hamper'))->toMatchArray([
            'items' => ['table sampling_hampers'],
            'status' => StructureGraph::DIFFERS,
            'reason' => 'entities.Hamper.table.softDeletes is true in the code but false in the manifest',
        ])->and($node('entity:'.SAMPLING_TABLES_CONTEXT.'/Strap'))->toMatchArray(['items' => ['table straps'], 'status' => StructurePlanner::DONE]);
    });
});

describe('StructurePlanner', function () {
    /**
     * The steps of this file's context and the resource that names its Strap model, as title,
     * state, reason, the command a person would type and the nodes each one builds.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: list<string>}>
     */
    function samplingTablesSteps(): array
    {
        $planner = new StructurePlanner(samplingTablesReader(), samplingTablesFiles(), new StructureMarkers(storage_path('framework/testing/sampling-tables')));
        $steps = [];

        foreach ($planner->steps([SAMPLING_TABLES_CONTEXT], ['SamplingTablesStrap']) as $step) {
            if (preg_match('/^(migration|model|repository) /', $step['title']) === 1) {
                $steps[$step['title']] = [$step['state'], $step['reason'], StructurePlanner::describe($step), $step['nodes']];
            }
        }

        return $steps;
    }

    beforeEach(fn () => samplingTablesFiles()->writeResource(['resource' => 'SamplingTablesStrap', 'model' => 'Strap', 'controller' => [], 'actions' => [], 'policy' => null, 'pages' => []]));

    afterEach(fn () => File::delete(base_path(StructureFiles::HTTP_DIRECTORY.'/SamplingTablesStrap.json')));

    it('finds every table and model the manifest designs built, and builds the model of a resource the context designs through the table', function () {
        $manifest = samplingTablesManifest();
        $manifest['aggregates']['Hamper']['repository'] = true;
        samplingTablesFiles()->write($manifest);
        $folder = samplingTablesMigrations();
        $entity = fn (string $name): string => 'entity:'.SAMPLING_TABLES_CONTEXT."/{$name}";

        expect(samplingTablesSteps())->toBe([
            'repository '.SAMPLING_TABLES_CONTEXT.'/Hamper' => [StructurePlanner::DONE, null, 'php artisan make:eloquent-repository Hamper --domain='.SAMPLING_TABLES_CONTEXT.'/Hamper --model=SamplingTablesHamper', ['aggregate:'.SAMPLING_TABLES_CONTEXT.'/Hamper']],
            'migration sampling_hampers' => [StructurePlanner::DONE, null, "php artisan make:table-migration sampling_hampers --path={$folder}", [$entity('Hamper')]],
            'model SamplingTablesHamper' => [StructurePlanner::DONE, null, 'php artisan make:table-model sampling_hampers', [$entity('Hamper')]],
            'migration straps' => [StructurePlanner::DONE, null, "php artisan make:table-migration straps --path={$folder}", [$entity('Strap')]],
            'model Strap' => [StructurePlanner::DONE, null, 'php artisan make:table-model straps', [$entity('Strap'), 'model:SamplingTablesStrap']],
            'migration hamper_labels' => [StructurePlanner::DONE, null, "php artisan make:table-migration hamper_labels --path={$folder}", ['table:'.SAMPLING_TABLES_CONTEXT.'/hamper_labels']],
        ]);
    });

    it('plans the migration of what a table adds once the tables it points at are built, and the model once its enums are', function () {
        $manifest = samplingTablesManifest();
        $manifest['entities']['Hamper']['table']['columns']['colour'] = ['type' => 'string', 'length' => 20];
        $manifest['tables']['sampling_lids'] = [
            'model' => 'SamplingTablesLid',
            'columns' => ['shade' => ['type' => 'string', 'length' => 20, 'enum' => SAMPLING_TABLES_CONTEXT.'/Hamper/LidShade']],
            'foreignKeys' => ['box_id' => ['table' => 'sampling_boxes', 'onDelete' => 'cascade', 'index' => true], 'hamper_id' => ['table' => 'sampling_hampers', 'onDelete' => 'cascade', 'index' => true]],
            'indexes' => [],
            'timestamps' => false,
            'softDeletes' => false,
        ];
        samplingTablesFiles()->write($manifest);
        $steps = samplingTablesSteps();

        expect($steps['migration sampling_hampers'][0])->toBe(StructurePlanner::READY)
            ->and($steps['migration sampling_lids'])->toBe([StructurePlanner::WAITING, 'the table sampling_boxes comes first', 'php artisan make:table-migration sampling_lids --path='.samplingTablesMigrations(), ['table:'.SAMPLING_TABLES_CONTEXT.'/sampling_lids']])
            ->and($steps['model SamplingTablesLid'])->toBe([StructurePlanner::WAITING, SAMPLING_TABLES_CONTEXT.'/Hamper/LidShade is not built yet', 'php artisan make:table-model sampling_lids', ['table:'.SAMPLING_TABLES_CONTEXT.'/sampling_lids']]);
    });
});
