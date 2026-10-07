<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitInstaller;

/**
 * A kit and a project of this file's own, under the system's temp folder, so nothing here
 * touches the project the suite runs on.
 *
 * @return array{kit: string, project: string}
 */
function kitInstallerRoots(): array
{
    $root = sys_get_temp_dir().'/sampling-kit-installer-'.getmypid();

    return ['kit' => $root.'/kit', 'project' => $root.'/project'];
}

beforeEach(function () {
    ['kit' => $kit, 'project' => $project] = kitInstallerRoots();

    File::ensureDirectoryExists($project);
    File::ensureDirectoryExists($kit.'/files/app/Http');
    File::ensureDirectoryExists($kit.'/files/database/migrations');
    File::ensureDirectoryExists($kit.'/scaffold/app/Auth');
    File::put($kit.'/files/app/Http/Toast.php', "<?php // kit\n");
    File::put($kit.'/files/database/migrations/2026_01_01_000000_create_entries_table.php', "<?php // kit migration\n");
    File::put($kit.'/scaffold/app/Auth/Actor.php', "<?php // scaffold\n");
});

afterEach(fn () => File::deleteDirectory(dirname(kitInstallerRoots()['kit'])));

describe('KitInstaller', function () {
    describe('install', function () {
        it('writes every missing kit file and scaffold at its path in the project', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();

            $report = (new KitInstaller($kit, $project))->install();

            expect($report['written'])->toBe([
                'app/Http/Toast.php',
                'database/migrations/2026_01_01_000000_create_entries_table.php',
                'app/Auth/Actor.php',
            ])
                ->and(File::get($project.'/app/Http/Toast.php'))->toBe("<?php // kit\n")
                ->and(File::get($project.'/app/Auth/Actor.php'))->toBe("<?php // scaffold\n");
        });

        it('reports a file that already reads as the kit\'s copy as unchanged', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            $installer = new KitInstaller($kit, $project);
            $installer->install();

            $report = $installer->install();

            expect($report['written'])->toBe([])
                ->and($report['unchanged'])->toHaveCount(2)
                ->and($report['kept'])->toBe(['app/Auth/Actor.php']);
        });

        it('leaves a kit file that differs alone until --force asks to overwrite it', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            File::ensureDirectoryExists($project.'/app/Http');
            File::put($project.'/app/Http/Toast.php', "<?php // changed\n");
            $installer = new KitInstaller($kit, $project);

            expect($installer->install()['differs'])->toBe(['app/Http/Toast.php'])
                ->and(File::get($project.'/app/Http/Toast.php'))->toBe("<?php // changed\n")
                ->and($installer->install(force: true)['overwritten'])->toBe(['app/Http/Toast.php'])
                ->and(File::get($project.'/app/Http/Toast.php'))->toBe("<?php // kit\n");
        });

        it('never overwrites a scaffold the project already owns, even with --force', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            File::ensureDirectoryExists($project.'/app/Auth');
            File::put($project.'/app/Auth/Actor.php', "<?php // the project's own\n");

            $report = (new KitInstaller($kit, $project))->install(force: true);

            expect($report['kept'])->toBe(['app/Auth/Actor.php'])
                ->and(File::get($project.'/app/Auth/Actor.php'))->toBe("<?php // the project's own\n");
        });

        it('matches a migration by its name, so one under another timestamp is never written twice', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            File::ensureDirectoryExists($project.'/database/migrations');
            File::put($project.'/database/migrations/2025_05_05_101010_create_entries_table.php', "<?php // kit migration\n");

            $report = (new KitInstaller($kit, $project))->install();

            expect($report['unchanged'])->toContain('database/migrations/2025_05_05_101010_create_entries_table.php')
                ->and(File::files($project.'/database/migrations'))->toHaveCount(1);
        });
    });
});
