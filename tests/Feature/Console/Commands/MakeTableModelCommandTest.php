<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTables;

/**
 * The scratch context and models of this file. They carry `Sampling`, so no Architecture check
 * reads them, and differ from every other test file's. A class loads once per process, so only
 * one case loads the models it writes.
 */
const SAMPLING_CADDIES_CONTEXT = 'SamplingCaddies';

function samplingCaddiesMigrations(): string
{
    return storage_path('framework/testing/'.SAMPLING_CADDIES_CONTEXT.'-migrations');
}

/**
 * A root Caddy with a status, a child Cup on a table its name does not lead to, and a table no
 * entity maps to.
 *
 * @return array<string, mixed>
 */
function samplingCaddiesManifest(string $status = 'CaddyStatus'): array
{
    $entity = fn (array $table): array => ['aggregate' => 'Caddy', 'state' => [], 'behaviours' => [], 'assertions' => [], 'table' => $table];

    return [
        'context' => SAMPLING_CADDIES_CONTEXT,
        'aggregates' => ['Caddy' => ['children' => ['Cup'], 'repository' => false]],
        'services' => [],
        'applicationServices' => [],
        'ports' => [],
        'useCases' => [],
        'enums' => [],
        'valueObjects' => [],
        'exceptions' => [],
        'entities' => [
            'Caddy' => $entity([
                'name' => 'sampling_caddies',
                'model' => 'SamplingCaddy',
                'columns' => [
                    'name' => ['type' => 'string', 'length' => 120],
                    'code' => ['type' => 'string', 'length' => 8, 'unique' => true],
                    'status' => ['type' => 'string', 'length' => 20, 'enum' => $status],
                    'size' => ['type' => 'unsignedInteger'],
                    'price' => ['type' => 'decimal', 'precision' => 8, 'scale' => 2],
                    'active' => ['type' => 'boolean'],
                    'packed_at' => ['type' => 'dateTime', 'nullable' => true, 'comment' => 'null means not packed yet'],
                    'details' => ['type' => 'jsonb'],
                ],
                'foreignKeys' => [],
                'indexes' => [],
                'timestamps' => true,
                'softDeletes' => true,
            ]),
            'Cup' => $entity([
                'name' => 'sampling_caddy_holders',
                'model' => 'SamplingCaddyCup',
                'columns' => ['position' => ['type' => 'unsignedSmallInteger']],
                'foreignKeys' => ['caddy_id' => ['table' => 'sampling_caddies', 'onDelete' => 'cascade', 'index' => true]],
                'indexes' => [],
                'timestamps' => false,
                'softDeletes' => false,
            ]),
        ],
        'tables' => [
            'sampling_caddy_marks' => [
                'model' => null,
                'columns' => [],
                'foreignKeys' => ['caddy_id' => ['table' => 'sampling_caddies', 'onDelete' => 'restrict', 'index' => true]],
                'indexes' => [],
                'timestamps' => false,
                'softDeletes' => false,
            ],
        ],
    ];
}

function writeSamplingCaddies(string $status = 'CaddyStatus'): void
{
    $enum = app_path('Domain/'.SAMPLING_CADDIES_CONTEXT.'/Caddy/Enums/CaddyStatus.php');
    File::ensureDirectoryExists(dirname($enum));
    File::put($enum, "<?php\n\nnamespace App\\Domain\\".SAMPLING_CADDIES_CONTEXT."\\Caddy\\Enums;\n\nenum CaddyStatus: string\n{\n    case Open = 'open';\n    case Packed = 'packed';\n}\n");
    (new StructureFiles(base_path()))->write(samplingCaddiesManifest($status));
}

function forgetSamplingCaddies(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_CADDIES_CONTEXT));
    File::deleteDirectory(samplingCaddiesMigrations());
    File::delete([
        base_path(StructureFiles::DIRECTORY.'/'.SAMPLING_CADDIES_CONTEXT.'.json'),
        app_path('Models/SamplingCaddy.php'),
        app_path('Models/SamplingCaddyCup.php'),
        database_path('factories/SamplingCaddyFactory.php'),
        database_path('factories/SamplingCaddyCupFactory.php'),
    ]);
}

