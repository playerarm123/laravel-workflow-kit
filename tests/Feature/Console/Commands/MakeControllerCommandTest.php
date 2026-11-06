<?php

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

/**
 * The scratch context and model of this file. Both must differ from every other test file's,
 * or --parallel lets them delete each other's fixtures.
 */
const CONTROLLER_CONTEXT = 'SamplingRacking';

const CONTROLLER_MODEL = 'SamplingCrate';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeController(array $arguments = []): PendingCommand
{
    return test()->artisan('make:controller', ['name' => CONTROLLER_MODEL, '--domain' => CONTROLLER_CONTEXT, ...$arguments]);
}

function controllerGenerated(): string
{
    return app_path('Http/Controllers/'.CONTROLLER_MODEL.'Controller.php');
}

function controllerTestGenerated(string $action): string
{
    return base_path('tests/Feature/Http/Controllers/'.CONTROLLER_MODEL."Controller/{$action}Test.php");
}

function indexRequestGenerated(): string
{
    return app_path('Http/Requests/'.CONTROLLER_MODEL.'/Index'.CONTROLLER_MODEL.'Request.php');
}

/**
 * The public methods of the generated controller, in the order the file declares them.
 *
 * @return list<string>
 */
function controllerMethodsInOrder(): array
{
    preg_match_all('/public function (\w+)\(/', File::get(controllerGenerated()), $matches);

    return $matches[1];
}

/**
 * Filled-in use cases for every method but the forms, and a policy that answers only the reads,
 * so the run warns about the rest.
 */
function writeControllerFixtures(): void
{
    $namespace = 'App\\Application\\'.CONTROLLER_CONTEXT.'\\UseCases';

    $fixtures = [
        'ListSamplingCrates/ListSamplingCratesCommand' => <<<PHP
<?php

namespace {$namespace}\\ListSamplingCrates;

use Spatie\\LaravelData\\Data;

final class ListSamplingCratesCommand extends Data
{
    public function __construct(
        public readonly string \$sort = '',
        public readonly string \$search = '',
        public readonly string \$countryCode = '',
        public readonly string \$perPage = '',
    ) {}
}
PHP,
        'ListSamplingCrates/ListSamplingCratesResult' => <<<PHP
<?php

namespace {$namespace}\\ListSamplingCrates;

use Illuminate\\Pagination\\LengthAwarePaginator;
use Spatie\\LaravelData\\Data;

final class ListSamplingCratesResult extends Data
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  \$crates
     * @param  array<string, string>  \$sort
     * @param  array<string, string|null>  \$filters
     */
    public function __construct(
        public readonly LengthAwarePaginator \$crates,
        public readonly array \$sort,
        public readonly array \$filters,
    ) {}
}
PHP,
        'ListSamplingCrates/ListSamplingCratesHandler' => <<<PHP
<?php

namespace {$namespace}\\ListSamplingCrates;

final class ListSamplingCratesHandler {}
PHP,
        'CreateSamplingCrate/CreateSamplingCrateHandler' => <<<PHP
<?php

namespace {$namespace}\\CreateSamplingCrate;

final class CreateSamplingCrateHandler
{
    public function __invoke(): string
    {
        return '';
    }
}
PHP,
        'UpdateSamplingCrate/UpdateSamplingCrateHandler' => <<<PHP
<?php

namespace {$namespace}\\UpdateSamplingCrate;

final class UpdateSamplingCrateHandler {}
PHP,
        'DeleteSamplingCrateHandler' => <<<PHP
<?php

namespace {$namespace};

final class DeleteSamplingCrateHandler {}
PHP,
    ];

    foreach ($fixtures as $relative => $contents) {
        $path = app_path('Application/'.CONTROLLER_CONTEXT."/UseCases/{$relative}.php");

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    File::put(app_path('Policies/'.CONTROLLER_MODEL.'Policy.php'), <<<'PHP'
<?php

namespace App\Policies;

class SamplingCratePolicy
{
    public function viewAny(): bool
    {
        return false;
    }

    public function view(): bool
    {
        return false;
    }
}
PHP);
}

