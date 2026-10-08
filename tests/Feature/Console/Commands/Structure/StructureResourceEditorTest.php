<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureResourceEditor;

/**
 * The scratch context and resources of this file. They carry `Sampling`, so no Architecture check
 * reads them, and differ from every other test file's. SamplingRoute lists a use case of every
 * shape; SamplingRouteCase is only designed, and SamplingRouteBuilt has a model and a controller
 * with one method.
 */
const SAMPLING_ROUTE_CONTEXT = 'SamplingRoute';

const SAMPLING_ROUTE_CASE = 'SamplingRouteCase';

const SAMPLING_ROUTE_BUILT = 'SamplingRouteBuilt';

const SAMPLING_ROUTE_NEW = 'SamplingRouteNew';

function samplingRouteEditor(): StructureResourceEditor
{
    return new StructureResourceEditor(new StructureFiles(base_path()), new StructureReader(base_path()));
}

/**
 * @return array<string, mixed>
 */
function samplingRouteManifest(string $resource = SAMPLING_ROUTE_CASE): array
{
    return json_decode(File::get(base_path(".kit/structure/http/{$resource}.json")), true);
}

function samplingRouteVersion(string $resource = SAMPLING_ROUTE_CASE): string
{
    return (new StructureFiles(base_path()))->resourceVersion($resource);
}

function samplingRouteUseCase(string $name): string
{
    return SAMPLING_ROUTE_CONTEXT.'/'.$name;
}

function writeSamplingRouteFixtures(): void
{
    $files = new StructureFiles(base_path());
    $useCase = fn (string $shape, string $returns, bool $query = false): array => ['shape' => $shape, 'returns' => $returns, 'creates' => false, 'query' => $query, 'repositories' => []];

    $files->write([
        'context' => SAMPLING_ROUTE_CONTEXT,
        'aggregates' => [],
        'services' => [],
        'ports' => [],
        'useCases' => [
            'ArchiveSamplingRouteCase' => $useCase('command', 'void'),
            'CloseSamplingRouteCases' => $useCase('command', 'int'),
            'CreateSamplingRouteCase' => $useCase('command', 'string'),
            'DeleteSamplingRouteCase' => $useCase('plain', 'void'),
            'ListSamplingRouteCases' => $useCase('command-result', 'result', query: true),
        ],
    ]);
    $files->writeResource([
        'resource' => SAMPLING_ROUTE_CASE,
        'model' => SAMPLING_ROUTE_CASE,
        'controller' => ['index' => [samplingRouteUseCase('ListSamplingRouteCases')]],
        'actions' => [],
        'policy' => null,
        'pages' => ['sampling-route-cases/index' => 'table'],
    ]);
    $files->writeResource([
        'resource' => SAMPLING_ROUTE_BUILT,
        'model' => SAMPLING_ROUTE_BUILT,
        'controller' => ['index' => [], 'show' => []],
        'actions' => [],
        'policy' => null,
        'pages' => [],
    ]);

    File::put(app_path('Models/'.SAMPLING_ROUTE_BUILT.'.php'), "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass ".SAMPLING_ROUTE_BUILT." extends Model {}\n");
    File::put(app_path('Http/Controllers/'.SAMPLING_ROUTE_BUILT.'Controller.php'), "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass ".SAMPLING_ROUTE_BUILT."Controller\n{\n    public function index(): void {}\n}\n");
}

function forgetSamplingRoute(): void
{
    File::delete([
        base_path('.kit/structure/'.SAMPLING_ROUTE_CONTEXT.'.json'),
        ...array_map(fn (string $resource): string => base_path(".kit/structure/http/{$resource}.json"), [SAMPLING_ROUTE_CASE, SAMPLING_ROUTE_BUILT, SAMPLING_ROUTE_NEW]),
        app_path('Models/'.SAMPLING_ROUTE_BUILT.'.php'),
        app_path('Http/Controllers/'.SAMPLING_ROUTE_BUILT.'Controller.php'),
    ]);
}

beforeEach(function () {
    forgetSamplingRoute();
    writeSamplingRouteFixtures();
});

afterEach(fn () => forgetSamplingRoute());

