<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureComparer;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureGraph;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureLiveSchema;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTables;

/**
 * The scratch context of this file. It carries `Sampling` (testing.md), so no Architecture check
 * reads it, and differs from every other test file's. Its migrations live in a folder of their
 * own, never in the workbench's database/migrations, which every other test migrates; a test that
 * runs them rolls them back again.
 */
const SAMPLING_VAULTS_CONTEXT = 'SamplingVaults';

function samplingVaultsMigrations(): string
{
    return storage_path('framework/testing/'.SAMPLING_VAULTS_CONTEXT.'-migrations');
}

/**
 * A migration of the scratch folder, whose `up()` runs the given statements and whose `down()`
 * drops every table this file builds.
 */
function samplingVaultsMigration(string $file, string $up): void
{
    File::ensureDirectoryExists(samplingVaultsMigrations());
    File::put(samplingVaultsMigrations().'/'.$file, <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
        {$up}
            }

            public function down(): void
            {
                Schema::dropIfExists('sampling_vault_tags');
                Schema::dropIfExists('sampling_vault_drawers');
                Schema::dropIfExists('sampling_vaults');
            }
        };

        PHP);
}

/**
 * A root Vault and a child Drawer that cascades from it, built in one migration, and a join table
 * built in a second.
 */
function writeSamplingVaultsMigrations(): void
{
    samplingVaultsMigration('2026_02_01_000000_create_sampling_vaults_table.php', <<<'PHP'
                Schema::create('sampling_vaults', function (Blueprint $table) {
                    $table->uuid('id')->primary();
                    $table->string('name', 120);
                    $table->string('code', 40)->unique();
                    /** null means never weighed */
                    $table->unsignedInteger('weight')->nullable();
                    $table->decimal('ratio', 5, 2);
                    $table->boolean('sealed');
                    $table->timestamps();
                });

                Schema::create('sampling_vault_drawers', function (Blueprint $table) {
                    $table->uuid('id')->primary();
                    $table->foreignUuid('vault_id')->index()->constrained('sampling_vaults')->cascadeOnDelete();
                    $table->unsignedSmallInteger('position');
                    $table->timestamps();
                });
        PHP);

    samplingVaultsMigration('2026_02_02_000000_create_sampling_vault_tags_table.php', <<<'PHP'
                Schema::create('sampling_vault_tags', function (Blueprint $table) {
                    $table->uuid('id')->primary();
                    $table->foreignUuid('vault_id')->constrained('sampling_vaults')->restrictOnDelete();
                    $table->string('tag', 30);

                    $table->unique(['vault_id', 'tag']);
                });
        PHP);
}

/**
 * The context as the migrations above build it.
 *
 * @return array<string, mixed>
 */
function samplingVaultsManifest(): array
{
    $tables = new StructureTables(base_path(), samplingVaultsMigrations());
    $schema = $tables->schema();
    $entity = fn (string $table, string $model): array => ['aggregate' => 'Vault', 'state' => [], 'behaviours' => [], 'assertions' => [], 'table' => ['name' => $table, 'model' => $model, ...$schema[$table]]];

    return [
        'context' => SAMPLING_VAULTS_CONTEXT,
        'aggregates' => ['Vault' => ['children' => ['Drawer'], 'repository' => false]],
        'services' => [],
        'applicationServices' => [],
        'ports' => [],
        'useCases' => [],
        'enums' => [],
        'valueObjects' => [],
        'exceptions' => [],
        'entities' => [
            'Vault' => $entity('sampling_vaults', 'SamplingVault'),
            'Drawer' => $entity('sampling_vault_drawers', 'SamplingVaultDrawer'),
        ],
        'tables' => ['sampling_vault_tags' => ['model' => null, ...$schema['sampling_vault_tags']]],
    ];
}

function samplingVaultsReader(): StructureReader
{
    return new StructureReader(base_path(), samplingVaultsMigrations());
}

function samplingVaultsFiles(): StructureFiles
{
    return new StructureFiles(base_path(), samplingVaultsMigrations());
}

