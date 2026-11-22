<?php

use App\Domain\Shared\ValueObjects\CalendarDate;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureRepositoryMapping;

/**
 * The scratch context, aggregate, models and tables of this file. They carry `Sampling` (or
 * `Satchel`), so no Architecture check reads them, and differ from every other test file's. A
 * class loads once per process, so only one case loads the classes it writes.
 */
const SAMPLING_SATCHELS_CONTEXT = 'SamplingSatchels';

function samplingSatchelsMigrations(): string
{
    return storage_path('framework/testing/'.SAMPLING_SATCHELS_CONTEXT.'-migrations');
}

function samplingSatchelsRepository(): string
{
    return app_path('Infra/Persistence/Eloquent/Repositories/EloquentSatchelRepository.php');
}

/**
 * A root Satchel whose state covers each plain mapping, with Pocket children on a table of their
 * own.
 *
 * @param  array<string, string>  $satchelState
 * @return array<string, mixed>
 */
function samplingSatchelsManifest(array $satchelState = [], bool $children = true): array
{
    $entity = fn (array $state, array $table): array => ['aggregate' => 'Satchel', 'state' => $state, 'behaviours' => [], 'assertions' => [], 'table' => $table];
    $satchelState = $satchelState === [] ? [
        'name' => 'string',
        'status' => 'SatchelStatus',
        'price' => 'Shared/Money',
        'packedAt' => '?DateTimeImmutable',
        'dueOn' => '?Shared/CalendarDate',
        ...($children ? ['pockets' => 'array'] : []),
    ] : $satchelState;

    return [
        'context' => SAMPLING_SATCHELS_CONTEXT,
        'aggregates' => ['Satchel' => ['children' => $children ? ['Pocket'] : [], 'repository' => true]],
        'services' => [],
        'applicationServices' => [],
        'ports' => [],
        'useCases' => [],
        'enums' => [],
        'valueObjects' => [],
        'exceptions' => [],
        'entities' => [
            'Satchel' => $entity($satchelState, [
                'name' => 'sampling_satchels',
                'model' => 'SamplingSatchel',
                'columns' => [
                    'name' => ['type' => 'string', 'length' => 120],
                    'status' => ['type' => 'string', 'length' => 20, 'enum' => 'SatchelStatus'],
                    'price' => ['type' => 'unsignedBigInteger'],
                    'packed_at' => ['type' => 'dateTime', 'nullable' => true, 'comment' => 'null means not packed yet'],
                    'due_on' => ['type' => 'date', 'nullable' => true, 'comment' => 'null means no due day'],
                ],
                'foreignKeys' => [],
                'indexes' => [],
                'timestamps' => true,
                'softDeletes' => false,
            ]),
            ...($children ? ['Pocket' => $entity(['label' => 'string', 'position' => 'int'], [
                'name' => 'sampling_satchel_pockets',
                'model' => 'SamplingSatchelPocket',
                'columns' => [
                    'label' => ['type' => 'string', 'length' => 40],
                    'position' => ['type' => 'unsignedSmallInteger'],
                ],
                'foreignKeys' => ['satchel_id' => ['table' => 'sampling_satchels', 'onDelete' => 'cascade', 'index' => true]],
                'indexes' => [],
                'timestamps' => false,
                'softDeletes' => false,
            ])] : []),
        ],
        'tables' => [],
    ];
}

function writeSamplingSatchelsEnum(): void
{
    $enum = app_path('Domain/'.SAMPLING_SATCHELS_CONTEXT.'/Satchel/Enums/SatchelStatus.php');
    File::ensureDirectoryExists(dirname($enum));
    File::put($enum, "<?php\n\nnamespace App\\Domain\\".SAMPLING_SATCHELS_CONTEXT."\\Satchel\\Enums;\n\nenum SatchelStatus: string\n{\n    case Open = 'open';\n    case Packed = 'packed';\n}\n");
}

/**
 * Builds everything the mapping waits for, the way kit:apply's earlier steps build it.
 *
 * @param  array<string, mixed>  $manifest
 */
