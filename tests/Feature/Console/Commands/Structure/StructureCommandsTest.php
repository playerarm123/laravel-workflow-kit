<?php

use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureCommands;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch context of this file. It carries `Sampling`, so no Architecture check reads it, and
 * differs from every other test file's, so --parallel never deletes it mid-run.
 */
const SAMPLING_COMMANDS_CONTEXT = 'SamplingCommands';

/**
 * The commands, run through the package's own console, which boots the same app as this test.
 */
function samplingCommands(): StructureCommands
{
    $package = dirname(__DIR__, 5);

    return new StructureCommands(new StructureReader(base_path()), $package, [PHP_BINARY, $package.'/vendor/bin/testbench']);
}

function forgetSamplingCommands(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_COMMANDS_CONTEXT));
    File::delete(base_path('.kit/structure/'.SAMPLING_COMMANDS_CONTEXT.'.json'));
}

beforeEach(function () {
    forgetSamplingCommands();
    File::ensureDirectoryExists(app_path('Domain/'.SAMPLING_COMMANDS_CONTEXT));
    (new StructureFiles(base_path()))->write(['context' => SAMPLING_COMMANDS_CONTEXT]);
});

afterEach(fn () => forgetSamplingCommands());

describe('StructureCommands', function () {
    it('offers every kit command, and the route generator only when the project has Wayfinder', function () {
        $keys = array_column(samplingCommands()->available(), 'key');

        expect($keys)->toContain('plan', 'apply', 'sync-preview', 'sync', 'retire')
            ->and(in_array('routes', $keys, true))->toBe(InstalledVersions::isInstalled('laravel/wayfinder'))
            ->and(samplingCommands()->available()[0])->toBe(['key' => 'plan', 'label' => 'Plan', 'writes' => false, 'scopes' => ['context', 'resource']]);
    });

    it('describes a run as the command line a developer types', function () {
        $commands = samplingCommands();

        expect($commands->describe('apply', 'Shipping', null))->toBe('php artisan kit:apply --context=Shipping')
            ->and($commands->describe('sync-preview', null, 'Crate'))->toBe('php artisan kit:import --sync --dry-run --resource=Crate')
            ->and($commands->describe('plan', null, null))->toBe('php artisan kit:plan');
    });

    it('refuses a command it does not know, a scope the command does not take, and a name the code does not have', function (string $key, ?string $context, ?string $resource, string $refusal) {
        expect(samplingCommands()->refusal($key, $context, $resource))->toBe($refusal);
    })->with([
        'unknown command' => ['migrate', null, null, 'There is no command migrate to run here.'],
        'both scopes' => ['plan', SAMPLING_COMMANDS_CONTEXT, 'Crate', 'A command runs on one context or one HTTP resource, not both.'],
        'retire on a resource' => ['retire', null, 'Crate', 'kit:retire does not narrow to one HTTP resource.'],
        'unknown context' => ['plan', 'SamplingNowhere', null, 'There is no context named SamplingNowhere.'],
        'unknown resource' => ['apply', null, 'SamplingNowhere', 'There is no HTTP resource named SamplingNowhere.'],
    ]);

    it('lets a command run on a context the code has', function () {
        expect(samplingCommands()->refusal('plan', SAMPLING_COMMANDS_CONTEXT, null))->toBeNull();
    });

    it('runs a command in a console of its own and hands back what it printed, without colour', function () {
        $run = samplingCommands()->run('plan', SAMPLING_COMMANDS_CONTEXT, null);

        expect($run['command'])->toBe('php artisan kit:plan --context='.SAMPLING_COMMANDS_CONTEXT)
            ->and($run['exitCode'])->toBe(0)
            ->and($run['output'])->toContain('Done:')
            ->and($run['output'])->not->toContain("\e[");
    });

    it('previews a sync without writing the manifest', function () {
        $path = base_path('.kit/structure/'.SAMPLING_COMMANDS_CONTEXT.'.json');
        $before = File::get($path);

        $run = samplingCommands()->run('sync-preview', SAMPLING_COMMANDS_CONTEXT, null);

        expect($run['exitCode'])->toBe(0)
            ->and(File::get($path))->toBe($before);
    });
});
