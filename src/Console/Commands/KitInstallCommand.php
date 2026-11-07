<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitInstaller;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * Writes the kit's files into the project at their fixed paths (the guidelines' Kit files). A
 * file the project must keep as the kit ships it is written when it is missing and overwritten
 * only with `--force`; a file the kit writes once, such as the actor port, is never overwritten.
 * The `kit-files` checks fail for every file this would still write.
 *
 * `--with=` adds a feature the project chooses, such as the image library. The project records
 * it in `.kit/features.json`, so every later run keeps its files up to date too.
 */
#[Signature('kit:install
    {--force : Overwrite the kit files that differ from the kit\'s copy}
    {--with=* : Also install a feature the kit ships, such as media}')]
#[Description('Write the workflow kit\'s files into the project, as the guidelines\' Kit files describe')]
class KitInstallCommand extends Command
{
    /**
     * What a project wires by hand once, because no file of the kit can say it for the project.
     */
    private const array BY_HAND = [
        'Register App\Infra\Audit\AuditServiceProvider, App\Infra\Time\TimeServiceProvider and App\Providers\KitServiceProvider in bootstrap/providers.php',
        'Import the kit\'s ESLint rules from vendor/playerarm123/laravel-workflow-kit/tests/ESLint in eslint.config.js',
        'Include vendor/playerarm123/laravel-workflow-kit/tests/PHPStan/write-path.php from phpstan.neon',
        'Add the Architecture testsuite (vendor/playerarm123/laravel-workflow-kit/tests/Architecture) to phpunit.xml',
        'Run php artisan make:policy AuditEntry, keep viewAny, and route audit-entries.index',
        'Add auditLogReader() and auditLogOutsider() to tests/Pest.php, and the kit\'s keys to every lang/*.json',
    ];

    public function handle(): int
    {
        $installer = new KitInstaller(WorkflowKit::kitPath(), base_path());

        try {
            $report = $installer->install((bool) $this->option('force'), array_values(array_map(strval(...), (array) $this->option('with'))));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report['enabled'] as $feature) {
            $this->components->info(sprintf('Feature [%s] installed and recorded in %s.', $feature, KitInstaller::FEATURES_FILE));
        }

        foreach ($report['written'] as $path) {
            $this->components->info(sprintf('Kit file [%s] written.', $path));
        }

        foreach ($report['overwritten'] as $path) {
            $this->components->info(sprintf('Kit file [%s] overwritten with the kit\'s copy.', $path));
        }

        foreach ($report['differs'] as $path) {
            $this->components->warn(sprintf('Kit file [%s] differs from the kit\'s copy, kept as it is. Pass --force to overwrite it.', $path));
        }

        $this->components->twoColumnDetail('Unchanged', (string) count($report['unchanged']));
        $this->components->twoColumnDetail('Owned by the project, kept', (string) count($report['kept']));

        if (count($report['written']) > count($report['feature_written'])) {
            $this->components->bulletList(self::BY_HAND);
        }

        foreach ($report['translated'] as $file => $count) {
            $this->components->info(sprintf('Added %d keys to [%s] in English; translate them where it is not an English file.', $count, $file));
        }

        foreach ($report['enabled'] as $feature) {
            $byHand = $installer->byHandOf($feature);

            if ($byHand !== []) {
                $this->components->twoColumnDetail(sprintf('Wire the %s feature by hand', $feature));
                $this->components->bulletList($byHand);
            }
        }

        return self::SUCCESS;
    }
}
