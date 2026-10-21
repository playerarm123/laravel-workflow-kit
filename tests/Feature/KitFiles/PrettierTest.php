<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * The kit's TypeScript and CSS land in a project beside the .prettierrc kit:setup writes, and
 * [kit-files] keeps the project from reformatting them, so `prettier --check resources/` must
 * pass on them as the kit ships them. The order of the classes depends on the Tailwind
 * stylesheet, so the run reads the starter kit's own app.css.
 */
it('ships every file under resources/ in the shape the project\'s Prettier checks for', function () {
    $workbench = dirname(__DIR__, 3).'/workbench';

    if (! is_file($workbench.'/node_modules/.bin/prettier')) {
        $this->markTestSkipped('Run `npm ci` in workbench/ first.');
    }

    $project = storage_path('framework/testing/kit-prettier-'.bin2hex(random_bytes(4)));

    try {
        // A setup file is written only where the project has none, so a kit file of the same path wins.
        File::copyDirectory(WorkflowKit::kitPath().'/setup/resources', $project.'/resources');
        File::copyDirectory(WorkflowKit::kitPath().'/files/resources', $project.'/resources');
        File::copy(WorkflowKit::kitPath().'/setup/.prettierrc', $project.'/.prettierrc');
        File::copy(WorkflowKit::kitPath().'/setup/.prettierignore', $project.'/.prettierignore');
        File::copyDirectory(dirname(__DIR__, 2).'/Fixtures/StarterKit/resources/css', $project.'/resources/css');
        symlink($workbench.'/node_modules', $project.'/node_modules');

        $result = Process::path($project)->run(['node_modules/.bin/prettier', '--check', 'resources/']);

        expect($result->output().$result->errorOutput())->toContain('All matched files use Prettier code style!');
    } finally {
        File::deleteDirectory($project);
    }
});