function cleanControllerScratch(): void
{
    File::deleteDirectory(app_path('Application/'.CONTROLLER_CONTEXT));
    File::deleteDirectory(base_path('tests/Feature/Application/'.CONTROLLER_CONTEXT));
    cleanGeneratedListScratch();
    File::deleteDirectory(base_path('tests/Feature/Http/Controllers/'.CONTROLLER_MODEL.'Controller'));
    File::deleteDirectory(app_path('Http/Requests/'.CONTROLLER_MODEL));
    File::delete([controllerGenerated(), app_path('Policies/'.CONTROLLER_MODEL.'Policy.php')]);
}

/**
 * What the end-to-end index test writes for its own model, beside the fixtures' context.
 */
const GENERATED_LIST_MODEL = 'SamplingPallet';

function cleanGeneratedListScratch(): void
{
    $model = GENERATED_LIST_MODEL;

    File::deleteDirectory(app_path("Http/Requests/{$model}"));
    File::deleteDirectory(base_path("tests/Feature/Http/Controllers/{$model}Controller"));
    File::delete([
        app_path("Http/Controllers/{$model}Controller.php"),
        app_path("Infra/Persistence/Eloquent/Queries/EloquentList{$model}sQuery.php"),
        base_path("tests/Feature/Infra/Persistence/Eloquent/Queries/EloquentList{$model}sQueryTest.php"),
    ]);
}

beforeEach(function () {
    cleanControllerScratch();
    writeControllerFixtures();
});

afterEach(function () {
    cleanControllerScratch();
});

it('writes all seven methods in resource order, each read off its use case', function () {
    runMakeController()
        ->expectsOutputToContain("Route::resource('sampling-crates', SamplingCrateController::class);")
        ->assertSuccessful();

    expect(controllerMethodsInOrder())->toBe(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);

    expect(File::get(controllerGenerated()))
        ->toContain('namespace App\Http\Controllers;')
        ->toContain('class SamplingCrateController extends Controller')
        ->toContain('public function index(IndexSamplingCrateRequest $request, ListSamplingCratesHandler $listSamplingCrates): Response')
        ->toContain('$result = $listSamplingCrates($request->toCommand());')
        ->toContain('use App\Http\Requests\SamplingCrate\IndexSamplingCrateRequest;')
        ->not->toContain("Gate::authorize('viewAny'")
        ->not->toContain('new ListSamplingCratesCommand')
        ->not->toContain('use Illuminate\Http\Request;')
        ->toContain("'crates' => \$result->crates,")
        ->toContain("'create' => Gate::allows('create', SamplingCrate::class),")
        ->toContain("'update' => Gate::allows('updateAny', SamplingCrate::class),")
        ->toContain("'delete' => Gate::allows('deleteAny', SamplingCrate::class),")
        ->toContain("'defaults' => SamplingCrateFormValues::empty(),")
        ->toContain('$id = $createSamplingCrate($request->toCommand());')
        ->toContain("return to_route('sampling-crates.show', \$id);")
        ->toContain("'update' => Gate::allows('update', \$samplingCrate),")
        ->toContain("'defaults' => SamplingCrateFormValues::of(\$samplingCrate),")
        ->toContain('$updateSamplingCrate($request->toCommand());')
        ->toContain("return to_route('sampling-crates.show', \$samplingCrate);")
        ->toContain('$deleteSamplingCrate($samplingCrate->id);')
        ->toContain("FlashToast::success(__('sampling-crates.deleted'))")
        ->toContain('use App\Application\SamplingRacking\UseCases\DeleteSamplingCrateHandler;')
        ->toContain('use Symfony\Component\HttpFoundation\RedirectResponse;')
        ->not->toContain('{{');

    foreach (['Index', 'Create', 'Store', 'Show', 'Edit', 'Update', 'Destroy'] as $action) {
        expect(File::exists(controllerTestGenerated($action)))->toBeTrue();
    }

    expect(File::get(controllerTestGenerated('Update')))
        ->toContain('PUT sampling-crates/{sampling_crate} (sampling-crates.update), answered by SamplingCrateController::update().')
        ->toContain("it('redirects a guest to the login page')->todo();")
        ->toContain("it('rejects each invalid field')->todo();")
        ->toContain("it('answers 404 for an unknown id')->todo();");
});

