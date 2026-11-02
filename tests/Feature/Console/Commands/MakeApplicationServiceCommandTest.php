<?php

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

/**
 * This file's scratch folder must not match any other generator test file's, or --parallel
 * runs delete each other's files midway.
 */
const SAMPLING_APP_SERVICE_CONTEXT = 'SamplingAppService';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeApplicationService(array $arguments): PendingCommand
{
    return test()->artisan('make:application-service', ['--domain' => SAMPLING_APP_SERVICE_CONTEXT, ...$arguments]);
}

function applicationServicePath(string $relative): string
{
    return app_path('Application/'.SAMPLING_APP_SERVICE_CONTEXT."/Services/{$relative}.php");
}

function applicationServiceTestPath(string $relative, string $suite = 'Unit'): string
{
    return base_path("tests/{$suite}/Application/".SAMPLING_APP_SERVICE_CONTEXT."/Services/{$relative}Test.php");
}

beforeEach(function () {
    File::deleteDirectory(app_path('Application/'.SAMPLING_APP_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Application/'.SAMPLING_APP_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Application/'.SAMPLING_APP_SERVICE_CONTEXT));
});

afterEach(function () {
    File::deleteDirectory(app_path('Application/'.SAMPLING_APP_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Application/'.SAMPLING_APP_SERVICE_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Application/'.SAMPLING_APP_SERVICE_CONTEXT));
});

it('generates a plain service with no data and no result', function () {
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true])->assertSuccessful();

    expect(File::get(applicationServicePath('ResolveOwner/ResolveOwnerService')))
        ->toContain('namespace App\Application\SamplingAppService\Services\ResolveOwner;')
        ->toContain('final class ResolveOwnerService')
        ->toContain('public function handle(): void')
        ->not->toContain('__construct')
        ->not->toContain('{{')
        ->and(File::exists(applicationServicePath('ResolveOwner/ResolveOwnerData')))->toBeFalse()
        ->and(File::exists(applicationServicePath('ResolveOwner/ResolveOwnerResult')))->toBeFalse();
});

it('generates a data in, result out service with both payloads beside it', function () {
    runMakeApplicationService(['name' => 'GatherSample', '--data' => true])->assertSuccessful();

    expect(File::get(applicationServicePath('GatherSample/GatherSampleService')))
        ->toContain('public function handle(GatherSampleData $data): GatherSampleResult')
        ->not->toContain('use App\\')
        ->and(File::get(applicationServicePath('GatherSample/GatherSampleData')))->toContain('final readonly class GatherSampleData')
        ->and(File::get(applicationServicePath('GatherSample/GatherSampleResult')))->toContain('final readonly class GatherSampleResult');
});

it('creates the service exception on the application base only when asked to', function () {
    runMakeApplicationService(['name' => 'GatherSample', '--data' => true, '--exception' => true])->assertSuccessful();
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true])->assertSuccessful();

    expect(File::get(applicationServicePath('GatherSample/GatherSampleException')))
        ->toContain('use App\Application\ApplicationException;')
        ->toContain('final class GatherSampleException extends ApplicationException')
        ->and(File::exists(applicationServicePath('ResolveOwner/ResolveOwnerException')))->toBeFalse();
});

it('injects the actor port and a repository of its own context', function () {
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true, '--actor' => true, '--repo' => 'Sample'])->assertSuccessful();

    expect(File::get(applicationServicePath('ResolveOwner/ResolveOwnerService')))
        ->toContain("use App\Application\Auth\UserContext;\nuse App\Domain\SamplingAppService\Sample\SampleRepository;")
        ->toContain("protected UserContext \$user,\n        protected SampleRepository \$repo,");
});

it('injects a repository of another context', function () {
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true, '--repo' => 'Billing/AccountRepository'])->assertSuccessful();

    expect(File::get(applicationServicePath('ResolveOwner/ResolveOwnerService')))
        ->toContain('use App\Domain\Billing\Account\AccountRepository;')
        ->toContain('protected AccountRepository $repo,');
});

