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
        $teams = ProjectSetup::teamsMarkerOf(base_path());

        if ($teams !== null) {
            $this->components->error(sprintf(
                'This project was made from the starter kit\'s teams variant (%s), which kit:setup does not set up: its migrations key team members on a bigint users.id the kit keys on a uuid, and its team code does not follow the kit\'s rules. Start from the starter kit without teams, and build the teams the project needs with the kit.',
                $teams,
            ));

            return self::FAILURE;
        }

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

        $kitFiles = $this->step('Kit files', fn () => $setup->files(), $manual);
        $config = $this->step('Config', fn () => $setup->config(), $manual);
        $wiring = $this->step('Wiring', fn () => $setup->wiring(), $manual);
        $users = $this->step('Users on uuids', fn () => $setup->users(), $manual);
        $auditPage = $this->step('Audit log page', fn () => $setup->auditPage(), $manual);

        /*
         * The steps keep each file's own layout, which is not always Pint's. The kit's own files
         * stay as the kit ships them ([kit-files]).
         */
        $edited = [...$config['changed'], ...$wiring['changed'], ...$users['changed'], ...$auditPage['changed']];
        $php = array_values(array_filter($edited, fn (string $path): bool => str_ends_with($path, '.php') && is_file(base_path($path))));

        if ($php !== [] && is_file(base_path('vendor/bin/pint'))) {
            $this->runProcess([PHP_BINARY, 'vendor/bin/pint', ...$php]);
        }

        $built = false;

        if (! $this->option('skip-dependencies')) {
            $this->runProcess([PHP_BINARY, 'artisan', 'wayfinder:generate', '--with-form', '--no-interaction']);
            $this->runProcess([$setup->packageManager(), 'run', 'lint']);

            // The kit's pages are new entries of the Vite manifest: a Feature test that renders
            // one fails with a 500 until the assets are built again.
            $built = $this->runProcess([$setup->packageManager(), 'run', 'build']);
        }

        $this->call('kit:import');

        if ($manual !== []) {
            $this->components->warn('Left to do by hand:');
            $this->components->bulletList($manual);
        }

        $this->components->info('Next:');
        $this->components->bulletList([
            ...($built || ($kitFiles['changed'] === [] && $setup->builtWithTheKitsPages()) ? [] : [sprintf('Run `%s run build`: the Feature tests render the kit\'s pages from the Vite manifest', $setup->packageManager())]),
            // A users table already migrated on a bigint id needs migrate:fresh after the step.
            ...($users['changed'] === [] && $this->migrated() ? [] : [sprintf('Create the %s database your .env names, then run `php artisan migrate:fresh`', $database)]),
            ...($setup->boostLoadsTheKit() ? [] : ['Run `php artisan boost:install` and add "playerarm123/laravel-workflow-kit" to "packages" in boost.json']),
            ...($this->chromiumInstalled() ? [] : ['Run `npx playwright install chromium` for the Browser tests']),
            'Run `php artisan test --testsuite=Architecture`',
        ]);

        return self::SUCCESS;
    }

    /**
     * Whether the database .env names, as it reads now, has run every migration. A fresh process
     * reads it: the run may have just moved .env to another engine.
     */
    private function migrated(): bool
    {
        $result = Process::path(base_path())->run([PHP_BINARY, 'artisan', 'migrate:status', '--no-ansi', '--no-interaction']);

        return $result->successful() && preg_match('/\bPending\b/', $result->output()) !== 1;
    }

    /**
     * Whether Playwright has the Chromium it launches for the Browser tests on this machine.
     */
    private function chromiumInstalled(): bool
    {
        $result = Process::path(base_path())->run(['node', '-e', "process.stdout.write(require('playwright').chromium.executablePath())"]);

        return $result->successful() && is_file(trim($result->output()));
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