it('writes the index request, which asks viewAny and builds the list Command from the raw query string', function () {
    runMakeController(['--only' => 'index'])
        ->expectsOutputToContain('Http/Requests/SamplingCrate/IndexSamplingCrateRequest.php')
        ->assertSuccessful();

    expect(File::get(indexRequestGenerated()))
        ->toContain('namespace App\Http\Requests\SamplingCrate;')
        ->toContain('class IndexSamplingCrateRequest extends FormRequest')
        ->toContain("return Gate::allows('viewAny', SamplingCrate::class);")
        ->toContain(<<<'PHP'
    public function toCommand(): ListSamplingCratesCommand
    {
        return new ListSamplingCratesCommand(
            sort: $this->string('sort')->toString(),
            search: $this->string('search')->toString(),
            countryCode: $this->string('country_code')->toString(),
            perPage: $this->string('per_page')->toString(),
        );
    }
PHP)
        ->toContain('use App\Application\SamplingRacking\UseCases\ListSamplingCrates\ListSamplingCratesCommand;')
        ->not->toContain('function rules(')
        ->not->toContain('{{');
});

it('writes an index request that hands every field of a generated list Command over raw', function () {
    $model = GENERATED_LIST_MODEL;

    test()->artisan('make:use-case', ['name' => "List{$model}s", '--domain' => CONTROLLER_CONTEXT, '--query' => true])
        ->assertSuccessful();

    test()->artisan('make:controller', ['name' => $model, '--domain' => CONTROLLER_CONTEXT, '--only' => 'index'])
        ->doesntExpectOutputToContain('should carry its rows, sort and filters only')
        ->assertSuccessful();

    $request = app_path("Http/Requests/{$model}/Index{$model}Request.php");

    expect(File::get($request))->toContain(<<<'PHP'
    public function toCommand(): ListSamplingPalletsCommand
    {
        return new ListSamplingPalletsCommand(
            sort: $this->string('sort')->toString(),
            direction: $this->string('direction')->toString(),
            search: $this->string('search')->toString(),
            createdFrom: $this->string('created_from')->toString(),
            createdTo: $this->string('created_to')->toString(),
            perPage: $this->string('per_page')->toString(),
        );
    }
PHP)
        ->and(File::get(app_path("Http/Controllers/{$model}Controller.php")))
        ->toContain('$result->samplingPallets');

    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', $request]);

    expect($pint->successful())->toBeTrue($pint->output());
});

