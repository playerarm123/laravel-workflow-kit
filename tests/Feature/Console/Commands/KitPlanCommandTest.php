<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;

/**
 * The scratch context of this file, designed in a manifest and not built at all. It carries
 * `Sampling`, so no Architecture check reads it, and differs from every other test file's.
 */
const SAMPLING_SURVEY_CONTEXT = 'SamplingSurvey';

function samplingSurveyMarkersRoot(): string
{
    return storage_path('framework/testing/sampling-survey');
}

function forgetSamplingSurvey(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_SURVEY_CONTEXT));
    File::deleteDirectory(samplingSurveyMarkersRoot());
    File::delete(base_path('.kit/structure/'.SAMPLING_SURVEY_CONTEXT.'.json'));
}

beforeEach(function () {
    forgetSamplingSurvey();

    (new StructureFiles(base_path()))->write([
        'context' => SAMPLING_SURVEY_CONTEXT,
        'aggregates' => ['Plot' => ['children' => ['Peg'], 'repository' => false]],
        'services' => [],
        'ports' => [],
        'useCases' => [],
    ]);
    app()->instance(StructureMarkers::class, new StructureMarkers(samplingSurveyMarkersRoot()));
});

afterEach(fn () => forgetSamplingSurvey());

it('shows the steps that are ready and the ones that wait, and writes nothing', function () {
    $this->artisan('kit:plan', ['--context' => [SAMPLING_SURVEY_CONTEXT]])
        ->expectsOutputToContain('Ready to run (1)')
        ->expectsOutputToContain('php artisan make:entity Plot --domain='.SAMPLING_SURVEY_CONTEXT.'/Plot')
        ->expectsOutputToContain('Waiting (1)')
        ->expectsOutputToContain('the root PlotEntity comes first')
        ->expectsOutputToContain('Done: 0 of 2 steps.')
        ->doesntExpectOutputToContain('By hand')
        ->assertSuccessful();

    expect(File::exists(app_path('Domain/'.SAMPLING_SURVEY_CONTEXT)))->toBeFalse();
});

it('fails on a name no manifest gives', function () {
    $this->artisan('kit:plan', ['--context' => ['SamplingNowhere']])
        ->expectsOutputToContain('No manifest names the context or HTTP resource SamplingNowhere.')
        ->assertFailed();
});