it('strips service decoration from the given name', function () {
    runMakeApplicationService(['name' => 'ResolveOwnerService', '--plain' => true])->assertSuccessful();

    expect(File::exists(applicationServicePath('ResolveOwner/ResolveOwnerService')))->toBeTrue()
        ->and(File::exists(applicationServicePath('ResolveOwnerService/ResolveOwnerServiceService')))->toBeFalse();
});

it('writes a unit test for a service that injects nothing', function () {
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true])->assertSuccessful();

    expect(File::get(applicationServiceTestPath('ResolveOwner/ResolveOwnerService')))
        ->toContain('use App\Application\SamplingAppService\Services\ResolveOwner\ResolveOwnerService;')
        ->toContain('new ResolveOwnerService;')
        ->not->toContain('{{')
        ->and(File::exists(applicationServiceTestPath('ResolveOwner/ResolveOwnerService', 'Feature')))->toBeFalse();
});

it('writes a feature test for a service the container builds', function (array $injects) {
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true, ...$injects])->assertSuccessful();

    expect(File::get(applicationServiceTestPath('ResolveOwner/ResolveOwnerService', 'Feature')))
        ->toContain('app(ResolveOwnerService::class)')
        ->not->toContain('{{')
        ->and(File::exists(applicationServiceTestPath('ResolveOwner/ResolveOwnerService')))->toBeFalse();
})->with([
    'the actor' => [['--actor' => true]],
    'a repository' => [['--repo' => 'Sample']],
]);

it('refuses both shapes at once and writes nothing', function () {
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true, '--data' => true])
        ->expectsOutputToContain('Pass exactly one of --data or --plain.')
        ->assertFailed();

    expect(File::exists(applicationServicePath('ResolveOwner/ResolveOwnerService')))->toBeFalse();
});

it('fails instead of guessing the shape when it cannot ask', function () {
    runMakeApplicationService(['name' => 'ResolveOwner', '--no-interaction' => true])
        ->expectsOutputToContain('Pass exactly one of --data or --plain.')
        ->assertFailed();
});

it('asks which shape to take', function () {
    runMakeApplicationService(['name' => 'GatherSample'])
        ->expectsQuestion('Which shape does handle() take?', 'data')
        ->assertSuccessful();

    expect(File::get(applicationServicePath('GatherSample/GatherSampleService')))
        ->toContain('public function handle(GatherSampleData $data): GatherSampleResult');
});

it('fails instead of guessing the domain when it cannot ask', function () {
    $this->artisan('make:application-service', ['name' => 'ResolveOwner', '--plain' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Pass --domain to name the domain that owns the application service.')
        ->assertFailed();
});

it('refuses the shared kernel', function () {
    $this->artisan('make:application-service', ['name' => 'ResolveOwner', '--domain' => 'Shared', '--plain' => true])
        ->expectsOutputToContain('The shared kernel holds no application code.')
        ->assertFailed();

    expect(File::exists(app_path('Application/Shared')))->toBeFalse();
});

it('warns about the repository it names but did not write', function () {
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true, '--repo' => 'Sample'])
        ->expectsOutputToContain('Repository [App\Domain\SamplingAppService\Sample\SampleRepository] does not exist yet.')
        ->assertSuccessful();
});

it('generates services in both shapes that load and refuse to run until written', function () {
    runMakeApplicationService(['name' => 'GatherSample', '--data' => true, '--exception' => true])->assertSuccessful();
    runMakeApplicationService(['name' => 'ResolveOwner', '--plain' => true])->assertSuccessful();

    $namespace = 'App\\Application\\'.SAMPLING_APP_SERVICE_CONTEXT.'\\Services\\';
    $data = new ReflectionMethod($namespace.'GatherSample\\GatherSampleService', 'handle');

    expect((string) $data->getReturnType())->toBe($namespace.'GatherSample\\GatherSampleResult')
        ->and(class_exists($namespace.'GatherSample\\GatherSampleData'))->toBeTrue()
        ->and(is_subclass_of($namespace.'GatherSample\\GatherSampleException', 'App\\Application\\ApplicationException'))->toBeTrue();

    $plain = $namespace.'ResolveOwner\\ResolveOwnerService';

    expect(fn () => (new $plain)->handle())->toThrow(LogicException::class, 'ResolveOwnerService::handle() is not implemented yet.');
});
