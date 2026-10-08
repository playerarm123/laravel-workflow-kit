<?php

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

/**
 * This file's scratch folder must not match any other generator test file's, or --parallel
 * runs delete each other's files midway.
 */
const SAMPLING_SERVICE_CONTEXT = 'SamplingService';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeDomainService(array $arguments): PendingCommand
{
    return test()->artisan('make:domain-service', ['--domain' => SAMPLING_SERVICE_CONTEXT, ...$arguments]);
}

function domainServicePath(string $relative): string
{
    return app_path('Domain/'.SAMPLING_SERVICE_CONTEXT."/Services/{$relative}.php");
}

function domainServiceTestPath(string $relative, string $suite = 'Unit'): string
{
    return base_path("tests/{$suite}/Domain/".SAMPLING_SERVICE_CONTEXT."/Services/{$relative}Test.php");
}

beforeEach(function () {
    File::deleteDirectory(app_path('Domain/'.SAMPLING_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Domain/'.SAMPLING_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_SERVICE_CONTEXT));
});

afterEach(function () {
    File::deleteDirectory(app_path('Domain/'.SAMPLING_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Domain/'.SAMPLING_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_SERVICE_CONTEXT));
});

it('generates a plain service with no data and no result', function () {
    runMakeDomainService(['name' => 'OpenSample', '--plain' => true])->assertSuccessful();

    expect(File::get(domainServicePath('OpenSample/OpenSampleService')))
        ->toContain('namespace App\Domain\SamplingService\Services\OpenSample;')
        ->toContain('final class OpenSampleService')
        ->toContain('public function handle(): void')
        ->not->toContain('__construct')
        ->not->toContain('{{')
        ->and(File::exists(domainServicePath('OpenSample/OpenSampleData')))->toBeFalse()
        ->and(File::exists(domainServicePath('OpenSample/OpenSampleResult')))->toBeFalse();
});

it('generates a creates service that builds the named aggregate from its data', function () {
    runMakeDomainService(['name' => 'RegisterSample', '--creates' => 'Sample'])->assertSuccessful();

    expect(File::get(domainServicePath('RegisterSample/RegisterSampleService')))
        ->toContain('use App\Domain\SamplingService\Sample\SampleEntity;')
        ->toContain('public function handle(string $id, RegisterSampleData $data): SampleEntity')
        ->not->toContain('{{')
        ->and(File::get(domainServicePath('RegisterSample/RegisterSampleData')))
        ->toContain('final readonly class RegisterSampleData')
        ->and(File::exists(domainServicePath('RegisterSample/RegisterSampleResult')))->toBeFalse();
});

it('generates a data in, result out service with both payloads beside it', function () {
    runMakeDomainService(['name' => 'MoveSample', '--data' => true])->assertSuccessful();

    expect(File::get(domainServicePath('MoveSample/MoveSampleService')))
        ->toContain('public function handle(MoveSampleData $data): MoveSampleResult')
        ->not->toContain('use App\Domain')
        ->and(File::get(domainServicePath('MoveSample/MoveSampleData')))->toContain('final readonly class MoveSampleData')
        ->and(File::get(domainServicePath('MoveSample/MoveSampleResult')))->toContain('final readonly class MoveSampleResult');
});

it('creates the service exception only when asked to', function () {
    runMakeDomainService(['name' => 'MoveSample', '--data' => true, '--exception' => true])->assertSuccessful();
    runMakeDomainService(['name' => 'OpenSample', '--plain' => true])->assertSuccessful();

    expect(File::get(domainServicePath('MoveSample/MoveSampleException')))
        ->toContain('use App\Domain\Shared\DomainException;')
        ->toContain('final class MoveSampleException extends DomainException')
        ->and(File::exists(domainServicePath('OpenSample/OpenSampleException')))->toBeFalse();
});

it('injects a repository of the same context', function () {
    runMakeDomainService(['name' => 'RegisterSample', '--creates' => 'Sample', '--repo' => 'Sample'])->assertSuccessful();

    expect(File::get(domainServicePath('RegisterSample/RegisterSampleService')))
        ->toContain("use App\Domain\SamplingService\Sample\SampleEntity;\nuse App\Domain\SamplingService\Sample\SampleRepository;")
        ->toContain('protected SampleRepository $repo,');
});

it('strips service decoration from the given name', function () {
    runMakeDomainService(['name' => 'OpenSampleService', '--plain' => true])->assertSuccessful();

    expect(File::exists(domainServicePath('OpenSample/OpenSampleService')))->toBeTrue()
        ->and(File::exists(domainServicePath('OpenSampleService/OpenSampleServiceService')))->toBeFalse();
});

it('writes a unit test mirroring where a service that only computes lands', function () {
    runMakeDomainService(['name' => 'OpenSample', '--plain' => true])->assertSuccessful();

    expect(File::get(domainServiceTestPath('OpenSample/OpenSampleService')))
        ->toContain('use App\Domain\SamplingService\Services\OpenSample\OpenSampleService;')
        ->toContain('new OpenSampleService;')
        ->not->toContain('app(')
        ->not->toContain('{{')
        ->and(File::exists(domainServiceTestPath('OpenSample/OpenSampleService', 'Feature')))->toBeFalse();
});

it('writes a feature test for a service given a repository', function () {
    runMakeDomainService(['name' => 'OpenSample', '--plain' => true, '--repo' => 'Sample'])->assertSuccessful();

    expect(File::get(domainServiceTestPath('OpenSample/OpenSampleService', 'Feature')))
        ->toContain('app(OpenSampleService::class)')
        ->not->toContain('{{')
        ->and(File::exists(domainServiceTestPath('OpenSample/OpenSampleService')))->toBeFalse();
});

it('refuses two shapes at once and writes nothing', function () {
    runMakeDomainService(['name' => 'OpenSample', '--plain' => true, '--data' => true])
        ->expectsOutputToContain('Pass exactly one of --creates=, --data or --plain.')
        ->assertFailed();

    expect(File::exists(domainServicePath('OpenSample/OpenSampleService')))->toBeFalse();
});

it('fails instead of guessing the shape when it cannot ask', function () {
    runMakeDomainService(['name' => 'OpenSample', '--no-interaction' => true])
        ->expectsOutputToContain('Pass exactly one of --creates=, --data or --plain.')
        ->assertFailed();

    expect(File::exists(domainServicePath('OpenSample/OpenSampleService')))->toBeFalse();
});

it('asks which shape to take, and which aggregate a creates service builds', function () {
    runMakeDomainService(['name' => 'RegisterSample'])
        ->expectsQuestion('Which shape does handle() take?', 'creates')
        ->expectsQuestion('Which aggregate does it create?', 'Sample')
        ->assertSuccessful();

    expect(File::get(domainServicePath('RegisterSample/RegisterSampleService')))
        ->toContain('public function handle(string $id, RegisterSampleData $data): SampleEntity');
});

it('fails instead of guessing the domain when it cannot ask', function () {
    $this->artisan('make:domain-service', ['name' => 'OpenSample', '--plain' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Pass --domain to name the domain that owns the domain service.')
        ->assertFailed();
});

it('keeps a data class that already exists', function () {
    File::ensureDirectoryExists(dirname(domainServicePath('MoveSample/MoveSampleData')));
    File::put(domainServicePath('MoveSample/MoveSampleData'), '<?php // hand written data');

    runMakeDomainService(['name' => 'MoveSample', '--data' => true])
        ->expectsOutputToContain('Data [App\Domain\SamplingService\Services\MoveSample\MoveSampleData] already exists.')
        ->assertSuccessful();

    expect(File::get(domainServicePath('MoveSample/MoveSampleData')))->toBe('<?php // hand written data');
});

it('warns about the entity and repository it names but did not write', function () {
    runMakeDomainService(['name' => 'RegisterSample', '--creates' => 'Sample', '--repo' => 'Sample'])
        ->expectsOutputToContain('Entity [App\Domain\SamplingService\Sample\SampleEntity] does not exist yet.')
        ->expectsOutputToContain('Repository [App\Domain\SamplingService\Sample\SampleRepository] does not exist yet.')
        ->assertSuccessful();
});

it('generates services in every shape that load and refuse to run until written', function () {
    $this->artisan('make:entity', ['name' => 'Loadable', '--domain' => SAMPLING_SERVICE_CONTEXT.'/Loadable'])->assertSuccessful();

    runMakeDomainService(['name' => 'BuildLoadable', '--creates' => 'Loadable', '--exception' => true])->assertSuccessful();
    runMakeDomainService(['name' => 'ShiftLoadable', '--data' => true])->assertSuccessful();
    runMakeDomainService(['name' => 'TouchLoadable', '--plain' => true])->assertSuccessful();

    $namespace = 'App\\Domain\\'.SAMPLING_SERVICE_CONTEXT.'\\Services\\';
    $creates = new ReflectionMethod($namespace.'BuildLoadable\\BuildLoadableService', 'handle');
    $data = new ReflectionMethod($namespace.'ShiftLoadable\\ShiftLoadableService', 'handle');

    expect((string) $creates->getReturnType())->toBe('App\\Domain\\'.SAMPLING_SERVICE_CONTEXT.'\\Loadable\\LoadableEntity')
        ->and(class_exists($namespace.'BuildLoadable\\BuildLoadableException'))->toBeTrue()
        ->and((string) $data->getReturnType())->toBe($namespace.'ShiftLoadable\\ShiftLoadableResult')
        ->and(class_exists($namespace.'ShiftLoadable\\ShiftLoadableData'))->toBeTrue();

    $plain = $namespace.'TouchLoadable\\TouchLoadableService';

    expect(fn () => (new $plain)->handle())->toThrow(LogicException::class, 'TouchLoadableService::handle() is not implemented yet.');
});
