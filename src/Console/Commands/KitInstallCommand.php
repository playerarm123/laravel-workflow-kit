<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitInstaller;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * Writes the kit's files into the project at their fixed paths (the guidelines' Kit files). A
 * file the project must keep as the kit ships it is written when it is missing and overwritten
 * only with `--force`; a file the kit writes once, such as the actor port, is never overwritten.
 * The `kit-files` checks fail for every file this would still write.
 */
#[Signature('kit:install {--force : Overwrite the kit files that differ from the kit\'s copy}')]
#[Description('Write the workflow kit\'s files into the project, as the guidelines\' Kit files describe')]
class KitInstallCommand extends Command
{
    /**
     * What a project wires by hand once, because no file of the kit can say it for the project.
     */
    private const array BY_HAND = [
        'Register App\Infra\Audit\AuditServiceProvider and App\Providers\KitServiceProvider in bootstrap/providers.php',
        'Import the kit\'s ESLint rules from vendor/playerarm123/laravel-workflow-kit/tests/ESLint in eslint.config.js',
        'Include vendor/playerarm123/laravel-workflow-kit/tests/PHPStan/write-path.php from phpstan.neon',
        'Add the Architecture testsuite (vendor/playerarm123/laravel-workflow-kit/tests/Architecture) to phpunit.xml',
        'Run php artisan make:policy AuditEntry, keep viewAny, and route audit-entries.index',
        'Add auditLogReader() and auditLogOutsider() to tests/Pest.php, and the kit\'s keys to every lang/*.json',
    ];

    public function handle(): int
    {
        $report = (new KitInstaller(WorkflowKit::kitPath(), base_path()))->install((bool) $this->option('force'));

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

        if ($report['written'] !== []) {
            $this->components->bulletList(self::BY_HAND);
        }

        return self::SUCCESS;
    }
}
