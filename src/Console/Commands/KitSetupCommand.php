<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\ProjectSetup;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * Brings a project made from Laravel's React starter kit to the shape the kit's checks hold it
 * to, in one run: the stack's packages, the kit's files, the config, the wiring, the users table
 * on uuids and the audit log page. It changes only what is not in that shape yet, so it can run
 * again at any time, and it ends with what is left to a person.
 */
#[Signature('kit:setup
    {--database= : The engine every environment and the tests run on: pgsql, mysql or mariadb}
    {--skip-dependencies : Edit composer.json and package.json, but run no install}')]
#[Description('Set up a new project from Laravel\'s React starter kit the way the workflow kit\'s checks want it')]
class KitSetupCommand extends Command
{
    public function handle(): int
    {
        $database = $this->database();

        if ($database === null) {
            return self::FAILURE;
        }

        $setup = new ProjectSetup(WorkflowKit::kitPath(), base_path(), $database);
        $manual = [];

        $composer = $this->step('Composer packages', fn () => $setup->composerDependencies(), $manual);
        $npm = $this->step('JavaScript packages', fn () => $setup->npmDependencies(), $manual);

        if (! $this->option('skip-dependencies')) {
            if ($composer['packages'] !== [] && ! $this->runProcess(['composer', 'update', ...$composer['packages'], '--with-all-dependencies', '--no-interaction'])) {
                return self::FAILURE;
            }

            if ($npm['packages'] !== [] && ! $this->runProcess([$setup->packageManager(), 'install'])) {
                return self::FAILURE;
            }
        }

        $this->step('Kit files', fn () => $setup->files(), $manual);
        $this->step('Config', fn () => $setup->config(), $manual);
        $this->step('Wiring', fn () => $setup->wiring(), $manual);
        $this->step('Users on uuids', fn () => $setup->users(), $manual);
        $this->step('Audit log page', fn () => $setup->auditPage(), $manual);

        if (! $this->option('skip-dependencies')) {
            $this->runProcess([PHP_BINARY, 'artisan', 'wayfinder:generate', '--with-form', '--no-interaction']);
            $this->runProcess([$setup->packageManager(), 'run', 'lint']);
        }

        $this->call('kit:import');

        if ($manual !== []) {
            $this->components->warn('Left to do by hand:');
            $this->components->bulletList($manual);
        }

        $this->components->info('Next:');
        $this->components->bulletList([
            sprintf('Create the %s database your .env names, then run `php artisan migrate:fresh`', $database),
            'Run `php artisan boost:install` and add "playerarm123/laravel-workflow-kit" to "packages" in boost.json',
            'Run `npx playwright install chromium` for the Browser tests',
            'Run `php artisan test --testsuite=Architecture`',
        ]);

        return self::SUCCESS;
    }

    private function database(): ?string
    {
        $database = $this->option('database');

        if (! is_string($database) || $database === '') {
            $database = $this->components->choice('Which database do every environment and the tests run on?', ProjectSetup::DATABASES, 'pgsql');
        }

        if (! in_array($database, ProjectSetup::DATABASES, true)) {
            $this->components->error(sprintf('The database must be one of %s, not [%s].', implode(', ', ProjectSetup::DATABASES), $database));

            return null;
        }

        return $database;
    }

    /**
     * @template TResult of array{changed: list<string>, manual: list<string>}
     *
     * @param  callable(): TResult  $step
     * @param  list<string>  $manual
     * @return TResult
     */
    private function step(string $label, callable $step, array &$manual): array
    {
        $result = $step();

        $this->components->twoColumnDetail($label, $result['changed'] === [] ? '<fg=gray>already done</>' : sprintf('<fg=green>%d changed</>', count($result['changed'])));

        foreach ($result['changed'] as $path) {
            $this->line(sprintf('    <fg=gray>%s</>', $path), verbosity: 'v');
        }

        array_push($manual, ...$result['manual']);

        /** @var TResult $result */
        return $result;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): bool
    {
        $this->components->info('Running '.implode(' ', $command));

        $result = Process::path(base_path())
            ->forever()
            ->run($command, function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($result->failed()) {
            $this->components->error(sprintf('[%s] failed. Fix what it says, then run kit:setup again.', implode(' ', $command)));
        }

        return $result->successful();
    }
}
