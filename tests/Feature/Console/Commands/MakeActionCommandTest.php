<?php

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

/**
 * The scratch context and model of this file. Both must differ from every other test file's,
 * or --parallel lets them delete each other's fixtures.
 */
const ACTION_CONTEXT = 'SamplingDispatching';

const ACTION_MODEL = 'SamplingBox';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeAction(string $verb, array $arguments = []): PendingCommand
{
    return test()->artisan('make:action', ['verb' => $verb, '--model' => ACTION_MODEL, '--domain' => ACTION_CONTEXT, ...$arguments]);
}

/**
 * @return array{controller: string, request: string, test: string}
 */
function actionGenerated(string $stem): array
{
    return [
        'controller' => app_path("Http/Controllers/{$stem}Controller.php"),
        'request' => app_path('Http/Requests/'.ACTION_MODEL."/{$stem}Request.php"),
        'test' => base_path("tests/Feature/Http/Controllers/{$stem}ControllerTest.php"),
    ];
}

/**
 * Two filled-in use cases: CancelSamplingBox counts what it changed over `ids`, as a row action
 * with a bulk twin does, and HoldSamplingBox acts on one row and returns nothing.
 */
function writeActionFixtures(): void
{
    $namespace = 'App\\Application\\'.ACTION_CONTEXT.'\\UseCases';

    $fixtures = [
        'CancelSamplingBox/CancelSamplingBoxCommand' => <<<PHP
<?php

namespace {$namespace}\\CancelSamplingBox;

use Spatie\\LaravelData\\Data;

final class CancelSamplingBoxCommand extends Data
{
    /**
     * @param  list<string>  \$ids
     */
    public function __construct(
        public readonly array \$ids,
        public readonly string \$reason,
    ) {}
}
PHP,
        'CancelSamplingBox/CancelSamplingBoxHandler' => <<<PHP
<?php

namespace {$namespace}\\CancelSamplingBox;

final class CancelSamplingBoxHandler
{
    public function __invoke(CancelSamplingBoxCommand \$command): int
    {
        return count(\$command->ids);
    }
}
PHP,
        'HoldSamplingBox/HoldSamplingBoxCommand' => <<<PHP
<?php

namespace {$namespace}\\HoldSamplingBox;

use Spatie\\LaravelData\\Data;

final class HoldSamplingBoxCommand extends Data
{
    public function __construct(
        public readonly string \$samplingBoxId,
        public readonly ?string \$note,
    ) {}
}
PHP,
        'HoldSamplingBox/HoldSamplingBoxHandler' => <<<PHP
<?php

namespace {$namespace}\\HoldSamplingBox;

final class HoldSamplingBoxHandler
{
    public function __invoke(HoldSamplingBoxCommand \$command): void {}
}
PHP,
    ];

    foreach ($fixtures as $relative => $contents) {
        $path = app_path('Application/'.ACTION_CONTEXT."/UseCases/{$relative}.php");

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }
}

function cleanActionScratch(): void
{
    File::deleteDirectory(app_path('Application/'.ACTION_CONTEXT));
    File::deleteDirectory(app_path('Http/Requests/'.ACTION_MODEL));

    foreach (['Cancel', 'BulkCancel', 'Hold'] as $verb) {
        File::delete([
            actionGenerated(ACTION_MODEL.$verb)['controller'],
            actionGenerated(ACTION_MODEL.$verb)['test'],
        ]);
    }
}

beforeEach(function () {
    cleanActionScratch();
    writeActionFixtures();
});

afterEach(function () {
    cleanActionScratch();
});

it('fails without the model the action acts on', function () {
    $this->artisan('make:action', ['verb' => 'Cancel', '--domain' => ACTION_CONTEXT])
        ->expectsOutputToContain('Pass --model')
        ->assertFailed();
});

it('fails and points at make:use-case when the Command does not exist', function () {
    runMakeAction('Ship')
        ->expectsOutputToContain('make:use-case ShipSamplingBox --domain='.ACTION_CONTEXT.' --command')
        ->assertFailed();

    expect(File::exists(actionGenerated(ACTION_MODEL.'Ship')['controller']))->toBeFalse();
});

it('writes a row action that hands its handler the Command and answers back()', function () {
    runMakeAction('Hold')
        ->expectsOutputToContain("Route::post('sampling-boxes/{sampling_box}/hold', SamplingBoxHoldController::class)->name('sampling-boxes.hold');")
        ->expectsOutputToContain('has no hold()')
        ->assertSuccessful();

    $files = actionGenerated('SamplingBoxHold');

    expect(File::get($files['controller']))
        ->toContain('class SamplingBoxHoldController extends Controller')
        ->toContain('SamplingBoxHoldRequest $request,')
        ->toContain('SamplingBox $samplingBox,')
        ->toContain('HoldSamplingBoxHandler $holdSamplingBox,')
        ->toContain('$holdSamplingBox($request->toCommand());')
        ->toContain("FlashToast::success(__('sampling-boxes.hold_succeeded'))")
        ->toContain('->back();')
        ->not->toContain('=== 0')
        ->not->toContain('{{');

    expect(File::get($files['request']))
        ->toContain('namespace App\Http\Requests\SamplingBox;')
        ->toContain('class SamplingBoxHoldRequest extends FormRequest')
        ->toContain("return Gate::allows('hold', \$this->samplingBox());")
        ->toContain("'note' => ['nullable', 'string', 'max:255'],")
        ->toContain('public function toCommand(): HoldSamplingBoxCommand')
        ->toContain('samplingBoxId: $this->samplingBox()->id,')
        ->toContain("note: \$this->filled('note') ? \$this->string('note')->toString() : null,")
        ->toContain("\$samplingBox = \$this->route('sampling_box');")
        ->not->toContain('{{');
});