function samplingVaultsLive(?Closure $connection = null): StructureLiveSchema
{
    return new StructureLiveSchema(samplingVaultsReader()->tables(), $connection);
}

function migrateSamplingVaults(): void
{
    test()->artisan('migrate', ['--path' => samplingVaultsMigrations(), '--realpath' => true])->assertSuccessful();
}

function forgetSamplingVaults(): void
{
    if (is_dir(samplingVaultsMigrations())) {
        test()->artisan('migrate:rollback', ['--path' => samplingVaultsMigrations(), '--realpath' => true]);
    }

    File::deleteDirectory(samplingVaultsMigrations());
    File::delete(base_path(StructureFiles::DIRECTORY.'/'.SAMPLING_VAULTS_CONTEXT.'.json'));
}

/**
 * Rewrites one line of a scratch migration, as a person who edits a migration after it ran does.
 */
function editSamplingVaultsMigration(string $file, string $from, string $to): void
{
    $path = samplingVaultsMigrations().'/'.$file;
    $code = File::get($path);

    expect($code)->toContain($from);

    File::put($path, str_replace($from, $to, $code));
}

beforeEach(function () {
    forgetSamplingVaults();
    writeSamplingVaultsMigrations();
    samplingVaultsFiles()->write(samplingVaultsManifest());
});

afterEach(fn () => forgetSamplingVaults());

describe('StructureTables', function () {
    it('names the migrations that build or change each table, and a renamed table keeps those of its old name', function () {
        samplingVaultsMigration('2026_02_03_000000_change_sampling_vault_tags_table.php', <<<'PHP'
                    Schema::table('sampling_vault_tags', function (Blueprint $table) {
                        $table->string('colour', 20);
                    });

                    Schema::rename('sampling_vault_tags', 'sampling_vault_labels');
            PHP);

        $tables = new StructureTables(base_path(), samplingVaultsMigrations());

        expect($tables->migrationsOf('sampling_vaults'))->toBe(['2026_02_01_000000_create_sampling_vaults_table'])
            ->and($tables->migrationsOf('sampling_vault_drawers'))->toBe(['2026_02_01_000000_create_sampling_vaults_table'])
            ->and($tables->migrationsOf('sampling_vault_labels'))->toBe(['2026_02_02_000000_create_sampling_vault_tags_table', '2026_02_03_000000_change_sampling_vault_tags_table'])
            ->and($tables->migrationsOf('sampling_nowhere'))->toBe([]);
    });
});

