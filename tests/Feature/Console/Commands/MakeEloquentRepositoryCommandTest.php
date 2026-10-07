<?php

use Illuminate\Support\Facades\File;

/**
 * This file's scratch folder must not match any other generator test file's, or --parallel
 * runs delete each other's files midway.
 */
const SAMPLING_REPOSITORY_CONTEXT = 'SamplingRepository';

function repositoryPath(string $class): string
{
    return app_path("Infra/Persistence/Eloquent/Repositories/{$class}.php");
}

function repositoryInterfacePath(string $class): string
{
    return app_path('Domain/'.SAMPLING_REPOSITORY_CONTEXT."/Sample/{$class}.php");
}

function exceptionPath(string $class): string
{
    return app_path('Domain/'.SAMPLING_REPOSITORY_CONTEXT."/Sample/Exceptions/{$class}.php");
}

function logPayloadPath(string $class): string
{
    return app_path("Infra/Logging/EntityPayloads/{$class}.php");
}

function repositoryTestPath(string $class): string
{
    return base_path("tests/Feature/Infra/Persistence/Eloquent/Repositories/{$class}Test.php");
}

afterEach(function () {
    foreach (File::glob(app_path('Infra/Persistence/Eloquent/Repositories/EloquentSample*Repository.php')) as $file) {
        File::delete($file);
    }

    foreach (File::glob(app_path('Infra/Logging/EntityPayloads/Sample*LogPayload.php')) as $file) {
        File::delete($file);
    }

    File::deleteDirectory(app_path('Domain/'.SAMPLING_REPOSITORY_CONTEXT));

    foreach (File::glob(base_path('tests/Feature/Infra/Persistence/Eloquent/Repositories/EloquentSample*RepositoryTest.php')) as $file) {
        File::delete($file);
    }
});

it('generates the repository at the eloquent repositories path', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::exists(repositoryPath('EloquentSampleRepository')))->toBeTrue();
});

it('builds the class from the eloquent repository stub', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample']);

    $contents = File::get(repositoryPath('EloquentSampleRepository'));

    expect($contents)
        ->toContain('namespace App\Infra\Persistence\Eloquent\Repositories;')
        ->toContain('class EloquentSampleRepository extends EloquentRepository implements SampleRepository')
        ->toContain('public function save(SampleEntity $entity): void')
        ->toContain('public function saveMany(array $entities): void')
        ->toContain('@extends EloquentRepository<Sample, SampleEntity>')
        ->toContain('public function update(SampleEntity $entity): void')
        ->toContain('protected function newQuery(): Builder')
        ->toContain('return SampleRepositoryException::class;')
        ->toContain('protected function toModel(DomainEntity $entity): Model')
        ->not->toContain('@var')
        ->toContain('protected function toEntity(Model $model): DomainEntity')
        ->toContain('public function findById(string $id): ?SampleEntity')
        ->toContain('public function getById(string $id): SampleEntity')
        ->not->toContain('{{');
});

it('imports the domain collaborators of the given domain', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample']);

    expect(File::get(repositoryPath('EloquentSampleRepository')))
        ->toContain('use App\Domain\SamplingRepository\Sample\SampleEntity;')
        ->toContain('use App\Domain\SamplingRepository\Sample\SampleRepository;')
        ->toContain('use App\Domain\SamplingRepository\Sample\Exceptions\SampleNotFoundException;')
        ->toContain('use App\Domain\SamplingRepository\Sample\Exceptions\SampleRepositoryException;')
        ->toContain('use App\Infra\Logging\EntityPayloads\SampleLogPayload;')
        ->toContain('use App\Models\Sample;');
});

it('asks which aggregate owns the entity when no domain is given', function () {
    seedSamplingAggregate(SAMPLING_REPOSITORY_CONTEXT);

    try {
        $this->artisan('make:eloquent-repository', ['name' => 'Sample'])
            ->expectsQuestion('Which domain owns this entity?', 'SamplingRepository/Sample')
            ->assertSuccessful();

        expect(File::get(repositoryPath('EloquentSampleRepository')))
            ->toContain('use App\Domain\SamplingRepository\Sample\SampleEntity;');
    } finally {
        File::deleteDirectory(app_path('Domain/'.SAMPLING_REPOSITORY_CONTEXT));
    }
});