function buildSamplingSatchels(array $manifest, bool $migrate = false): void
{
    test()->artisan('make:entity', ['name' => 'Satchel', '--domain' => SAMPLING_SATCHELS_CONTEXT.'/Satchel'])->assertSuccessful();

    foreach (array_keys($manifest['entities']) as $entity) {
        if ($entity !== 'Satchel') {
            test()->artisan('make:entity', ['name' => $entity, '--domain' => SAMPLING_SATCHELS_CONTEXT.'/Satchel', '--child' => true])->assertSuccessful();
        }

        foreach ($manifest['entities'][$entity]['state'] as $property => $type) {
            test()->artisan('make:entity-state', ['entity' => $entity, '--domain' => SAMPLING_SATCHELS_CONTEXT.'/Satchel', '--field' => ["{$property}:{$type}"]])->assertSuccessful();
        }
    }

    foreach ($manifest['entities'] as $entry) {
        test()->artisan('make:table-migration', ['table' => $entry['table']['name'], '--path' => samplingSatchelsMigrations()])->assertSuccessful();
        test()->artisan('make:table-model', ['table' => $entry['table']['name']])->assertSuccessful();
    }

    // Last, because reading the workbench's manifests, which predate tables, loads every repository.
    test()->artisan('make:eloquent-repository', ['name' => 'Satchel', '--domain' => SAMPLING_SATCHELS_CONTEXT.'/Satchel', '--model' => 'SamplingSatchel'])->assertSuccessful();

    foreach ($migrate ? glob(samplingSatchelsMigrations().'/*.php') ?: [] : [] as $file) {
        (require $file)->up();
    }
}

function forgetSamplingSatchels(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_SATCHELS_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_SATCHELS_CONTEXT));
    File::deleteDirectory(samplingSatchelsMigrations());
    File::delete([
        base_path(StructureFiles::DIRECTORY.'/'.SAMPLING_SATCHELS_CONTEXT.'.json'),
        samplingSatchelsRepository(),
        app_path('Infra/Logging/EntityPayloads/SatchelLogPayload.php'),
        base_path('tests/Feature/Infra/Persistence/Eloquent/Repositories/EloquentSatchelRepositoryTest.php'),
        app_path('Models/SamplingSatchel.php'),
        app_path('Models/SamplingSatchelPocket.php'),
        database_path('factories/SamplingSatchelFactory.php'),
        database_path('factories/SamplingSatchelPocketFactory.php'),
    ]);
}

/**
 * The mapping step of the scratch aggregate, as kit:plan plans it.
 *
 * @return array<string, mixed>|null
 */
function samplingSatchelsMappingStep(): ?array
{
    $steps = (new StructurePlanner(new StructureReader(base_path(), samplingSatchelsMigrations()), new StructureFiles(base_path()), new StructureMarkers(base_path())))->steps([SAMPLING_SATCHELS_CONTEXT]);

    foreach ($steps as $step) {
        if ($step['key'] === 'mapping '.SAMPLING_SATCHELS_CONTEXT.'/Satchel') {
            return $step;
        }
    }

    return null;
}

beforeEach(fn () => forgetSamplingSatchels());

afterEach(fn () => forgetSamplingSatchels());