describe('StructureLiveSchema', function () {
    it('names the migrations of a table that have not run yet', function () {
        $live = samplingVaultsLive();

        expect($live->unreadable())->toBeNull()
            ->and($live->differences('sampling_vaults'))->toBe(['the migration 2026_02_01_000000_create_sampling_vaults_table has not run yet — run `php artisan migrate`'])
            ->and($live->differences('sampling_vault_tags'))->toBe(['the migration 2026_02_02_000000_create_sampling_vault_tags_table has not run yet — run `php artisan migrate`'])
            ->and($live->differences('sampling_nowhere'))->toBe([]);
    });

    it('finds the database holding every table as its migrations build it, once they ran', function () {
        migrateSamplingVaults();
        $live = samplingVaultsLive();

        expect($live->differences('sampling_vaults'))->toBe([])
            ->and($live->differences('sampling_vault_drawers'))->toBe([])
            ->and($live->differences('sampling_vault_tags'))->toBe([]);
    });

    it('names a column, a type, a length, a nullability, a foreign key and an index the database holds another way', function () {
        migrateSamplingVaults();
        Schema::table('sampling_vaults', fn ($table) => $table->string('stray', 10)->nullable());

        $vaults = '2026_02_01_000000_create_sampling_vaults_table.php';
        editSamplingVaultsMigration($vaults, "\$table->string('code', 40)->unique();", "\$table->string('code', 40);\n            \$table->text('notes');");
        editSamplingVaultsMigration($vaults, "\$table->unsignedInteger('weight')->nullable();", "\$table->unsignedInteger('weight');");
        editSamplingVaultsMigration($vaults, "\$table->boolean('sealed');", "\$table->boolean('sealed')->nullable();\n            \$table->index('name');");
        editSamplingVaultsMigration($vaults, '->cascadeOnDelete()', '->nullOnDelete()');

        $tags = '2026_02_02_000000_create_sampling_vault_tags_table.php';
        editSamplingVaultsMigration($tags, '->restrictOnDelete()', '->cascadeOnDelete()');
        editSamplingVaultsMigration($tags, "\$table->string('tag', 30);", "\$table->string('tag', 40);");

        $live = samplingVaultsLive();
        $driver = Schema::getConnection()->getDriverName();
        $vaultDifferences = $live->differences('sampling_vaults');

        expect($vaultDifferences)->toContain(
            'the database lacks the column notes, which the migrations declare',
            'the column sealed is not null in the database, but the migrations declare it nullable',
            'the database has the column stray, which no migration declares',
            'the database has no index on (name), which the migrations declare',
            'the database has a unique index on (code), which no migration declares',
        )
            // SQLite reads every integer as `integer`, and keeps a nullability it cannot change.
            ->and($vaultDifferences)->toContain('the column weight is nullable in the database, but the migrations declare it not null')
            ->and($live->differences('sampling_vault_drawers'))->toBe(['the foreign key on vault_id is cascadeOnDelete() in the database, but the migrations declare nullOnDelete()'])
            ->and($live->differences('sampling_vault_tags'))->toBe([
                ...($driver === 'sqlite' ? [] : ['the column tag is varchar(30) in the database, but the migrations declare varchar(40)']),
                'the foreign key on vault_id is restrictOnDelete() in the database, but the migrations declare cascadeOnDelete()',
            ]);
    });

    it('says why the schema could not be read, and compares nothing', function () {
        $live = samplingVaultsLive(fn (): Connection => throw new RuntimeException("SQLSTATE[08006] connection to server failed\nis the server running?"));

        expect($live->unreadable())->toBe('SQLSTATE[08006] connection to server failed')
            ->and($live->differences('sampling_vaults'))->toBeNull();
    });
});

describe('StructurePlanner', function () {
    function samplingVaultsPlanner(?StructureLiveSchema $live): StructurePlanner
    {
        return new StructurePlanner(samplingVaultsReader(), samplingVaultsFiles(), new StructureMarkers(storage_path('framework/testing/sampling-vaults')), $live);
    }

    it('adds a schema step that differs while the database holds a table another way, and none once it agrees', function () {
        $schemaSteps = fn (StructurePlanner $planner): array => array_values(array_map(
            fn (array $step): array => [$step['title'], $step['state'], $step['reason'], $step['nodes']],
            array_filter($planner->steps([SAMPLING_VAULTS_CONTEXT]), fn (array $step): bool => str_starts_with($step['title'], 'schema ')),
        ));

        expect($schemaSteps(samplingVaultsPlanner(samplingVaultsLive())))->toBe([
            ['schema sampling_vault_drawers', StructurePlanner::DIFFERS, 'the migration 2026_02_01_000000_create_sampling_vaults_table has not run yet — run `php artisan migrate`', ['entity:'.SAMPLING_VAULTS_CONTEXT.'/Drawer']],
            ['schema sampling_vaults', StructurePlanner::DIFFERS, 'the migration 2026_02_01_000000_create_sampling_vaults_table has not run yet — run `php artisan migrate`', ['entity:'.SAMPLING_VAULTS_CONTEXT.'/Vault']],
            ['schema sampling_vault_tags', StructurePlanner::DIFFERS, 'the migration 2026_02_02_000000_create_sampling_vault_tags_table has not run yet — run `php artisan migrate`', ['table:'.SAMPLING_VAULTS_CONTEXT.'/sampling_vault_tags']],
        ])->and($schemaSteps(samplingVaultsPlanner(null)))->toBe([]);

        migrateSamplingVaults();

        expect($schemaSteps(samplingVaultsPlanner(samplingVaultsLive())))->toBe([]);
    });

    it('says the schema went unread only when a table the migrations build was left uncompared', function () {
        $unreachable = fn (): Connection => throw new RuntimeException('could not connect');
        $planner = samplingVaultsPlanner(samplingVaultsLive($unreachable));
        $planner->steps([SAMPLING_VAULTS_CONTEXT]);

        $workbench = new StructurePlanner(new StructureReader(base_path()), new StructureFiles(base_path()), new StructureMarkers(storage_path('framework/testing/sampling-vaults')), new StructureLiveSchema((new StructureReader(base_path()))->tables(), $unreachable));
        $workbench->steps(['NoSuchContext']);

        expect($planner->unreadSchema())->toBe('could not connect')
            ->and($workbench->unreadSchema())->toBeNull();
    });
});