beforeEach(fn () => forgetSamplingCaddies());

afterEach(fn () => forgetSamplingCaddies());

describe('make:table-model', function () {
    it('writes the model the way models.md shapes it: fillable, a @property per column as its cast returns, casts and the relations', function () {
        writeSamplingCaddies();

        $this->artisan('make:table-model', ['table' => 'sampling_caddies'])
            ->expectsOutputToContain('Model [app/Models/SamplingCaddy.php] created successfully.')
            ->expectsOutputToContain('Factory [database/factories/SamplingCaddyFactory.php] created successfully.')
            ->assertSuccessful();
        $this->artisan('make:table-model', ['table' => 'sampling_caddy_holders'])->assertSuccessful();

        expect(File::get(app_path('Models/SamplingCaddy.php')))->toBe(<<<'PHP'
            <?php

            namespace App\Models;

            use App\Domain\SamplingCaddies\Caddy\Enums\CaddyStatus;
            use App\Models\Concerns\KeyedByUuid;
            use Database\Factories\SamplingCaddyFactory;
            use Illuminate\Database\Eloquent\Attributes\Fillable;
            use Illuminate\Database\Eloquent\Factories\HasFactory;
            use Illuminate\Database\Eloquent\Model;
            use Illuminate\Database\Eloquent\Relations\HasMany;
            use Illuminate\Database\Eloquent\SoftDeletes;
            use Illuminate\Support\Carbon;

            /**
             * @property string $id
             * @property string $name
             * @property string $code
             * @property CaddyStatus $status
             * @property int $size
             * @property string $price
             * @property bool $active
             * @property Carbon|null $packed_at
             * @property array $details
             * @property Carbon|null $created_at
             * @property Carbon|null $updated_at
             * @property Carbon|null $deleted_at
             */
            #[Fillable(['name', 'code', 'status', 'size', 'price', 'active', 'packed_at', 'details'])]
            class SamplingCaddy extends Model
            {
                /** @use HasFactory<SamplingCaddyFactory> */
                use HasFactory, KeyedByUuid, SoftDeletes;

                /**
                 * @return array<string, string>
                 */
                protected function casts(): array
                {
                    return [
                        'status' => CaddyStatus::class,
                        'size' => 'integer',
                        'price' => 'decimal:2',
                        'active' => 'boolean',
                        'packed_at' => 'datetime',
                        'details' => 'array',
                    ];
                }

                /**
                 * @return HasMany<SamplingCaddyCup, $this>
                 */
                public function samplingCaddyCups(): HasMany
                {
                    return $this->hasMany(SamplingCaddyCup::class, 'caddy_id');
                }
            }

            PHP)
            ->and(File::get(database_path('factories/SamplingCaddyFactory.php')))->toBe(<<<'PHP'
                <?php

                namespace Database\Factories;

                use App\Domain\SamplingCaddies\Caddy\Enums\CaddyStatus;
                use App\Models\SamplingCaddy;
                use Illuminate\Database\Eloquent\Factories\Factory;

                /**
                 * @extends Factory<SamplingCaddy>
                 */
                class SamplingCaddyFactory extends Factory
                {
                    /**
                     * Define the model's default state.
                     *
                     * @return array<string, mixed>
                     */
                    public function definition(): array
                    {
                        return [
                            'id' => fake()->uuid(),
                            'name' => fake()->words(3, true),
                            'code' => fake()->unique()->lexify('????????'),
                            'status' => CaddyStatus::Open,
                            'size' => fake()->numberBetween(0, 100),
                            'price' => fake()->randomFloat(2, 0, 100),
                            'active' => fake()->boolean(),
                            'packed_at' => null,
                            'details' => [],
                        ];
                    }
                }

                PHP)
            ->and(File::get(app_path('Models/SamplingCaddyCup.php')))
            ->toContain("#[Fillable(['caddy_id', 'position'])]\n#[Table('sampling_caddy_holders')]\n#[WithoutTimestamps]\nclass SamplingCaddyCup extends Model")
            ->toContain(" * @property string \$id\n * @property string \$caddy_id\n * @property int \$position\n */")
            ->toContain("     * @return BelongsTo<SamplingCaddy, \$this>\n     */\n    public function caddy(): BelongsTo\n    {\n        return \$this->belongsTo(SamplingCaddy::class, 'caddy_id');\n    }")
            ->and(File::get(database_path('factories/SamplingCaddyCupFactory.php')))
            ->toContain("            'caddy_id' => SamplingCaddy::factory(),\n            'position' => fake()->numberBetween(0, 100),");

        $pint = Process::path(dirname(__DIR__, 4))->run([
            'vendor/bin/pint', '--test',
            app_path('Models/SamplingCaddy.php'), app_path('Models/SamplingCaddyCup.php'),
            database_path('factories/SamplingCaddyFactory.php'), database_path('factories/SamplingCaddyCupFactory.php'),
        ]);

        expect($pint->exitCode())->toBe(0, $pint->output());
    });

    it('writes a model and factory that fill the tables its migrations build, and that the reader reads the casts of', function () {
        writeSamplingCaddies();

        foreach (['sampling_caddies', 'sampling_caddy_holders'] as $table) {
            $this->artisan('make:table-migration', ['table' => $table, '--path' => samplingCaddiesMigrations()])->assertSuccessful();
            $this->artisan('make:table-model', ['table' => $table])->assertSuccessful();
        }

        foreach (glob(samplingCaddiesMigrations().'/*.php') ?: [] as $file) {
            (require $file)->up();
        }

        $cup = 'App\Models\SamplingCaddyCup';
        $caddy = 'App\Models\SamplingCaddy';
        $status = 'App\Domain\\'.SAMPLING_CADDIES_CONTEXT.'\Caddy\Enums\CaddyStatus';
        $made = $cup::factory()->create();

        expect($made->caddy)->toBeInstanceOf($caddy)
            ->and($made->caddy->status)->toBe($status::Open)
            ->and($made->caddy->samplingCaddyCups->pluck('id')->all())->toBe([$made->id])
            ->and((new StructureTables(base_path(), samplingCaddiesMigrations()))->models())
            ->toMatchArray([
                'SamplingCaddy' => ['class' => $caddy, 'table' => 'sampling_caddies', 'casts' => ['status' => $status, 'size' => 'integer', 'price' => 'decimal:2', 'active' => 'boolean', 'packed_at' => 'datetime', 'details' => 'array', 'deleted_at' => 'datetime']],
                'SamplingCaddyCup' => ['class' => $cup, 'table' => 'sampling_caddy_holders', 'casts' => ['position' => 'integer']],
            ]);
    });

    it('refuses an enum that is not built yet, a model already there, and a table that names none, and keeps a factory already there', function () {
        writeSamplingCaddies('CaddyGrade');

        $this->artisan('make:table-model', ['table' => 'sampling_caddies'])
            ->expectsOutputToContain('CaddyGrade is not built yet: build the enums first.')
            ->assertFailed();

        $this->artisan('make:table-model', ['table' => 'sampling_caddy_marks'])
            ->expectsOutputToContain('The table sampling_caddy_marks names no model.')
            ->assertFailed();

        File::ensureDirectoryExists(database_path('factories'));
        File::put(database_path('factories/SamplingCaddyCupFactory.php'), '<?php // by hand');

        $this->artisan('make:table-model', ['table' => 'sampling_caddy_holders'])
            ->expectsOutputToContain('Factory [database/factories/SamplingCaddyCupFactory.php] already exists, so it is kept.')
            ->assertSuccessful();

        $this->artisan('make:table-model', ['table' => 'sampling_caddy_holders'])
            ->expectsOutputToContain('Model [SamplingCaddyCup] already exists.')
            ->assertFailed();

        expect(File::get(database_path('factories/SamplingCaddyCupFactory.php')))->toBe('<?php // by hand')
            ->and(is_file(app_path('Models/SamplingCaddy.php')))->toBeFalse();
    });
});