it('answers a row action whose handler counts with an error toast for nothing changed', function () {
    runMakeAction('Cancel')
        ->expectsOutputToContain("Route::post('sampling-boxes/{sampling_box}/cancel', SamplingBoxCancelController::class)->name('sampling-boxes.cancel');")
        ->assertSuccessful();

    $files = actionGenerated('SamplingBoxCancel');

    expect(File::get($files['controller']))
        ->toContain('if ($cancelSamplingBox($request->toCommand()) === 0) {')
        ->toContain("FlashToast::error(__('sampling-boxes.cancel_unchanged'))")
        ->toContain("FlashToast::success(__('sampling-boxes.cancel_succeeded'))");

    expect(File::get($files['request']))
        ->toContain('ids: [$this->samplingBox()->id],')
        ->toContain("'reason' => ['required', 'string', 'max:255'],")
        ->not->toContain("'ids'");

    expect(File::get($files['test']))
        ->toContain("it('answers with an error toast when the row had already changed')->todo();")
        ->toContain("it('rejects each invalid field')->todo();");
});

it('writes the bulk twin against the class, over the selected ids, with three toasts', function () {
    runMakeAction('Cancel', ['--bulk' => true])
        ->expectsOutputToContain("Route::post('sampling-boxes/cancel', SamplingBoxBulkCancelController::class)->name('sampling-boxes.bulk-cancel');")
        ->expectsOutputToContain('has no cancelAny()')
        ->assertSuccessful();

    $files = actionGenerated('SamplingBoxBulkCancel');

    expect(File::get($files['controller']))
        ->toContain('class SamplingBoxBulkCancelController extends Controller')
        ->toContain('CancelSamplingBoxHandler $cancelSamplingBox,')
        ->toContain('$skipped = count($command->ids) - $changed;')
        ->toContain("'sampling-boxes.bulk_cancel_unchanged'")
        ->toContain("'sampling-boxes.bulk_cancel_partial'")
        ->toContain("'sampling-boxes.bulk_cancel_succeeded'")
        ->not->toContain('SamplingBox $samplingBox');

    expect(File::get($files['request']))
        ->toContain("return Gate::allows('cancelAny', SamplingBox::class);")
        ->toContain("'ids' => ['required', 'array', 'min:1'],")
        ->toContain("'ids.*' => ['uuid', 'distinct', Rule::exists('sampling_boxes', 'id')],")
        ->toContain('ids: $this->ids(),')
        ->toContain('private function ids(): array')
        ->not->toContain('LogicException');

    expect(File::get($files['test']))
        ->toContain('POST sampling-boxes/cancel (sampling-boxes.bulk-cancel)')
        ->toContain("it('tells how many changed and how many were skipped')->todo();");
});

it('writes files that parse, load and keep to actions.md', function () {
    runMakeAction('Cancel')->assertSuccessful();
    runMakeAction('Cancel', ['--bulk' => true])->assertSuccessful();

    foreach (['SamplingBoxCancel', 'SamplingBoxBulkCancel'] as $stem) {
        foreach (actionGenerated($stem) as $path) {
            expect(Process::run(['php', '-l', $path])->successful())->toBeTrue();
        }

        require_once actionGenerated($stem)['request'];
        require_once actionGenerated($stem)['controller'];

        $controller = "App\\Http\\Controllers\\{$stem}Controller";
        $request = 'App\\Http\\Requests\\'.ACTION_MODEL."\\{$stem}Request";
        $public = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC));

        expect(array_values(array_diff($public, get_class_methods(Controller::class))))->toBe(['__invoke'])
            ->and((string) (new ReflectionMethod($controller, '__invoke'))->getReturnType())->toBe('Symfony\Component\HttpFoundation\RedirectResponse')
            ->and((new ReflectionMethod($request, 'toCommand'))->getDeclaringClass()->getName())->toBe($request);
    }
});

it('never overwrites a file that already exists', function () {
    $files = actionGenerated('SamplingBoxHold');

    File::ensureDirectoryExists(dirname($files['controller']));
    File::put($files['controller'], '<?php // kept');

    runMakeAction('Hold')
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get($files['controller']))->toBe('<?php // kept');
});