describe('make:repository-mapping', function () {
    it('writes toModel(), toEntity(), and write() with syncChildren() for each child table', function () {
        $manifest = samplingSatchelsManifest();
        writeSamplingSatchelsEnum();
        (new StructureFiles(base_path()))->write($manifest);
        buildSamplingSatchels($manifest, migrate: true);

        // The planner would load the repository as make:eloquent-repository wrote it, and this case
        // runs the one the mapping writes, so readiness is asked of the mapping alone.
        expect(StructureRepositoryMapping::of(base_path(), SAMPLING_SATCHELS_CONTEXT, 'Satchel', (new StructureFiles(base_path()))->read(SAMPLING_SATCHELS_CONTEXT), new StructureReader(base_path(), samplingSatchelsMigrations()))['problems'])->toBe([]);

        $this->artisan('make:repository-mapping', ['aggregate' => 'Satchel', '--domain' => SAMPLING_SATCHELS_CONTEXT.'/Satchel'])
            ->expectsOutputToContain('The mapping of [app/Infra/Persistence/Eloquent/Repositories/EloquentSatchelRepository.php] written successfully.')
            ->assertSuccessful();

        $code = File::get(samplingSatchelsRepository());

        expect($code)
            ->toContain(<<<'PHP'
                #[Override]
                protected function newQuery(): Builder
                {
                    return SamplingSatchel::query()->with('samplingSatchelPockets');
                }
            PHP)
            ->toContain(<<<'PHP'
                #[Override]
                protected function write(array $entities, WriteMode $mode): void
                {
                    $this->writeRoots($entities, $mode);

                    $ids = array_map(fn (SatchelEntity $entity): string => $entity->id(), $entities);
                    $pockets = [];

                    foreach ($entities as $entity) {
                        foreach ($entity->pockets() as $pocket) {
                            $pockets[] = $this->toPocketModel($pocket, $entity->id());
                        }
                    }

                    $this->syncChildren(SamplingSatchelPocket::class, 'satchel_id', $ids, $pockets, $mode);
                }

                #[Override]
                protected function toModel(DomainEntity $entity): Model
                {
                    $model = new SamplingSatchel;
                    $model->id = $entity->id();
                    $model->name = $entity->name();
                    $model->status = $entity->status();
                    $model->price = $entity->price()->amount;
                    $model->packed_at = Carbon::make($entity->packedAt());
                    $model->due_on = Carbon::make($entity->dueOn()?->toString());

                    return $model;
                }

                #[Override]
                protected function toEntity(Model $model): DomainEntity
                {
                    return SatchelEntity::reconstitute(
                        id: $model->id,
                        name: $model->name,
                        status: $model->status,
                        price: Money::from($model->price),
                        packedAt: $model->packed_at?->toImmutable(),
                        dueOn: $model->due_on === null ? null : CalendarDate::fromString($model->due_on->toDateString()),
                        pockets: $model->samplingSatchelPockets->map(fn (SamplingSatchelPocket $pocket): PocketEntity => $this->toPocketEntity($pocket))->values()->all(),
                    );
                }

                private function toPocketModel(PocketEntity $pocket, string $satchelId): SamplingSatchelPocket
                {
                    $model = new SamplingSatchelPocket;
                    $model->id = $pocket->id();
                    $model->satchel_id = $satchelId;
                    $model->label = $pocket->label();
                    $model->position = $pocket->position();

                    return $model;
                }

                private function toPocketEntity(SamplingSatchelPocket $model): PocketEntity
                {
                    return PocketEntity::reconstitute(
                        id: $model->id,
                        label: $model->label,
                        position: $model->position,
                    );
                }
            }

            PHP)
            ->toContain("use App\\Domain\\Shared\\ValueObjects\\CalendarDate;\nuse App\\Domain\\Shared\\ValueObjects\\Money;\n")
            ->toContain("use App\\Models\\SamplingSatchel;\nuse App\\Models\\SamplingSatchelPocket;\n")
            ->toContain("use Illuminate\\Support\\Carbon;\nuse Override;\n")
            ->toContain('use App\\Domain\\'.SAMPLING_SATCHELS_CONTEXT."\\Satchel\\Entities\\PocketEntity;\n");

        $pint = Process::path(dirname(__DIR__, 4))->run(['vendor/bin/pint', '--test', samplingSatchelsRepository()]);

        expect($pint->exitCode())->toBe(0, $pint->output());

        // Composer remembers the planner's look-up from before the repository existed.
        require_once samplingSatchelsRepository();
        $repository = app('App\Infra\Persistence\Eloquent\Repositories\EloquentSatchelRepository');
        $satchel = 'App\Domain\\'.SAMPLING_SATCHELS_CONTEXT.'\Satchel\SatchelEntity';
        $pocket = 'App\Domain\\'.SAMPLING_SATCHELS_CONTEXT.'\Satchel\Entities\PocketEntity';
        $status = 'App\Domain\\'.SAMPLING_SATCHELS_CONTEXT.'\Satchel\Enums\SatchelStatus';
        $id = (string) str()->uuid();
        $pockets = [$pocket::reconstitute(id: (string) str()->uuid(), label: 'front', position: 1), $pocket::reconstitute(id: (string) str()->uuid(), label: 'back', position: 2)];
        $packedAt = new DateTimeImmutable('2026-11-20 10:30:00');

        $repository->save($satchel::reconstitute(id: $id, name: 'Day bag', status: $status::Packed, price: Money::from(150050), packedAt: $packedAt, dueOn: CalendarDate::fromString('2026-12-01'), pockets: $pockets));
        $read = $repository->getById($id);

        expect($read->name())->toBe('Day bag')
            ->and($read->status())->toBe($status::Packed)
            ->and($read->price()->amount)->toBe(150050)
            ->and($read->packedAt()?->format('Y-m-d H:i:s'))->toBe('2026-11-20 10:30:00')
            ->and($read->dueOn()?->toString())->toBe('2026-12-01')
            ->and(collect($read->pockets())->map(fn ($entity): string => $entity->label())->sort()->values()->all())->toBe(['back', 'front']);

        $repository->update($satchel::reconstitute(id: $id, name: 'Day bag', status: $status::Open, price: Money::from(0), packedAt: null, dueOn: null, pockets: [$pockets[1]]));
        $read = $repository->getById($id);

        expect($read->status())->toBe($status::Open)
            ->and($read->packedAt())->toBeNull()
            ->and($read->dueOn())->toBeNull()
            ->and(array_map(fn ($entity): string => $entity->label(), $read->pockets()))->toBe(['back'])
            ->and(DB::table('sampling_satchel_pockets')->count())->toBe(1)
            ->and(samplingSatchelsMappingStep())->toMatchArray(['state' => 'done', 'command' => 'make:repository-mapping', 'arguments' => ['aggregate' => 'Satchel', '--domain' => SAMPLING_SATCHELS_CONTEXT.'/Satchel', '--no-interaction' => true]]);
    });

    it('waits for what it reads, and names a property or a column that does not map plainly', function () {
        writeSamplingSatchelsEnum();
        (new StructureFiles(base_path()))->write(samplingSatchelsManifest());

        expect(samplingSatchelsMappingStep())->toMatchArray([
            'state' => 'waiting',
            'reason' => 'EloquentSatchelRepository is not built yet; SatchelEntity is not built yet; SamplingSatchel is not built yet; SamplingSatchelPocket is not built yet; PocketEntity is not built yet',
        ]);

        $manifest = samplingSatchelsManifest(['name' => 'int', 'status' => 'string', 'price' => 'Shared/Money', 'packedAt' => 'DateTimeImmutable', 'note' => 'string'], children: false);
        (new StructureFiles(base_path()))->write($manifest);
        buildSamplingSatchels($manifest);

        $reason = 'Satchel.name: int does not fit the string column; Satchel.status: string does not fit the string column cast to SatchelStatus; Satchel.note has no column note in sampling_satchels; the column sampling_satchels.due_on maps to no state of SatchelEntity';

        expect(samplingSatchelsMappingStep())->toMatchArray(['state' => 'waiting', 'reason' => $reason]);

        $this->artisan('make:repository-mapping', ['aggregate' => 'Satchel', '--domain' => SAMPLING_SATCHELS_CONTEXT.'/Satchel'])
            ->expectsOutputToContain('The mapping of EloquentSatchelRepository waits:')
            ->expectsOutputToContain('Satchel.note has no column note in sampling_satchels')
            ->assertFailed();

        File::put(samplingSatchelsRepository(), str_replace(['// map entity attributes to model columns here', '// map model columns to entity attributes here'], ['$model->name = $entity->name();', 'name: $model->name,'], File::get(samplingSatchelsRepository())));

        expect(samplingSatchelsMappingStep())->toMatchArray(['state' => 'done', 'reason' => null]);
    });
});
