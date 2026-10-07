<?php

use Illuminate\Contracts\Console\Kernel;
use Playerarm123\LaravelWorkflowKit\Console\Commands\KitApplyCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeControllerCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeEnumCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakePolicyCommand;
use Playerarm123\LaravelWorkflowKit\Console\Commands\MakeUseCaseCommand;

describe('WorkflowKitServiceProvider', function () {
    it('registers the kit\'s generators and structure commands', function () {
        $commands = app(Kernel::class)->all();

        expect($commands['make:use-case'])->toBeInstanceOf(MakeUseCaseCommand::class)
            ->and($commands['kit:apply'])->toBeInstanceOf(KitApplyCommand::class);
    });

    it('takes over the framework commands the kit replaces under their own names', function (string $name, string $class) {
        expect(app(Kernel::class)->all()[$name])->toBeInstanceOf($class);
    })->with([
        'make:controller' => ['make:controller', MakeControllerCommand::class],
        'make:enum' => ['make:enum', MakeEnumCommand::class],
        'make:policy' => ['make:policy', MakePolicyCommand::class],
    ]);
});
