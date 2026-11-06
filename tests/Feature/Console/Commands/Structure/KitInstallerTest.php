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
    File::ensureDirectoryExists($kit.'/features/gallery/files/app/Gallery');
    File::ensureDirectoryExists($kit.'/features/gallery/scaffold/config');
    File::put($kit.'/features/gallery/files/app/Gallery/Picture.php', "<?php // gallery\n");
    File::put($kit.'/features/gallery/scaffold/config/gallery.php', "<?php // gallery config\n");
    File::put($kit.'/features/gallery/feature.json', json_encode(['by_hand' => ['Register GalleryServiceProvider']]));
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

    describe('features', function () {
        it('installs no feature the project did not ask for', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();

            $report = (new KitInstaller($kit, $project))->install();

            expect($report['enabled'])->toBe([])
                ->and(File::exists($project.'/app/Gallery/Picture.php'))->toBeFalse()
                ->and(File::exists($project.'/.kit/features.json'))->toBeFalse();
        });

        it('writes a feature\'s files and scaffold when --with names it, and records it', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();

            $report = (new KitInstaller($kit, $project))->install(with: ['gallery']);

            expect($report['enabled'])->toBe(['gallery'])
                ->and($report['written'])->toContain('app/Gallery/Picture.php', 'config/gallery.php')
                ->and(json_decode(File::get($project.'/.kit/features.json'), true))->toBe(['gallery']);
        });

        it('keeps a recorded feature up to date on every later install', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            $installer = new KitInstaller($kit, $project);
            $installer->install(with: ['gallery']);
            File::put($kit.'/features/gallery/files/app/Gallery/Picture.php', "<?php // gallery v2\n");

            $report = $installer->install(force: true);

            expect($report['enabled'])->toBe([])
                ->and($report['overwritten'])->toBe(['app/Gallery/Picture.php'])
                ->and($report['kept'])->toContain('config/gallery.php')
                ->and(File::get($project.'/app/Gallery/Picture.php'))->toBe("<?php // gallery v2\n");
        });

        it('reports a feature asked for again as nothing newly enabled', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            $installer = new KitInstaller($kit, $project);
            $installer->install(with: ['gallery']);

            expect($installer->install(with: ['gallery'])['enabled'])->toBe([])
                ->and($installer->enabledFeatures())->toBe(['gallery']);
        });

        it('refuses a feature the kit does not ship, before it writes anything', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();

            expect(fn () => (new KitInstaller($kit, $project))->install(with: ['videos']))
                ->toThrow(InvalidArgumentException::class, 'The kit has no feature [videos]. It ships: gallery.')
                ->and(File::exists($project.'/app/Http/Toast.php'))->toBeFalse();
        });

        it('forgets a recorded feature the kit no longer ships', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            File::ensureDirectoryExists($project.'/.kit');
            File::put($project.'/.kit/features.json', json_encode(['gallery', 'retired']));

            expect((new KitInstaller($kit, $project))->enabledFeatures())->toBe(['gallery']);
        });

        it('counts a feature\'s files as the kit\'s own only once the project installed it', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            $installer = new KitInstaller($kit, $project);

            expect($installer->ownedPaths())->not->toContain('app/Gallery/Picture.php');

            $installer->install(with: ['gallery']);

            expect($installer->ownedPaths())->toContain('app/Gallery/Picture.php')
                ->and($installer->ownedPaths())->not->toContain('config/gallery.php');
        });

        it('reads what a feature asks the project to wire by hand', function () {
            ['kit' => $kit, 'project' => $project] = kitInstallerRoots();
            $installer = new KitInstaller($kit, $project);

            expect($installer->features())->toBe(['gallery'])
                ->and($installer->byHandOf('gallery'))->toBe(['Register GalleryServiceProvider']);
        });
    });
});