it('asks for an aggregate that does not exist yet', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample'])
        ->expectsQuestion('Which domain owns this entity?', '__another__')
        ->expectsQuestion('Which domain owns this entity?', 'samplingRepository/sample')
        ->assertSuccessful();

    expect(File::get(repositoryPath('EloquentSampleRepository')))
        ->toContain('use App\Domain\SamplingRepository\Sample\SampleEntity;');
});

it('fails instead of guessing the domain when it cannot ask', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --domain to name the domain that owns the entity.')
        ->assertFailed();

    expect(File::exists(repositoryPath('EloquentSampleRepository')))->toBeFalse();
});

it('uses a custom model when one is given', function () {
    $this->artisan('make:eloquent-repository', [
        'name' => 'Sample',
        '--domain' => 'SamplingRepository/Sample',
        '--model' => 'SampleRecord',
    ]);

    expect(File::get(repositoryPath('EloquentSampleRepository')))
        ->toContain('use App\Models\SampleRecord;')
        ->toContain('@extends EloquentRepository<SampleRecord, SampleEntity>')
        ->toContain('$model = new SampleRecord;')
        ->toContain('return SampleRecord::query();')
        ->not->toContain('use App\Models\Sample;');
});

it('strips eloquent, entity and repository decoration from the given name', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'EloquentSampleRepository', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::exists(repositoryPath('EloquentSampleRepository')))->toBeTrue();

    $this->artisan('make:eloquent-repository', ['name' => 'SampleTwoEntity', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::exists(repositoryPath('EloquentSampleTwoRepository')))->toBeTrue();
});

it('refuses to overwrite an existing repository unless forced', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample']);

    File::put(repositoryPath('EloquentSampleRepository'), '<?php // hand written');

    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertFailed();

    expect(File::get(repositoryPath('EloquentSampleRepository')))->toBe('<?php // hand written');

    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample', '--force' => true])
        ->assertSuccessful();

    expect(File::get(repositoryPath('EloquentSampleRepository')))
        ->toContain('class EloquentSampleRepository');
});

it('warns about the collaborators that do not exist yet', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->expectsOutputToContain('App\Domain\SamplingRepository\Sample\SampleEntity')
        ->expectsOutputToContain('App\Models\Sample')
        ->assertSuccessful();
});

it('creates the collaborators the repository depends on', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::exists(repositoryPath('EloquentSampleRepository')))->toBeTrue()
        ->and(File::exists(repositoryInterfacePath('SampleRepository')))->toBeTrue()
        ->and(File::exists(exceptionPath('SampleNotFoundException')))->toBeTrue()
        ->and(File::exists(exceptionPath('SampleRepositoryException')))->toBeTrue()
        ->and(File::exists(logPayloadPath('SampleLogPayload')))->toBeTrue();
});

it('builds the repository interface from its stub', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample']);

    expect(File::get(repositoryInterfacePath('SampleRepository')))
        ->toContain('namespace App\Domain\SamplingRepository\Sample;')
        ->toContain('use App\Domain\SamplingRepository\Sample\Exceptions\SampleNotFoundException;')
        ->toContain('use App\Domain\SamplingRepository\Sample\Exceptions\SampleRepositoryException;')
        ->toContain('interface SampleRepository')
        ->toContain('public function save(SampleEntity $entity): void;')
        ->toContain('public function saveMany(array $entities): void;')
        ->toContain('public function update(SampleEntity $entity): void;')
        ->toContain('public function findById(string $id): ?SampleEntity;')
        ->toContain('public function getById(string $id): SampleEntity;')
        ->not->toContain('{{');
});

it('builds both exceptions from their stubs', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::get(exceptionPath('SampleNotFoundException')))
        ->toContain('namespace App\Domain\SamplingRepository\Sample\Exceptions;')
        ->toContain('use App\Domain\Shared\Exceptions\EntityNotFoundException;')
        ->toContain('class SampleNotFoundException extends EntityNotFoundException {}');

    expect(File::get(exceptionPath('SampleRepositoryException')))
        ->toContain('namespace App\Domain\SamplingRepository\Sample\Exceptions;')
        ->toContain('use App\Domain\Shared\Exceptions\RepositoryException;')
        ->toContain('class SampleRepositoryException extends RepositoryException')
        ->not->toContain('{{');
});

