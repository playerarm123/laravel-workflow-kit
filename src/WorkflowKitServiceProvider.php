<?php

namespace Playerarm123\LaravelWorkflowKit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\EnumMakeCommand;
use Illuminate\Foundation\Console\PolicyMakeCommand;
use Illuminate\Routing\Console\ControllerMakeCommand;
use Illuminate\Support\ServiceProvider;
use Playerarm123\LaravelWorkflowKit\Console\Commands\KitApplyCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\KitImportCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\KitInstallCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\KitPlanCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\KitRetireCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeActionCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeControllerCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeDomainExceptionCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeDomainServiceCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeEloquentRepositoryCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeEntityCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeEntityMethodCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeEnumCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeFormPageCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeFormRequestCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeListPageCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakePolicyCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakePortCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeUseCaseCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeValueObjectCommand;

/**
 * Registers the kit's generators (`make:*`), its structure commands and `kit:install` (`kit:*`).
 *
 * Three of them take over a framework command under the same name (`make:controller`,
 * `make:enum`, `make:policy`). The framework registers those in a deferred provider that loads
 * after this one, so registering ours by name would lose; the kit extends the framework's
 * binding instead, and Artisan gets the kit's command whenever it asks for the framework's.
 */
final class WorkflowKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(ControllerMakeCommand::class, fn (ControllerMakeCommand $command, Application $app): MakeControllerCommand => new MakeControllerCommand($app->make('files')));
        $this->app->extend(EnumMakeCommand::class, fn (EnumMakeCommand $command, Application $app): MakeEnumCommand => new MakeEnumCommand($app->make('files')));
        $this->app->extend(PolicyMakeCommand::class, fn (PolicyMakeCommand $command, Application $app): MakePolicyCommand => new MakePolicyCommand($app->make('files')));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            KitApplyCommand::class,
            KitImportCommand::class,
            KitInstallCommand::class,
            KitPlanCommand::class,
            KitRetireCommand::class,
            MakeActionCommand::class,
            MakeDomainExceptionCommand::class,
            MakeDomainServiceCommand::class,
            MakeEloquentRepositoryCommand::class,
            MakeEntityCommand::class,
            MakeEntityMethodCommand::class,
            MakeFormPageCommand::class,
            MakeFormRequestCommand::class,
            MakeListPageCommand::class,
            MakePortCommand::class,
            MakeUseCaseCommand::class,
            MakeValueObjectCommand::class,
        ]);
    }
}
