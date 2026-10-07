<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The scratch policies of this file. Their names must differ from every other test file's, or
 * --parallel lets them delete each other's fixtures.
 */
const SAMPLING_POLICIES = ['SamplingParcelPolicy', 'SamplingUserPolicy'];

function samplingPolicyPath(string $policy): string
{
    return app_path('Policies/'.$policy.'.php');
}

function samplingPolicyTestPath(string $policy): string
{
    return base_path('tests/Feature/Policies/'.$policy.'Test.php');
}

function forgetSamplingPolicies(): void
{
    foreach (SAMPLING_POLICIES as $policy) {
        File::delete([samplingPolicyPath($policy), samplingPolicyTestPath($policy)]);
    }
}

beforeEach(fn () => forgetSamplingPolicies());

afterEach(fn () => forgetSamplingPolicies());

it('writes the policy of the model its name says, with the five abilities denying', function () {
    $this->artisan('make:policy', ['name' => 'SamplingParcel'])->assertSuccessful();

    expect(File::get(samplingPolicyPath('SamplingParcelPolicy')))
        ->toContain('use App\Models\SamplingParcel;')
        ->toContain('class SamplingParcelPolicy')
        ->toContain('public function viewAny(User $user): bool')
        ->toContain('public function view(User $user, SamplingParcel $samplingParcel): bool')
        ->toContain('public function create(User $user): bool')
        ->toContain('public function update(User $user, SamplingParcel $samplingParcel): bool')
        ->toContain('public function delete(User $user, SamplingParcel $samplingParcel): bool')
        ->not->toContain('restore')
        ->not->toContain('forceDelete')
        ->not->toContain('{{');
});

it('reads the same policy from the model name and the policy name', function () {
    $this->artisan('make:policy', ['name' => 'SamplingParcelPolicy'])->assertSuccessful();

    expect(File::exists(samplingPolicyPath('SamplingParcelPolicy')))->toBeTrue()
        ->and(File::exists(samplingPolicyPath('SamplingParcelPolicyPolicy')))->toBeFalse();
});

it('writes the policy test with a todo for each ability allowed and denied', function () {
    $this->artisan('make:policy', ['name' => 'SamplingParcel'])->assertSuccessful();

    expect(File::get(samplingPolicyTestPath('SamplingParcelPolicy')))
        ->toContain('Each ability of SamplingParcelPolicy')
        ->toContain("allows('update', \$samplingParcel)")
        ->toContain("describe('delete'")
        ->toContain("it('denies everyone else')->todo();")
        ->not->toContain('{{');
});

it('keeps a policy test that already exists', function () {
    File::ensureDirectoryExists(dirname(samplingPolicyTestPath('SamplingParcelPolicy')));
    File::put(samplingPolicyTestPath('SamplingParcelPolicy'), '<?php // hand written');

    $this->artisan('make:policy', ['name' => 'SamplingParcel'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(samplingPolicyTestPath('SamplingParcelPolicy')))->toBe('<?php // hand written');
});

it('reminds to create a model that does not exist yet', function () {
    $this->artisan('make:policy', ['name' => 'SamplingParcel'])
        ->expectsOutputToContain('Model [App\Models\SamplingParcel] does not exist')
        ->assertSuccessful();
});

it('names the attribute that attaches the policy to an existing model', function () {
    $this->artisan('make:policy', ['name' => 'SamplingUser', '--model' => 'User'])
        ->expectsOutputToContain('add #[UsePolicy(\App\Policies\SamplingUserPolicy::class)] to App\Models\User')
        ->assertSuccessful();

    expect(File::get(samplingPolicyPath('SamplingUserPolicy')))
        ->toContain('public function view(User $user, User $model): bool');
});

it('writes PHP that pint leaves as it is', function () {
    $this->artisan('make:policy', ['name' => 'SamplingParcel'])->assertSuccessful();

    $pint = Process::path(base_path())->run([
        base_path('vendor/bin/pint'),
        '--test',
        samplingPolicyPath('SamplingParcelPolicy'),
        samplingPolicyTestPath('SamplingParcelPolicy'),
    ]);

    expect($pint->successful())->toBeTrue($pint->output());
});