describe('StructureGraph', function () {
    function samplingVaultsGraph(StructureLiveSchema $live): array
    {
        return (new StructureGraph(
            samplingVaultsReader(),
            samplingVaultsFiles(),
            new StructurePlanner(samplingVaultsReader(), samplingVaultsFiles(), new StructureMarkers(storage_path('framework/testing/sampling-vaults')), $live),
            new StructureComparer(samplingVaultsReader(), samplingVaultsFiles(), fn (string $name): bool => str_contains($name, 'Sampling') && $name !== SAMPLING_VAULTS_CONTEXT),
        ))->graph();
    }

    it('shows where the database differs on the table page and on the entity\'s card', function () {
        migrateSamplingVaults();
        editSamplingVaultsMigration('2026_02_01_000000_create_sampling_vaults_table.php', '->cascadeOnDelete()', '->restrictOnDelete()');

        $graph = samplingVaultsGraph(samplingVaultsLive());
        $tables = $graph['tables'][SAMPLING_VAULTS_CONTEXT];
        $node = fn (string $id): array => array_values(array_filter($graph['contexts'][SAMPLING_VAULTS_CONTEXT]['nodes'], fn (array $node): bool => $node['id'] === $id))[0];
        $message = 'the foreign key on vault_id is cascadeOnDelete() in the database, but the migrations declare restrictOnDelete()';

        expect($tables['sampling_vault_drawers'])->toMatchArray([
            'status' => StructureGraph::DIFFERS,
            'reason' => $message,
            'command' => 'php artisan make:table-model sampling_vault_drawers',
            'schema' => ['read' => true, 'message' => null, 'differences' => [$message]],
        ])->and($tables['sampling_vaults']['schema'])->toBe(['read' => true, 'message' => null, 'differences' => []])
            ->and($tables['sampling_vaults']['status'])->toBe(StructurePlanner::READY)
            ->and($node('entity:'.SAMPLING_VAULTS_CONTEXT.'/Drawer'))->toMatchArray(['status' => StructureGraph::DIFFERS, 'reason' => $message]);
    });

    it('says the schema could not be read rather than confirming the migrations', function () {
        $graph = samplingVaultsGraph(samplingVaultsLive(fn (): Connection => throw new RuntimeException('could not connect')));

        expect($graph['tables'][SAMPLING_VAULTS_CONTEXT]['sampling_vaults']['schema'])->toBe(['read' => false, 'message' => 'could not connect', 'differences' => []]);
    });
});

describe('kit:plan', function () {
    beforeEach(fn () => app()->bind(StructureReader::class, fn (): StructureReader => samplingVaultsReader()));

    it('lists each table the database holds another way, and nothing once it agrees', function () {
        $this->artisan('kit:plan', ['--context' => [SAMPLING_VAULTS_CONTEXT]])
            ->expectsOutputToContain('Differs from the database (3):')
            ->expectsOutputToContain('schema sampling_vault_tags')
            ->assertSuccessful();

        migrateSamplingVaults();

        $this->artisan('kit:plan', ['--context' => [SAMPLING_VAULTS_CONTEXT]])
            ->doesntExpectOutputToContain('Differs from the database')
            ->doesntExpectOutputToContain('could not be read')
            ->assertSuccessful();
    });
});