it('leaves an index request that already exists as it is', function () {
    File::ensureDirectoryExists(dirname(indexRequestGenerated()));
    File::put(indexRequestGenerated(), '<?php // kept by hand');

    runMakeController(['--only' => 'index'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(indexRequestGenerated()))->toBe('<?php // kept by hand');
});

it('writes only the methods --only names, and asks only for their buttons', function () {
    runMakeController(['--only' => 'destroy,index'])
        ->expectsOutputToContain("Route::resource('sampling-crates', SamplingCrateController::class)->only(['index', 'destroy']);")
        ->assertSuccessful();

    expect(controllerMethodsInOrder())->toBe(['index', 'destroy']);

    expect(File::get(controllerGenerated()))
        ->toContain("'delete' => Gate::allows('deleteAny', SamplingCrate::class),")
        ->not->toContain("'create' =>")
        ->not->toContain("'update' =>")
        ->not->toContain('FormValues');

    expect(File::exists(controllerTestGenerated('Store')))->toBeFalse();
});

it('writes a grid index that scrolls its rows and sends no sort', function () {
    runMakeController(['--only' => 'index,destroy', '--grid' => true])
        ->expectsOutputToContain('ListSamplingCratesCommand takes sort, but a grid sends no sort')
        ->assertSuccessful();

    expect(File::get(controllerGenerated()))
        ->toContain("'crates' => Inertia::scroll(\$result->crates),")
        ->toContain("'filters' => \$result->filters,")
        ->toContain("'delete' => Gate::allows('deleteAny', SamplingCrate::class),")
        ->not->toContain("'sort' =>")
        ->not->toContain('{{')
        ->toContain('$result = $listSamplingCrates($request->toCommand());')
        ->and(File::get(indexRequestGenerated()))->toContain('public function toCommand(): ListSamplingCratesCommand')
        ->and(File::get(controllerTestGenerated('Index')))
        ->toContain("it('renders the page with its rows through Inertia::scroll() and its filters')->todo();");

    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', controllerGenerated(), indexRequestGenerated()]);

    expect($pint->successful())->toBeTrue($pint->output());
});

it('adds the missing methods to a controller in resource order and leaves the rest as it was', function () {
    runMakeController(['--only' => 'index,destroy'])->assertSuccessful();

    $controller = File::get(controllerGenerated());
    $index = File::get(controllerTestGenerated('Index'));
    preg_match('/    \/\*\*\n     \* Display a listing.*?\n    }\n/s', $controller, $indexMethod);

    runMakeController(['--only' => 'create,store,edit,update'])
        ->expectsOutputToContain("Add 'create' => Gate::allows('create', SamplingCrate::class) to the can of index()")
        ->expectsOutputToContain("Add 'update' => Gate::allows('updateAny', SamplingCrate::class) to the can of index()")
        ->expectsOutputToContain("->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);")
        ->assertSuccessful();

    $after = File::get(controllerGenerated());

    expect(controllerMethodsInOrder())->toBe(['index', 'create', 'store', 'edit', 'update', 'destroy'])
        ->and($after)->toContain($indexMethod[0])
        ->and($after)->toContain("return to_route('sampling-crates.index');")
        ->and(File::get(controllerTestGenerated('Index')))->toBe($index);

    preg_match_all('/^use ([^;]+);$/m', $after, $imports);
    $sorted = $imports[1];
    usort($sorted, 'strcasecmp');

    expect($imports[1])->toBe($sorted)
        ->and($imports[1])->toBe(array_values(array_unique($imports[1])))
        ->and($imports[1])->toContain('App\Http\Requests\SamplingCrate\StoreSamplingCrateRequest');
});

it('leaves a method the controller already has as it is', function () {
    runMakeController(['--only' => 'index'])->assertSuccessful();
    File::put(controllerGenerated(), str_replace('Display a listing of the resource.', 'Kept by hand.', File::get(controllerGenerated())));

    runMakeController(['--only' => 'index'])
        ->expectsOutputToContain('already has index(), left as it is')
        ->assertSuccessful();

    expect(File::get(controllerGenerated()))->toContain('Kept by hand.');
});

it('sends store and update back to the list when there is no show page', function () {
    runMakeController(['--only' => 'create,store,update'])->assertSuccessful();

    expect(File::get(controllerGenerated()))
        ->toContain('$createSamplingCrate($request->toCommand());')
        ->toContain("return to_route('sampling-crates.index');")
        ->not->toContain('$id =');
});

it('writes both forms for a dialog: create and edit answer JSON, store and update go back', function () {
    runMakeController(['--only' => 'index,create,store,edit,update', '--dialog' => null])->assertSuccessful();

    expect(File::get(controllerGenerated()))
        ->toContain('public function create(): JsonResponse')
        ->toContain("'defaults' => SamplingCrateFormValues::empty(),")
        ->toContain('public function edit(SamplingCrate $samplingCrate): JsonResponse')
        ->toContain("'defaults' => SamplingCrateFormValues::of(\$samplingCrate),")
        ->toContain("return Inertia::flash(FlashToast::KEY, FlashToast::success(__('sampling-crates.created')))->back();")
        ->toContain("return Inertia::flash(FlashToast::KEY, FlashToast::success(__('sampling-crates.updated')))->back();")
        ->toContain('use Illuminate\Http\JsonResponse;')
        ->not->toContain('Inertia::render(\'sampling-crates/create\'')
        ->not->toContain('to_route(');

    expect(File::get(controllerTestGenerated('Create')))->toContain("it('answers the empty defaults as JSON')->todo();")
        ->and(File::get(controllerTestGenerated('Edit')))->toContain("it('answers the defaults of the row as JSON')->todo();")
        ->and(File::get(controllerTestGenerated('Store')))->toContain("it('creates the row, goes back and answers with a success toast')->todo();")
        ->and(File::get(controllerTestGenerated('Update')))->toContain("it('updates the row, goes back and answers with a success toast')->todo();");
});

it('writes only the form --dialog names for a dialog, and keeps the other a page', function () {
    runMakeController(['--only' => 'create,store,edit,update', '--dialog' => 'edit'])->assertSuccessful();

    expect(File::get(controllerGenerated()))
        ->toContain('public function create(): Response')
        ->toContain("Inertia::render('sampling-crates/create'")
        ->toContain("return to_route('sampling-crates.index');")
        ->toContain('public function edit(SamplingCrate $samplingCrate): JsonResponse')
        ->toContain("return Inertia::flash(FlashToast::KEY, FlashToast::success(__('sampling-crates.updated')))->back();");
});

it('follows a form the controller already answers as JSON when it adds the method that saves it', function () {
    runMakeController(['--only' => 'edit', '--dialog' => 'edit'])->assertSuccessful();
    runMakeController(['--only' => 'update'])->assertSuccessful();

    expect(File::get(controllerGenerated()))
        ->toContain("return Inertia::flash(FlashToast::KEY, FlashToast::success(__('sampling-crates.updated')))->back();")
        ->not->toContain('to_route(');
});

it('keeps a form that already renders a page a page, and says so', function () {
    runMakeController(['--only' => 'create'])->assertSuccessful();

    runMakeController(['--only' => 'store', '--dialog' => 'create'])
        ->expectsOutputToContain('create() already renders a page, so the form stays a page')
        ->assertSuccessful();

    expect(File::get(controllerGenerated()))->toContain("return to_route('sampling-crates.index');");
});

it('refuses a --dialog that names something that is not a form', function () {
    runMakeController(['--dialog' => 'show'])
        ->expectsOutputToContain('--dialog names show, which is not a form. Pick from create, edit.')
        ->assertFailed();
});

it('reads the model from a name that ends in Controller', function () {
    $this->artisan('make:controller', ['name' => CONTROLLER_MODEL.'Controller', '--domain' => CONTROLLER_CONTEXT, '--only' => 'show'])
        ->assertSuccessful();

    expect(File::get(controllerGenerated()))->toContain('public function show(SamplingCrate $samplingCrate): Response');
});

it('refuses the controllers it does not write', function (string $option, string $message) {
    runMakeController([$option => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::exists(controllerGenerated()))->toBeFalse();
})->with([
    'invokable' => ['--invokable', 'run `php artisan make:action`'],
    'api' => ['--api', 'Leave out --api'],
    'singleton' => ['--singleton', 'resource controllers only'],
]);

it('refuses a method a resource controller does not have', function () {
    runMakeController(['--only' => 'index,publish'])
        ->expectsOutputToContain('--only names publish')
        ->assertFailed();
});

it('fails and points at make:use-case when the list Command does not exist', function () {
    runMakeController(['--list' => 'ListNothing'])
        ->expectsOutputToContain('make:use-case ListNothing --domain='.CONTROLLER_CONTEXT.' --command --result --query')
        ->assertFailed();

    expect(File::exists(controllerGenerated()))->toBeFalse();
});

it('fails without a domain under --no-interaction', function () {
    $this->artisan('make:controller', ['name' => CONTROLLER_MODEL, '--no-interaction' => true])
        ->expectsOutputToContain('Pass --domain')
        ->assertFailed();
});

it('names the abilities, classes and translations still missing', function () {
    runMakeController()
        ->expectsOutputToContain('has no create(), updateAny(), deleteAny(), update(), delete()')
        ->expectsOutputToContain('SamplingCrateFormValues] does not exist yet')
        ->expectsOutputToContain('php artisan make:form-request')
        ->expectsOutputToContain('sampling-crates.created')
        ->assertSuccessful();
});

it('writes PHP that parses, loads and pint leaves as it is', function () {
    runMakeController(['--only' => 'index,show,destroy'])->assertSuccessful();
    runMakeController(['--only' => 'create,store,edit,update'])->assertSuccessful();

    $paths = [controllerGenerated(), indexRequestGenerated(), ...File::files(base_path('tests/Feature/Http/Controllers/'.CONTROLLER_MODEL.'Controller'))];

    foreach ($paths as $path) {
        expect(Process::run(['php', '-l', (string) $path])->successful())->toBeTrue();
    }

    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', ...array_map('strval', $paths)]);

    expect($pint->successful())->toBeTrue($pint->output());

    require_once controllerGenerated();

    $public = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass('App\\Http\\Controllers\\'.CONTROLLER_MODEL.'Controller'))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect(array_values(array_diff($public, get_class_methods(Controller::class))))
        ->toBe(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
});

it('writes dialog forms in PHP that parses and pint leaves as it is', function () {
    runMakeController(['--only' => 'index,create,store,edit,update', '--dialog' => null])->assertSuccessful();

    $paths = [controllerGenerated(), indexRequestGenerated(), ...File::files(base_path('tests/Feature/Http/Controllers/'.CONTROLLER_MODEL.'Controller'))];

    foreach ($paths as $path) {
        expect(Process::run(['php', '-l', (string) $path])->successful())->toBeTrue();
    }

    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', ...array_map('strval', $paths)]);

    expect($pint->successful())->toBeTrue($pint->output());
});
