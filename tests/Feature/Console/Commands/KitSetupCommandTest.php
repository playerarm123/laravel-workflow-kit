<?php

use Illuminate\Support\Facades\File;

/**
 * kit:setup edits the project it runs on, so the suite proves its steps on a copy of the starter
 * kit (Setup/ProjectSetupTest) and only the guard that stops it before any edit here.
 */
describe('kit:setup', function () {
    it('refuses a database the stack does not support before it touches anything', function () {
        $this->artisan('kit:setup', ['--database' => 'sqlite'])
            ->expectsOutputToContain('The database must be one of pgsql, mysql, mariadb, not [sqlite].')
            ->assertFailed();
    });

    it('refuses a project made from the starter kit\'s teams variant before it touches anything', function () {
        $project = storage_path('framework/testing/kit-setup-teams-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($project.'/app/Concerns');
        file_put_contents($project.'/app/Concerns/HasTeams.php', "<?php\n");
        $this->app->setBasePath($project);

        try {
            $this->artisan('kit:setup', ['--database' => 'pgsql'])
                ->expectsOutputToContain('teams variant (app/Concerns/HasTeams.php)')
                ->assertFailed();

            expect(File::allFiles($project))->toHaveCount(1);
        } finally {
            File::deleteDirectory($project);
        }
    });
});
