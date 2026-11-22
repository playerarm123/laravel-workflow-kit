<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTables;

/**
 * The scratch context of this file. It carries `Sampling`, so no Architecture check reads it, and
 * differs from every other test file's. Its migrations go to a folder of their own, never to the
 * workbench's database/migrations, which every other test migrates.
 */
const SAMPLING_COFFERS_CONTEXT = 'SamplingCoffers';

function samplingCoffersMigrations(): string
{
    return storage_path('framework/testing/'.SAMPLING_COFFERS_CONTEXT.'-migrations');
}

/**
 * A root Coffer, a child Slat that its foreign key cascades from, and a join table no entity maps
 * to, with every kind of column the manifest writes.
 *
 * @return array<string, mixed>
 */
function samplingCoffersManifest(): array
{
    $entity = fn (array $table): array => ['aggregate' => 'Coffer', 'state' => [], 'behaviours' => [], 'assertions' => [], 'table' => $table];

    return [
        'context' => SAMPLING_COFFERS_CONTEXT,
        'aggregates' => ['Coffer' => ['children' => ['Slat'], 'repository' => false]],
        'services' => [],
        'applicationServices' => [],
        'ports' => [],
        'useCases' => [],
        'enums' => [],
        'valueObjects' => [],
        'exceptions' => [],
        'entities' => [
            'Coffer' => $entity([
                'name' => 'sampling_coffers',
                'model' => 'SamplingCoffer',
                'columns' => [
                    'name' => ['type' => 'string', 'length' => 120, 'comment' => 'what a person calls it'],
                    'code' => ['type' => 'string', 'length' => 40, 'unique' => true],
                    'status' => ['type' => 'string', 'length' => 20, 'enum' => 'CofferStatus', 'default' => 'open'],
                    'weight' => ['type' => 'unsignedInteger', 'nullable' => true, 'comment' => 'null means never weighed'],
                    'ratio' => ['type' => 'decimal', 'precision' => 5, 'scale' => 2],
                    'sealed' => ['type' => 'boolean', 'default' => false],
                    'notes' => ['type' => 'text', 'nullable' => true, 'comment' => 'null means none'],
                    'shipped_on' => ['type' => 'date', 'nullable' => true, 'index' => true, 'comment' => 'null means not shipped'],
                    'details' => ['type' => 'jsonb'],
                ],
                'foreignKeys' => [],
                'indexes' => [['columns' => ['created_at'], 'unique' => false, 'comment' => 'the list reads newest first']],
                'timestamps' => true,
                'softDeletes' => true,
            ]),
            'Slat' => $entity([
                'name' => 'sampling_coffer_slats',
                'model' => 'SamplingCofferSlat',
                'columns' => ['position' => ['type' => 'unsignedSmallInteger']],
                'foreignKeys' => ['coffer_id' => ['table' => 'sampling_coffers', 'onDelete' => 'cascade', 'index' => true, 'comment' => 'the coffer it belongs to']],
                'indexes' => [],
                'timestamps' => true,
                'softDeletes' => false,
            ]),
        ],
        'tables' => [
            'sampling_coffer_tags' => [
                'description' => 'Which tags a coffer carries',
                'model' => null,
                'columns' => ['tag' => ['type' => 'string', 'length' => 30]],
                'foreignKeys' => [
                    'coffer_id' => ['table' => 'sampling_coffers', 'onDelete' => 'restrict'],
                    'slat_id' => ['table' => 'sampling_coffer_slats', 'onDelete' => 'null', 'nullable' => true, 'index' => true, 'comment' => 'null means the whole coffer'],
                ],
                'indexes' => [['columns' => ['coffer_id', 'tag'], 'unique' => true]],
                'timestamps' => false,
                'softDeletes' => false,
            ],
        ],
    ];
}

/**
 * A table as the migrations build it, which is the design without what only the code beside
 * the migration says (the model, the enum cast, a context table's description).
 *
 * @param  array<string, mixed>  $design
 * @return array<string, mixed>
 */
function samplingCoffersBuilt(array $design): array
{
    unset($design['name'], $design['model'], $design['description']);

    foreach ($design['columns'] as $column => $definition) {
        unset($design['columns'][$column]['enum']);
    }

    return $design;
}

/**
 * @return list<string>
 */
