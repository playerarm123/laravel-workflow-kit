<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

/**
 * The scratch context of this file. It must differ from every other generator test file's, or
 * --parallel lets them delete each other's fixtures.
 */
const SAMPLING_EXCEPTION_CONTEXT = 'SamplingException';

/**
 * The shared kernel is real code, so a value written there gets a name no other file uses and
 * is deleted by name.
 */
const SAMPLING_SHARED_VALUE = 'SamplingSharedValue';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeDomainException(array $arguments): PendingCommand
{
    return test()->artisan('make:domain-exception', $arguments);
}

function domainExceptionPath(string $relative): string
{
    return app_path($relative.'.php');
}

function forgetSamplingExceptions(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_EXCEPTION_CONTEXT));
    File::deleteDirectory(app_path('Application/'.SAMPLING_EXCEPTION_CONTEXT));
    File::delete(domainExceptionPath('Domain/Shared/Exceptions/'.SAMPLING_SHARED_VALUE.'Exception'));
}

beforeEach(fn () => forgetSamplingExceptions());

afterEach(fn () => forgetSamplingExceptions());

it('writes a refusal into its aggregate on the context base, and the base the first time', function () {
    runMakeDomainException(['name' => 'ParcelAlreadyShipped', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'refusal'])
        ->expectsOutputToContain('Context base')
        ->assertSuccessful();

    expect(File::get(domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Parcel/Exceptions/ParcelAlreadyShippedException')))
        ->toContain('namespace App\Domain\SamplingException\Parcel\Exceptions;')
        ->toContain('use App\Domain\SamplingException\Exceptions\SamplingExceptionDomainException;')
        ->toContain('final class ParcelAlreadyShippedException extends SamplingExceptionDomainException')
        ->not->toContain('{{')
        ->and(File::get(domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Exceptions/SamplingExceptionDomainException')))
        ->toContain('abstract class SamplingExceptionDomainException extends DomainException');
});

it('keeps the context base that already exists', function () {
    $base = domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Exceptions/SamplingExceptionDomainException');
    File::ensureDirectoryExists(dirname($base));
    File::put($base, '<?php // hand written base');

    runMakeDomainException(['name' => 'ParcelLost', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'refusal'])
        ->doesntExpectOutputToContain('Context base')
        ->assertSuccessful();

    expect(File::get($base))->toBe('<?php // hand written base');
});

it('writes an invalid value into its aggregate or the shared kernel', function () {
    runMakeDomainException(['name' => 'InvalidParcelWeight', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'value'])->assertSuccessful();
    runMakeDomainException(['name' => SAMPLING_SHARED_VALUE, '--domain' => 'Shared', '--kind' => 'value'])->assertSuccessful();

    expect(File::get(domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Parcel/Exceptions/InvalidParcelWeightException')))
        ->toContain('use App\Domain\Shared\Exceptions\DomainValueException;')
        ->toContain('final class InvalidParcelWeightException extends DomainValueException')
        ->and(File::get(domainExceptionPath('Domain/Shared/Exceptions/'.SAMPLING_SHARED_VALUE.'Exception')))
        ->toContain('namespace App\Domain\Shared\Exceptions;')
        ->not->toContain('use App\Domain\Shared\Exceptions\DomainValueException;')
        ->toContain('extends DomainValueException')
        ->and(File::exists(domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Exceptions/SamplingExceptionDomainException')))->toBeFalse();
});

it('writes an application exception beside its context or its use case', function () {
    runMakeDomainException(['name' => 'UnknownCourier', '--domain' => SAMPLING_EXCEPTION_CONTEXT, '--kind' => 'application'])->assertSuccessful();
    runMakeDomainException(['name' => 'DuplicateShipment', '--domain' => SAMPLING_EXCEPTION_CONTEXT, '--kind' => 'application', '--use-case' => 'ShipParcel'])->assertSuccessful();

    expect(File::get(domainExceptionPath('Application/'.SAMPLING_EXCEPTION_CONTEXT.'/UnknownCourierException')))
        ->toContain('namespace App\Application\SamplingException;')
        ->toContain('use App\Application\ApplicationException;')
        ->toContain('final class UnknownCourierException extends ApplicationException')
        ->and(File::get(domainExceptionPath('Application/'.SAMPLING_EXCEPTION_CONTEXT.'/UseCases/ShipParcel/DuplicateShipmentException')))
        ->toContain('namespace App\Application\SamplingException\UseCases\ShipParcel;');
});

it('strips exception decoration from the given name', function () {
    runMakeDomainException(['name' => 'ParcelLostException', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'refusal'])->assertSuccessful();

    expect(File::exists(domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Parcel/Exceptions/ParcelLostException')))->toBeTrue();
});

it('refuses a domain that cannot hold the kind and writes nothing', function (array $options, string $message) {
    runMakeDomainException(['name' => 'Misplaced', ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::isDirectory(app_path('Domain/'.SAMPLING_EXCEPTION_CONTEXT)))->toBeFalse()
        ->and(File::isDirectory(app_path('Application/'.SAMPLING_EXCEPTION_CONTEXT)))->toBeFalse();
})->with([
    'a refusal with no aggregate' => [['--domain' => SAMPLING_EXCEPTION_CONTEXT, '--kind' => 'refusal'], 'make:domain-service --exception'],
    'a value with no aggregate' => [['--domain' => SAMPLING_EXCEPTION_CONTEXT, '--kind' => 'value'], '--domain=Shared'],
    'a refusal in the shared kernel' => [['--domain' => 'Shared', '--kind' => 'refusal'], 'Only --kind=value may go to the shared kernel'],
    'an application exception in an aggregate' => [['--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'application'], 'pass --domain={Context}'],
    'a use case on a refusal' => [['--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'refusal', '--use-case' => 'ShipParcel'], 'Only --kind=application takes --use-case.'],
    'an unknown kind' => [['--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'failure'], 'Pass --kind=refusal, --kind=value or --kind=application.'],
]);

it('fails instead of guessing the kind or the domain when it cannot ask', function () {
    runMakeDomainException(['name' => 'Misplaced', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --kind=refusal, --kind=value or --kind=application.')
        ->assertFailed();

    runMakeDomainException(['name' => 'Misplaced', '--kind' => 'refusal', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --domain to name the domain that owns the exception.')
        ->assertFailed();
});

it('asks which kind of exception it is', function () {
    runMakeDomainException(['name' => 'ParcelLost', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel'])
        ->expectsQuestion('Which kind of exception is it?', 'refusal')
        ->assertSuccessful();

    expect(File::exists(domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Parcel/Exceptions/ParcelLostException')))->toBeTrue();
});

it('keeps an exception that already exists unless forced', function () {
    $path = domainExceptionPath('Domain/'.SAMPLING_EXCEPTION_CONTEXT.'/Parcel/Exceptions/ParcelLostException');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php // hand written');

    runMakeDomainException(['name' => 'ParcelLost', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'refusal'])->assertFailed();

    expect(File::get($path))->toBe('<?php // hand written');

    runMakeDomainException(['name' => 'ParcelLost', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Parcel', '--kind' => 'refusal', '--force' => true])->assertSuccessful();

    expect(File::get($path))->toContain('final class ParcelLostException');
});

/**
 * A class loads once per process, so this case uses names no other case writes.
 */
it('writes exceptions that load on their base with nothing exceptions.md forbids', function () {
    runMakeDomainException(['name' => 'LoadableRefused', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Loadable', '--kind' => 'refusal'])->assertSuccessful();
    runMakeDomainException(['name' => 'InvalidLoadable', '--domain' => SAMPLING_EXCEPTION_CONTEXT.'/Loadable', '--kind' => 'value'])->assertSuccessful();
    runMakeDomainException(['name' => 'LoadableUnavailable', '--domain' => SAMPLING_EXCEPTION_CONTEXT, '--kind' => 'application'])->assertSuccessful();

    $domain = 'App\\Domain\\'.SAMPLING_EXCEPTION_CONTEXT;
    $generated = [
        $domain.'\\Loadable\\Exceptions\\LoadableRefusedException' => $domain.'\\Exceptions\\'.SAMPLING_EXCEPTION_CONTEXT.'DomainException',
        $domain.'\\Loadable\\Exceptions\\InvalidLoadableException' => 'App\\Domain\\Shared\\Exceptions\\DomainValueException',
        'App\\Application\\'.SAMPLING_EXCEPTION_CONTEXT.'\\LoadableUnavailableException' => 'App\\Application\\ApplicationException',
    ];

    foreach ($generated as $class => $base) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getParentClass()->getName())->toBe($base)
            ->and($reflection->getMethod('context')->getDeclaringClass()->getName())->not->toBe($class)
            ->and($reflection->hasMethod('render') || $reflection->hasMethod('report'))->toBeFalse();
    }

    expect((new ReflectionClass($domain.'\\Exceptions\\'.SAMPLING_EXCEPTION_CONTEXT.'DomainException'))->isAbstract())->toBeTrue();

    $pint = Process::path(base_path())->run([
        base_path('vendor/bin/pint'), '--test',
        app_path('Domain/'.SAMPLING_EXCEPTION_CONTEXT), app_path('Application/'.SAMPLING_EXCEPTION_CONTEXT),
    ]);

    expect($pint->successful())->toBeTrue($pint->output());
});