/**
 * RepositoryException declares entityName() abstract. An empty subclass is fatal the moment
 * it is loaded, not when it is called, so the stub writes every delegate from the start.
 */
it('implements the abstract entity name on the repository exception it writes', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::get(exceptionPath('SampleRepositoryException')))
        ->toContain('use App\Domain\SamplingRepository\Sample\SampleEntity;')
        ->toContain('public static function entityName(): string')
        ->toContain('return SampleEntity::entityName();');
});

it('builds the log payload from its stub', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::get(logPayloadPath('SampleLogPayload')))
        ->toContain('namespace App\Infra\Logging\EntityPayloads;')
        ->toContain('use App\Domain\SamplingRepository\Sample\SampleEntity;')
        ->toContain('final class SampleLogPayload extends EntityLogPayload')
        ->toContain('protected function map(DomainEntity $entity): array')
        ->toContain('/** @var SampleEntity $entity */')
        ->not->toContain('{{');
});

it('creates the repository alone with --only-repository', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample', '--only-repository' => true])
        ->assertSuccessful();

    expect(File::exists(repositoryPath('EloquentSampleRepository')))->toBeTrue()
        ->and(File::exists(repositoryInterfacePath('SampleRepository')))->toBeFalse()
        ->and(File::exists(exceptionPath('SampleNotFoundException')))->toBeFalse()
        ->and(File::exists(exceptionPath('SampleRepositoryException')))->toBeFalse()
        ->and(File::exists(logPayloadPath('SampleLogPayload')))->toBeFalse();
});

it('leaves an existing collaborator untouched', function () {
    File::ensureDirectoryExists(app_path('Domain/'.SAMPLING_REPOSITORY_CONTEXT.'/Sample/Exceptions'));
    File::put(exceptionPath('SampleNotFoundException'), '<?php // hand written');

    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(exceptionPath('SampleNotFoundException')))->toBe('<?php // hand written')
        ->and(File::exists(exceptionPath('SampleRepositoryException')))->toBeTrue();
});

it('only warns about the entity and the model it cannot create', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->doesntExpectOutputToContain('App\Domain\SamplingRepository\Sample\SampleRepository] does not exist yet')
        ->doesntExpectOutputToContain('App\Domain\SamplingRepository\Sample\Exceptions\SampleNotFoundException] does not exist yet')
        ->doesntExpectOutputToContain('App\Infra\Logging\EntityPayloads\SampleLogPayload] does not exist yet')
        ->expectsOutputToContain('php artisan make:entity Sample --domain=SamplingRepository/Sample')
        ->expectsOutputToContain('php artisan make:model Sample')
        ->assertSuccessful();
});

it('still creates the missing collaborators when the repository already exists', function () {
    File::put(repositoryPath('EloquentSampleRepository'), '<?php // hand written');

    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertFailed();

    expect(File::get(repositoryPath('EloquentSampleRepository')))->toBe('<?php // hand written')
        ->and(File::exists(repositoryInterfacePath('SampleRepository')))->toBeTrue()
        ->and(File::exists(exceptionPath('SampleNotFoundException')))->toBeTrue()
        ->and(File::exists(exceptionPath('SampleRepositoryException')))->toBeTrue()
        ->and(File::exists(logPayloadPath('SampleLogPayload')))->toBeTrue();
});

it('writes a test file that already calls the repository contract', function () {
    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->assertSuccessful();

    expect(File::get(repositoryTestPath('EloquentSampleRepository')))
        ->toContain("require_once __DIR__.'/../RepositoryContract.php';")
        ->toContain('repositoryContract(')
        ->toContain('repository: SampleRepository::class')
        ->toContain('model: Sample::class')
        ->not->toContain('{{');
});

it('leaves an existing repository test untouched', function () {
    File::put(repositoryTestPath('EloquentSampleRepository'), '<?php // hand written');

    $this->artisan('make:eloquent-repository', ['name' => 'Sample', '--domain' => 'SamplingRepository/Sample'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(repositoryTestPath('EloquentSampleRepository')))->toBe('<?php // hand written');
});