function samplingCoffersFiles(): array
{
    $files = array_map('basename', glob(samplingCoffersMigrations().'/*.php') ?: []);
    sort($files);

    return $files;
}

function samplingCoffersSchema(): StructureTables
{
    return new StructureTables(base_path(), samplingCoffersMigrations());
}

function forgetSamplingCoffers(): void
{
    File::deleteDirectory(samplingCoffersMigrations());
    File::delete(base_path(StructureFiles::DIRECTORY.'/'.SAMPLING_COFFERS_CONTEXT.'.json'));
}

beforeEach(function () {
    forgetSamplingCoffers();
    (new StructureFiles(base_path()))->write(samplingCoffersManifest());
});

afterEach(fn () => forgetSamplingCoffers());

describe('make:table-migration', function () {
    it('writes the migration that creates each table, which the migrations then read back as designed', function () {
        foreach (['sampling_coffers', 'sampling_coffer_slats', 'sampling_coffer_tags'] as $table) {
            $this->artisan('make:table-migration', ['table' => $table, '--path' => samplingCoffersMigrations()])
                ->expectsOutputToContain('created successfully')
                ->assertSuccessful();
        }

        $manifest = samplingCoffersManifest();
        $files = samplingCoffersFiles();

        expect(array_map(fn (string $file): string => substr($file, 18), $files))
            ->toBe(['create_sampling_coffers_table.php', 'create_sampling_coffer_slats_table.php', 'create_sampling_coffer_tags_table.php'])
            ->and($files[0] < $files[1] && $files[1] < $files[2])->toBeTrue()
            ->and(samplingCoffersSchema()->schema())->toBe([
                'sampling_coffers' => samplingCoffersBuilt($manifest['entities']['Coffer']['table']),
                'sampling_coffer_slats' => samplingCoffersBuilt($manifest['entities']['Slat']['table']),
                'sampling_coffer_tags' => samplingCoffersBuilt($manifest['tables']['sampling_coffer_tags']),
            ])
            ->and(File::get(samplingCoffersMigrations().'/'.$files[2]))->toBe(<<<'PHP'
                <?php

                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Database\Schema\Blueprint;
                use Illuminate\Support\Facades\Schema;

                return new class extends Migration
                {
                    /**
                     * Run the migrations.
                     */
                    public function up(): void
                    {
                        Schema::create('sampling_coffer_tags', function (Blueprint $table) {
                            $table->uuid('id')->primary();
                            $table->foreignUuid('coffer_id')->constrained('sampling_coffers')->restrictOnDelete();
                            /** null means the whole coffer */
                            $table->foreignUuid('slat_id')->nullable()->index()->constrained('sampling_coffer_slats')->nullOnDelete();
                            $table->string('tag', 30);

                            $table->unique(['coffer_id', 'tag']);
                        });
                    }

                    /**
                     * Reverse the migrations.
                     */
                    public function down(): void
                    {
                        Schema::dropIfExists('sampling_coffer_tags');
                    }
                };

                PHP);
    });

    it('builds tables the engine accepts, and drops them again', function () {
        foreach (['sampling_coffers', 'sampling_coffer_slats', 'sampling_coffer_tags'] as $table) {
            $this->artisan('make:table-migration', ['table' => $table, '--path' => samplingCoffersMigrations()])->assertSuccessful();
        }

        $migrations = array_map(fn (string $file): object => require samplingCoffersMigrations().'/'.$file, samplingCoffersFiles());

        foreach ($migrations as $migration) {
            $migration->up();
        }

        expect(Schema::getColumnListing('sampling_coffers'))->toEqualCanonicalizing(['id', 'name', 'code', 'status', 'weight', 'ratio', 'sealed', 'notes', 'shipped_on', 'details', 'created_at', 'updated_at', 'deleted_at'])
            ->and(Schema::getColumnListing('sampling_coffer_tags'))->toEqualCanonicalizing(['id', 'coffer_id', 'slat_id', 'tag']);

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        expect(Schema::hasTable('sampling_coffers'))->toBeFalse()
            ->and(Schema::hasTable('sampling_coffer_tags'))->toBeFalse();
    });

    it('refuses a table whose foreign key points at a table not built yet, and one no manifest designs', function () {
        $this->artisan('make:table-migration', ['table' => 'sampling_coffer_slats', '--path' => samplingCoffersMigrations()])
            ->expectsOutputToContain('The table sampling_coffers, which sampling_coffer_slats points at, is not built yet')
            ->assertFailed();

        $this->artisan('make:table-migration', ['table' => 'sampling_nowhere', '--path' => samplingCoffersMigrations()])
            ->expectsOutputToContain('No valid context manifest designs the table sampling_nowhere.')
            ->assertFailed();

        expect(samplingCoffersFiles())->toBe([]);
    });

    it('adds what a built table lacks in a migration of its own, with a down() that drops just that, and then has nothing to add', function () {
        $this->artisan('make:table-migration', ['table' => 'sampling_coffers', '--path' => samplingCoffersMigrations()])->assertSuccessful();
        $this->artisan('make:table-migration', ['table' => 'sampling_coffers', '--path' => samplingCoffersMigrations()])
            ->expectsOutputToContain('The migrations already build everything sampling_coffers designs.')
            ->assertSuccessful();

        $manifest = samplingCoffersManifest();
        $manifest['entities']['Coffer']['table']['columns']['colour'] = ['type' => 'string', 'length' => 20, 'index' => true];
        (new StructureFiles(base_path()))->write($manifest);

        $this->artisan('make:table-migration', ['table' => 'sampling_coffers', '--path' => samplingCoffersMigrations()])->assertSuccessful();

        $manifest['entities']['Coffer']['table']['columns']['grade'] = ['type' => 'tinyInteger', 'default' => 0];
        $manifest['entities']['Coffer']['table']['foreignKeys']['parent_id'] = ['table' => 'sampling_coffers', 'onDelete' => 'null', 'nullable' => true, 'index' => true, 'comment' => 'null means a coffer of its own'];
        $manifest['entities']['Coffer']['table']['indexes'][] = ['columns' => ['status', 'code'], 'unique' => true];
        (new StructureFiles(base_path()))->write($manifest);

        $this->artisan('make:table-migration', ['table' => 'sampling_coffers', '--path' => samplingCoffersMigrations()])->assertSuccessful();

        $files = samplingCoffersFiles();
        $built = samplingCoffersSchema()->schema()['sampling_coffers'];

        expect(array_map(fn (string $file): string => substr($file, 18), $files))
            ->toBe(['create_sampling_coffers_table.php', 'add_colour_to_sampling_coffers_table.php', 'update_sampling_coffers_table.php'])
            ->and($built['columns']['colour'])->toBe(['type' => 'string', 'length' => 20, 'index' => true])
            ->and($built['columns']['grade'])->toBe(['type' => 'tinyInteger', 'default' => 0])
            ->and($built['foreignKeys'])->toBe(['parent_id' => ['table' => 'sampling_coffers', 'onDelete' => 'null', 'nullable' => true, 'index' => true, 'comment' => 'null means a coffer of its own']])
            ->and($built['indexes'])->toBe([['columns' => ['created_at'], 'unique' => false, 'comment' => 'the list reads newest first'], ['columns' => ['status', 'code'], 'unique' => true]])
            ->and(File::get(samplingCoffersMigrations().'/'.$files[2]))->toContain(<<<'PHP'
                    public function up(): void
                    {
                        Schema::table('sampling_coffers', function (Blueprint $table) {
                            /** null means a coffer of its own */
                            $table->foreignUuid('parent_id')->nullable()->index()->constrained('sampling_coffers')->nullOnDelete();
                            $table->tinyInteger('grade')->default(0);

                            $table->unique(['status', 'code']);
                        });
                    }

                    /**
                     * Reverse the migrations.
                     */
                    public function down(): void
                    {
                        Schema::table('sampling_coffers', function (Blueprint $table) {
                            $table->dropUnique(['status', 'code']);
                            $table->dropIndex(['parent_id']);
                            $table->dropConstrainedForeignId('parent_id');
                            $table->dropColumn(['grade']);
                        });
                    }
                PHP);

        $migrations = array_map(fn (string $file): object => require samplingCoffersMigrations().'/'.$file, $files);

        foreach ($migrations as $migration) {
            $migration->up();
        }

        expect(Schema::hasColumns('sampling_coffers', ['colour', 'grade', 'parent_id']))->toBeTrue();

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        expect(Schema::hasTable('sampling_coffers'))->toBeFalse();
    });
});
