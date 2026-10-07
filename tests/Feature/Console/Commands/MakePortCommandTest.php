<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

/**
 * The scratch context and infra folder of this file. They must differ from every other generator
 * test file's, or --parallel lets them delete each other's fixtures.
 */
const SAMPLING_PORT_CONTEXT = 'SamplingPort';

/**
 * The shared kernel is real code, so a port written there gets a name no other file uses and is
 * deleted by name.
 */
const SAMPLING_SHARED_PORT = 'SamplingSharedPort';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakePort(array $arguments): PendingCommand
{
    return test()->artisan('make:port', $arguments);
}

function forgetSamplingPorts(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_PORT_CONTEXT));
    File::deleteDirectory(app_path('Application/'.SAMPLING_PORT_CONTEXT));
    File::deleteDirectory(app_path('Infra/'.SAMPLING_PORT_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Infra/'.SAMPLING_PORT_CONTEXT));
    File::delete(app_path('Domain/Shared/Ports/'.SAMPLING_SHARED_PORT.'.php'));
}

/**
 * A port a human already filled in. A class loads once per process, so each case that loads one
 * names its own.
 */
function writeSamplingPort(string $name, string $body): void
{
    $path = app_path('Domain/'.SAMPLING_PORT_CONTEXT."/Ports/{$name}.php");

    File::ensureDirectoryExists(dirname($path));
    File::put($path, sprintf("<?php\n\nnamespace App\\Domain\\%s\\Ports;\n\ninterface %s\n{\n%s\n}\n", SAMPLING_PORT_CONTEXT, $name, $body));
}

beforeEach(fn () => forgetSamplingPorts());

afterEach(fn () => forgetSamplingPorts());

it('writes a domain port in its context\'s Ports folder', function () {
    runMakePort(['name' => 'Clock', '--domain' => SAMPLING_PORT_CONTEXT])->assertSuccessful();

    expect(File::get(app_path('Domain/'.SAMPLING_PORT_CONTEXT.'/Ports/Clock.php')))
        ->toContain('namespace App\Domain\\'.SAMPLING_PORT_CONTEXT.'\Ports;')
        ->toContain('interface Clock')
        ->toContain("What the domain needs from outside the core, in the domain's own types.")
        ->not->toContain('{{');
});

it('writes a shared port in the shared kernel', function () {
    runMakePort(['name' => SAMPLING_SHARED_PORT, '--domain' => 'Shared'])->assertSuccessful();

    expect(File::get(app_path('Domain/Shared/Ports/'.SAMPLING_SHARED_PORT.'.php')))
        ->toContain('namespace App\Domain\Shared\Ports;');
});

it('writes an application port at the root of its context', function () {
    runMakePort(['name' => 'Mailbox', '--application' => SAMPLING_PORT_CONTEXT])->assertSuccessful();

    expect(File::get(app_path('Application/'.SAMPLING_PORT_CONTEXT.'/Mailbox.php')))
        ->toContain('namespace App\Application\\'.SAMPLING_PORT_CONTEXT.';')
        ->toContain("What a use case needs from outside the core, in the application's own types.");
});

it('refuses options that cannot place the port or its adapter', function (array $options, string $message) {
    runMakePort(['name' => 'Clock', ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::isDirectory(app_path('Domain/'.SAMPLING_PORT_CONTEXT)))->toBeFalse();
})->with([
    'no layer' => [[], 'Pass one of --domain'],
    'both layers' => [['--domain' => SAMPLING_PORT_CONTEXT, '--application' => SAMPLING_PORT_CONTEXT], 'Pass one of --domain'],
    'the shared kernel as an application' => [['--application' => 'Shared'], 'pass --domain=Shared'],
    'an adapter with no folder' => [['--domain' => SAMPLING_PORT_CONTEXT, '--adapter' => 'System'], 'needs both --adapter'],
]);

it('writes an adapter with one throwing method per method of a filled-in port', function () {
    writeSamplingPort('SamplingClock', <<<'PHP'
    public const int SECOND = 1;

    public function now(?\DateTimeZone $zone = null, int|string $offset = 0, string ...$labels): \DateTimeImmutable;

    public function sleep(int $seconds = self::SECOND): void;
PHP);

    runMakePort(['name' => 'SamplingClock', '--domain' => SAMPLING_PORT_CONTEXT, '--adapter' => 'system', '--infra' => SAMPLING_PORT_CONTEXT])
        ->expectsOutputToContain('already exists, read as it is')
        ->expectsOutputToContain('SamplingClock::class => SystemSamplingClock::class,')
        ->assertSuccessful();

    $adapter = app_path('Infra/'.SAMPLING_PORT_CONTEXT.'/SystemSamplingClock.php');

    expect(File::get($adapter))
        ->toContain('class SystemSamplingClock implements SamplingClock')
        ->toContain('public function now(?DateTimeZone $zone = null, string|int $offset = 0, string ...$labels): DateTimeImmutable')
        ->toContain('public function sleep(int $seconds = self::SECOND): void')
        ->toContain("throw new LogicException('SystemSamplingClock::sleep() is not implemented yet.');")
        ->toContain('use DateTimeZone;')
        ->not->toContain('use self;');

    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', $adapter]);

    expect($pint->successful())->toBeTrue($pint->output());

    $class = 'App\Infra\\'.SAMPLING_PORT_CONTEXT.'\SystemSamplingClock';

    expect(fn () => (new $class)->sleep())->toThrow(LogicException::class);
});

it('writes the adapter test at the path testing.md mirrors, with two todos per method', function () {
    writeSamplingPort('SamplingTicker', '    public function tick(): void;');

    runMakePort(['name' => 'SamplingTicker', '--domain' => SAMPLING_PORT_CONTEXT, '--adapter' => 'Cron', '--infra' => SAMPLING_PORT_CONTEXT])->assertSuccessful();

    $test = base_path('tests/Feature/Infra/'.SAMPLING_PORT_CONTEXT.'/CronSamplingTickerTest.php');

    expect(File::get($test))
        ->toContain('use App\Infra\\'.SAMPLING_PORT_CONTEXT.'\CronSamplingTicker;')
        ->toContain('$this->adapter = app(CronSamplingTicker::class);')
        ->toContain("it('does what SamplingTicker::tick() promises')->todo();")
        ->toContain("it('fails the way the port says it fails')->todo();");

    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', $test]);

    expect($pint->successful())->toBeTrue($pint->output());
});

it('writes an adapter that loads for a port with no method yet', function () {
    runMakePort(['name' => 'SamplingBlank', '--domain' => SAMPLING_PORT_CONTEXT, '--adapter' => 'Null', '--infra' => SAMPLING_PORT_CONTEXT])->assertSuccessful();

    expect(File::get(app_path('Infra/'.SAMPLING_PORT_CONTEXT.'/NullSamplingBlank.php')))
        ->toContain('class NullSamplingBlank implements SamplingBlank')
        ->not->toContain('LogicException')
        ->and(File::get(base_path('tests/Feature/Infra/'.SAMPLING_PORT_CONTEXT.'/NullSamplingBlankTest.php')))
        ->toContain("it('implements every method of SamplingBlank')->todo();");
});

it('never overwrites a port, an adapter or a test that already exists', function () {
    writeSamplingPort('SamplingKept', '    // hand written');
    File::ensureDirectoryExists(app_path('Infra/'.SAMPLING_PORT_CONTEXT));
    File::put(app_path('Infra/'.SAMPLING_PORT_CONTEXT.'/NullSamplingKept.php'), 'hand written');
    File::ensureDirectoryExists(base_path('tests/Feature/Infra/'.SAMPLING_PORT_CONTEXT));
    File::put(base_path('tests/Feature/Infra/'.SAMPLING_PORT_CONTEXT.'/NullSamplingKeptTest.php'), 'hand written');

    runMakePort(['name' => 'SamplingKept', '--domain' => SAMPLING_PORT_CONTEXT, '--adapter' => 'Null', '--infra' => SAMPLING_PORT_CONTEXT])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(app_path('Domain/'.SAMPLING_PORT_CONTEXT.'/Ports/SamplingKept.php')))->toContain('// hand written')
        ->and(File::get(app_path('Infra/'.SAMPLING_PORT_CONTEXT.'/NullSamplingKept.php')))->toBe('hand written')
        ->and(File::get(base_path('tests/Feature/Infra/'.SAMPLING_PORT_CONTEXT.'/NullSamplingKeptTest.php')))->toBe('hand written');
});