describe('StructureResourceEditor', function () {
    describe('createResource', function () {
        it('writes the empty manifest of a new resource', function () {
            expect(samplingRouteEditor()->createResource(SAMPLING_ROUTE_NEW, null))->toBe([])
                ->and(samplingRouteManifest(SAMPLING_ROUTE_NEW))->toBe(['resource' => SAMPLING_ROUTE_NEW, 'model' => null, 'controller' => [], 'actions' => [], 'policy' => null, 'pages' => []]);
        });

        it('refuses a name or a model that does not fit', function (string $name, ?string $model, string $field) {
            expect(samplingRouteEditor()->createResource($name, $model))->toHaveKey($field);
        })->with([
            'a name not in StudlyCase' => ['samplingRouteNew', null, 'name'],
            'the kit\'s own page' => ['AuditEntry', 'AuditEntry', 'name'],
            'a resource already there' => [SAMPLING_ROUTE_CASE, SAMPLING_ROUTE_CASE, 'name'],
            'a model not in StudlyCase' => [SAMPLING_ROUTE_NEW, 'route case', 'model'],
        ]);
    });

    describe('saveResource', function () {
        it('sets the model and the policy\'s abilities', function () {
            expect(samplingRouteEditor()->saveResource(SAMPLING_ROUTE_CASE, samplingRouteVersion(), SAMPLING_ROUTE_CASE, ['viewAny', 'create', 'viewAny']))->toBe([])
                ->and(samplingRouteManifest()['policy'])->toBe(['create', 'viewAny']);
        });

        it('refuses a policy that does not fit', function (string $model, array $policy) {
            expect(samplingRouteEditor()->saveResource(SAMPLING_ROUTE_CASE, samplingRouteVersion(), $model === '' ? null : $model, $policy))->toHaveKey('policy');
        })->with([
            'an ability not in camelCase' => [SAMPLING_ROUTE_CASE, ['ViewAny']],
            'a policy with no model to attach to' => ['', ['viewAny']],
        ]);

        it('leaves a model the code already has alone', function () {
            expect(samplingRouteEditor()->saveResource(SAMPLING_ROUTE_BUILT, samplingRouteVersion(SAMPLING_ROUTE_BUILT), 'SamplingRouteOther', null))->toHaveKey('model');
        });
    });

    describe('savePiece', function () {
        it('adds a method, an action and a page in the manifest\'s canonical shape', function () {
            $editor = samplingRouteEditor();

            expect($editor->savePiece(SAMPLING_ROUTE_CASE, samplingRouteVersion(), 'controller', null, 'store', ['useCases' => [samplingRouteUseCase('CreateSamplingRouteCase')]]))->toBe([])
                ->and($editor->savePiece(SAMPLING_ROUTE_CASE, samplingRouteVersion(), 'actions', null, 'Close', ['row' => true, 'bulk' => true, 'useCases' => [samplingRouteUseCase('CloseSamplingRouteCases')]]))->toBe([])
                ->and($editor->savePiece(SAMPLING_ROUTE_CASE, samplingRouteVersion(), 'pages', null, 'sampling-route-cases/create', ['kind' => 'form']))->toBe([])
                ->and(samplingRouteManifest())->toMatchArray([
                    'controller' => ['index' => [samplingRouteUseCase('ListSamplingRouteCases')], 'store' => [samplingRouteUseCase('CreateSamplingRouteCase')]],
                    'actions' => ['Close' => ['row' => true, 'bulk' => true, 'useCases' => [samplingRouteUseCase('CloseSamplingRouteCases')]]],
                    'pages' => ['sampling-route-cases/create' => 'form', 'sampling-route-cases/index' => 'table'],
                ]);
        });

        it('renames a piece the code does not have yet', function () {
            $editor = samplingRouteEditor();
            $editor->savePiece(SAMPLING_ROUTE_CASE, samplingRouteVersion(), 'controller', null, 'picker', ['useCases' => [samplingRouteUseCase('ListSamplingRouteCases')]]);

            expect($editor->savePiece(SAMPLING_ROUTE_CASE, samplingRouteVersion(), 'controller', 'picker', 'chooser', ['useCases' => []]))->toBe([])
                ->and(samplingRouteManifest()['controller'])->toHaveKey('chooser')->not->toHaveKey('picker');
        });

        it('refuses a piece that breaks a rule, under the field that holds it, and writes nothing', function (string $section, ?string $previous, string $name, array $entry, string $field) {
            $before = samplingRouteVersion();

            expect(samplingRouteEditor()->savePiece(SAMPLING_ROUTE_CASE, $before, $section, $previous, $name, $entry))->toHaveKey($field)
                ->and(samplingRouteVersion())->toBe($before);
        })->with([
            'a method not in camelCase' => ['controller', null, 'Picker', ['useCases' => []], 'name'],
            'a method already in the manifest' => ['controller', null, 'index', ['useCases' => []], 'name'],
            'an index that reads no list' => ['controller', 'index', 'index', ['useCases' => [samplingRouteUseCase('CreateSamplingRouteCase')]], 'useCases'],
            'a destroy that takes a Command' => ['controller', null, 'destroy', ['useCases' => [samplingRouteUseCase('CreateSamplingRouteCase')]], 'useCases'],
            'a store that calls nothing' => ['controller', null, 'store', ['useCases' => []], 'useCases'],
            'a use case no manifest lists' => ['controller', null, 'store', ['useCases' => [samplingRouteUseCase('Missing')]], 'useCases'],
            'a use case not named Context/UseCase' => ['controller', null, 'store', ['useCases' => ['missing']], 'useCases'],
            'an action not named with a verb' => ['actions', null, 'close', ['row' => true, 'useCases' => [samplingRouteUseCase('ArchiveSamplingRouteCase')]], 'name'],
            'an action on neither a row nor a selection' => ['actions', null, 'Archive', ['useCases' => [samplingRouteUseCase('ArchiveSamplingRouteCase')]], 'row'],
            'an action calling a plain use case' => ['actions', null, 'Archive', ['row' => true, 'useCases' => [samplingRouteUseCase('DeleteSamplingRouteCase')]], 'useCases'],
            'a bulk action that counts nothing' => ['actions', null, 'Archive', ['bulk' => true, 'useCases' => [samplingRouteUseCase('ArchiveSamplingRouteCase')]], 'useCases'],
            'a page with no folder' => ['pages', null, 'index', ['kind' => 'page'], 'name'],
            'a kind the schema does not know' => ['pages', null, 'sampling-route-cases/show', ['kind' => 'card'], 'kind'],
            'a list page away from where make:list-page writes it' => ['pages', 'sampling-route-cases/index', 'cases/index', ['kind' => 'table'], 'name'],
            'a form page with nothing to post to' => ['pages', null, 'sampling-route-cases/create', ['kind' => 'form'], 'kind'],
        ]);

        it('refuses a change to a manifest that changed since the page loaded', function () {
            expect(samplingRouteEditor()->savePiece(SAMPLING_ROUTE_CASE, 'stale', 'pages', null, 'sampling-route-cases/show', ['kind' => 'page']))->toHaveKey('version');
        });

        it('leaves a method the code already has alone, and changes the ones it lacks', function () {
            $editor = samplingRouteEditor();

            expect($editor->savePiece(SAMPLING_ROUTE_BUILT, samplingRouteVersion(SAMPLING_ROUTE_BUILT), 'controller', 'index', 'index', ['useCases' => []])['name'][0])->toContain('The code already has index')
                ->and($editor->savePiece(SAMPLING_ROUTE_BUILT, samplingRouteVersion(SAMPLING_ROUTE_BUILT), 'controller', 'show', 'edit', ['useCases' => []]))->toBe([]);
        });
    });

    describe('removePiece', function () {
        it('removes a piece the code does not have yet', function () {
            expect(samplingRouteEditor()->removePiece(SAMPLING_ROUTE_CASE, samplingRouteVersion(), 'pages', 'sampling-route-cases/index'))->toBe([])
                ->and(samplingRouteManifest()['pages'])->toBe([]);
        });

        it('keeps the index while a list page needs it', function () {
            expect(samplingRouteEditor()->removePiece(SAMPLING_ROUTE_CASE, samplingRouteVersion(), 'controller', 'index'))->toHaveKey('name')
                ->and(samplingRouteManifest()['controller'])->toHaveKey('index');
        });

        it('keeps a method the code already has', function () {
            expect(samplingRouteEditor()->removePiece(SAMPLING_ROUTE_BUILT, samplingRouteVersion(SAMPLING_ROUTE_BUILT), 'controller', 'index'))->toHaveKey('name');
        });
    });

    describe('syncPiece', function () {
        it('takes a built entry back from the code, and refuses one the code does not have or a stale page', function () {
            $manifest = samplingRouteManifest(SAMPLING_ROUTE_BUILT);
            (new StructureFiles(base_path()))->writeResource([...$manifest, 'controller' => [...$manifest['controller'], 'index' => [samplingRouteUseCase('ListSamplingRouteCases')]]]);
            $editor = samplingRouteEditor();

            expect($editor->syncPiece(SAMPLING_ROUTE_BUILT, 'stale', 'controller', 'index'))->toHaveKey('version')
                ->and($editor->syncPiece(SAMPLING_ROUTE_BUILT, samplingRouteVersion(SAMPLING_ROUTE_BUILT), 'controller', 'show'))->toBe(['name' => ['The code has no show, so there is nothing to sync from.']])
                ->and($editor->syncPiece(SAMPLING_ROUTE_BUILT, samplingRouteVersion(SAMPLING_ROUTE_BUILT), 'controller', 'index'))->toBe([])
                ->and(samplingRouteManifest(SAMPLING_ROUTE_BUILT)['controller'])->toBe(['index' => [], 'show' => []]);
        });
    });
});
