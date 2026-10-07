<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * A stub name no project or kit file carries, so a published copy written here is this test's
 * alone.
 */
const SAMPLING_KIT_STUB = 'sampling-kit.stub';

afterEach(fn () => File::delete(base_path('stubs/'.SAMPLING_KIT_STUB)));

describe('WorkflowKit', function () {
    describe('stubPath', function () {
        it('reads the kit\'s own stub while the project publishes none', function () {
            expect(WorkflowKit::stubPath('use-case-handler.stub'))
                ->toBe(dirname(WorkflowKit::guidelinesPath(), 3).'/stubs/use-case-handler.stub')
                ->toBeFile();
        });

        it('reads the project\'s stub once it publishes one of the same name', function () {
            File::put(base_path('stubs/'.SAMPLING_KIT_STUB), 'published');

            expect(WorkflowKit::stubPath(SAMPLING_KIT_STUB))->toBe(base_path('stubs/'.SAMPLING_KIT_STUB));
        });
    });

    describe('guidelinesPath and skillsPath', function () {
        it('point at the folders Boost reads', function () {
            expect(WorkflowKit::guidelinesPath().'/layers.md')->toBeFile()
                ->and(WorkflowKit::skillsPath().'/list-page/SKILL.md')->toBeFile();
        });
    });
});
