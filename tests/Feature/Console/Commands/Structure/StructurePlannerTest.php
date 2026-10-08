<?php

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch context and model of this file. Both carry `Sampling`, so no Architecture check
 * reads them, and differ from every other test file's, so --parallel never deletes them mid-run.
 * Every case writes the same fixtures, because a class one case loads stays loaded for the next.
 */
const SAMPLING_PLAN_CONTEXT = 'SamplingPlan';

const SAMPLING_PLAN_MODEL = 'SamplingSack';

function samplingPlanMarkersRoot(): string
{
    return storage_path('framework/testing/sampling-plan');
}

/**
 * Built so far: the Sack root, the list use case with an empty Row, and StitchSack with a filled
 * Command. Everything else the manifest lists is still to build.
 */
function writeSamplingPlanFixtures(): void
{
    $context = SAMPLING_PLAN_CONTEXT;
    $useCases = "App\\Application\\{$context}\\UseCases";
    $fixtures = [
        "Domain/{$context}/Sack/SackEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Sack;\n\nfinal class SackEntity\n{\n    public function stitch(): void {}\n}\n",
        "Domain/{$context}/Sack/Exceptions/SackTornException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Sack\\Exceptions;\n\nfinal class SackTornException extends \\RuntimeException {}\n",
        "Domain/{$context}/Sack/Enums/SackColour.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Sack\\Enums;\n\nenum SackColour: string\n{\n    case Red = 'red';\n}\n",
        "Application/{$context}/UseCases/ListSamplingSacks/ListSamplingSacksHandler.php" => "<?php\n\nnamespace {$useCases}\\ListSamplingSacks;\n\nfinal class ListSamplingSacksHandler {}\n",
        "Application/{$context}/UseCases/ListSamplingSacks/SamplingSackListRow.php" => "<?php\n\nnamespace {$useCases}\\ListSamplingSacks;\n\nfinal class SamplingSackListRow\n{\n    /**\n     * @return array{id: string}\n     */\n    public function toArray(): array { return []; }\n}\n",
        "Application/{$context}/UseCases/StitchSack/StitchSackHandler.php" => "<?php\n\nnamespace {$useCases}\\StitchSack;\n\nfinal class StitchSackHandler {}\n",
        "Application/{$context}/UseCases/StitchSack/StitchSackCommand.php" => "<?php\n\nnamespace {$useCases}\\StitchSack;\n\nfinal class StitchSackCommand\n{\n    public function __construct(public readonly string \$sackId) {}\n}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(app_path($relative)));
        File::put(app_path($relative), $contents);
    }

    $files = new StructureFiles(base_path());
    $files->write([
        'context' => $context,
        'aggregates' => [
            'Sack' => ['children' => ['Thread'], 'repository' => true],
            'Bale' => ['children' => ['Twine'], 'repository' => false],
        ],
        'services' => [
            'PackSack' => ['shape' => 'creates', 'creates' => 'Sack', 'repositories' => ['Sack']],
            'WeighSack' => ['shape' => 'plain', 'creates' => null, 'repositories' => ['Sack'], 'exception' => true],
        ],
        'ports' => [
            'Weigher' => ['layer' => 'domain', 'adapter' => 'Infra/Scales/DigitalWeigher'],
        ],
        'useCases' => [
            'ListSamplingSacks' => ['shape' => 'command-result', 'returns' => 'result', 'creates' => false, 'query' => true, 'repositories' => []],
            'ShipSack' => ['shape' => 'command-result', 'returns' => 'result', 'creates' => true, 'query' => false, 'repositories' => ['Credit/Wallet', 'Sack']],
            'StitchSack' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => []],
            'TidySacks' => ['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => []],
        ],
        'enums' => [
            'SackColour' => ['aggregate' => 'Sack', 'backing' => 'string', 'cases' => ['Red' => 'red']],
            'SackGrade' => ['aggregate' => 'Sack', 'backing' => 'string', 'cases' => ['Top' => 'top', 'Low' => 'low']],
            'SackSide' => ['aggregate' => 'Bale', 'backing' => null, 'cases' => ['Left' => null]],
            'SackStatus' => ['aggregate' => 'Sack', 'backing' => 'string', 'cases' => ['Open' => 'open', 'Sewn' => 'sewn', 'Shipped' => 'shipped'], 'transitions' => [
                'Shipped' => [],
                'Sewn' => ['Shipped'],
                'Open' => ['Shipped', 'Sewn'],
            ]],
        ],
        'valueObjects' => [
            'SackSeal' => ['aggregate' => 'Sack', 'fields' => ['colour' => 'SackColour', 'code' => 'string|int', 'price' => 'Shared/Money']],
            'SackTag' => ['aggregate' => 'Sack', 'fields' => ['grade' => 'SackGrade', 'note' => '?string', 'bin' => 'Elsewhere/Bin/BinKind']],
        ],
        'exceptions' => [
            'SackFullException' => ['kind' => 'refusal', 'aggregate' => 'Sack', 'useCase' => null],
            'SackWetException' => ['kind' => 'value', 'aggregate' => 'Sack', 'useCase' => null],
            'SamplingPlanQuotaException' => ['kind' => 'application', 'aggregate' => null, 'useCase' => null],
            'SackTakenException' => ['kind' => 'application', 'aggregate' => null, 'useCase' => 'ShipSack'],
            'StitchRefusedException' => ['kind' => 'application', 'aggregate' => null, 'useCase' => 'StitchSack'],
        ],
        'entities' => [
            'Sack' => [
                'aggregate' => 'Sack',
                'behaviours' => [
                    'stitch' => ['params' => [], 'throws' => []],
                    'fill' => ['params' => ['colour' => 'SackColour', 'tags' => '...string'], 'throws' => ['SackTornException', 'SackFullException']],
                    'weigh' => ['params' => [], 'throws' => ['Shared/SamplingPlanScaleException', 'SackHeavy']],
                ],
                'assertions' => [
                    'assertIntact' => ['params' => [], 'throws' => ['SackTornException']],
                ],
            ],
            'Thread' => ['aggregate' => 'Sack', 'behaviours' => ['snap' => ['params' => [], 'throws' => []]], 'assertions' => []],
        ],
    ]);
    $files->writeResource([
        'resource' => SAMPLING_PLAN_MODEL,
        'model' => SAMPLING_PLAN_MODEL,
        'controller' => [
            'index' => ["{$context}/ListSamplingSacks"],
            'update' => ["{$context}/RestitchSack"],
        ],
        'actions' => [
            'Stitch' => ['row' => true, 'bulk' => false, 'useCases' => ["{$context}/StitchSack"]],
        ],
        'policy' => ['viewAny'],
        'pages' => [
            'sampling-sacks/index' => 'table',
            'sampling-sacks/edit' => 'form',
        ],
    ]);

    $routes = samplingPlanMarkersRoot().'/routes/web.php';
    File::ensureDirectoryExists(dirname($routes));
    File::put($routes, "<?php\n\nRoute::resource('sacks', \\App\\Http\\Controllers\\".SAMPLING_PLAN_MODEL."Controller::class);\n// kit:routes\n");
}

function forgetSamplingPlan(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_PLAN_CONTEXT));
    File::deleteDirectory(app_path('Application/'.SAMPLING_PLAN_CONTEXT));
    File::deleteDirectory(samplingPlanMarkersRoot());
    File::delete([
        base_path('.kit/structure/'.SAMPLING_PLAN_CONTEXT.'.json'),
        base_path('.kit/structure/http/'.SAMPLING_PLAN_MODEL.'.json'),
    ]);
}

/**
 * Each step of the scratch manifests by title, as its state and either the line it runs or why it
 * waits.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function samplingPlanSteps(): array
{
    $planner = new StructurePlanner(
        new StructureReader(base_path()),
        new StructureFiles(base_path()),
        new StructureMarkers(samplingPlanMarkersRoot()),
    );
    $steps = [];

    foreach ($planner->steps([SAMPLING_PLAN_CONTEXT], [SAMPLING_PLAN_MODEL]) as $step) {
        $steps[$step['title']] = [$step['state'], $step['state'] === StructurePlanner::WAITING ? (string) $step['reason'] : StructurePlanner::describe($step)];
    }

    return $steps;
}

beforeEach(function () {
    forgetSamplingPlan();
    writeSamplingPlanFixtures();
});

afterEach(fn () => forgetSamplingPlan());

describe('StructurePlanner', function () {
    describe('steps', function () {
        it('builds an aggregate root first, then its children and its repository', function () {
            $steps = samplingPlanSteps();
            $domain = SAMPLING_PLAN_CONTEXT;

            expect($steps["aggregate {$domain}/Sack"][0])->toBe(StructurePlanner::DONE)
                ->and($steps["child {$domain}/Sack/Thread"])->toBe([StructurePlanner::READY, "php artisan make:entity Thread --domain={$domain}/Sack --child"])
                ->and($steps["repository {$domain}/Sack"])->toBe([StructurePlanner::READY, "php artisan make:eloquent-repository Sack --domain={$domain}/Sack"])
                ->and($steps["aggregate {$domain}/Bale"])->toBe([StructurePlanner::READY, "php artisan make:entity Bale --domain={$domain}/Bale"])
                ->and($steps["child {$domain}/Bale/Twine"])->toBe([StructurePlanner::WAITING, 'the root BaleEntity comes first']);
        });

        it('builds an enum with its backing and its cases in order, and a status with where each case may go', function () {
            $steps = samplingPlanSteps();
            $domain = SAMPLING_PLAN_CONTEXT;

            expect($steps["enum {$domain}/SackColour"][0])->toBe(StructurePlanner::DONE)
                ->and($steps["enum {$domain}/SackStatus"])->toBe([StructurePlanner::READY, "php artisan make:enum SackStatus --domain={$domain}/Sack --string --case=Open=open --case=Sewn=sewn --case=Shipped=shipped --transitions --transition=Open:Sewn,Shipped --transition=Sewn:Shipped"])
                ->and($steps["enum {$domain}/SackGrade"])->toBe([StructurePlanner::READY, "php artisan make:enum SackGrade --domain={$domain}/Sack --string --case=Top=top --case=Low=low"])
                ->and($steps["enum {$domain}/SackSide"])->toBe([StructurePlanner::READY, "php artisan make:enum SackSide --domain={$domain}/Bale --case=Left"]);
        });

        it('builds a value object once every class its fields name exists', function () {
            $steps = samplingPlanSteps();
            $domain = SAMPLING_PLAN_CONTEXT;

            expect($steps["value object {$domain}/SackSeal"])->toBe([StructurePlanner::READY, "php artisan make:value-object SackSeal --domain={$domain}/Sack --field=colour:SackColour '--field=code:string|int' --field=price:Shared/Money"])
                ->and($steps["value object {$domain}/SackTag"])->toBe([StructurePlanner::WAITING, 'SackGrade, Elsewhere/Bin/BinKind are not built yet']);
        });

        it('builds the exceptions of an entity\'s own aggregate, then each method its file does not declare', function () {
            $steps = samplingPlanSteps();
            $domain = SAMPLING_PLAN_CONTEXT;

            expect($steps["method {$domain}/Sack::stitch"][0])->toBe(StructurePlanner::DONE)
                ->and($steps["exception {$domain}/Sack/SackFullException"])->toBe([StructurePlanner::READY, "php artisan make:domain-exception SackFull --domain={$domain}/Sack --kind=refusal"])
                ->and($steps["method {$domain}/Sack::fill"])->toBe([StructurePlanner::WAITING, 'SackFullException is not built yet'])
                ->and($steps["method {$domain}/Sack::assertIntact"])->toBe([StructurePlanner::READY, "php artisan make:entity-method Sack assertIntact --domain={$domain}/Sack --throws=SackTornException"])
                ->and($steps["exception {$domain}/Sack/SackHeavy"])->toBe([StructurePlanner::WAITING, 'SackHeavy must end with Exception, the name make:domain-exception writes'])
                ->and($steps["method {$domain}/Sack::weigh"])->toBe([StructurePlanner::WAITING, 'SackHeavy, Shared/SamplingPlanScaleException are not built yet'])
                ->and($steps["method {$domain}/Thread::snap"])->toBe([StructurePlanner::WAITING, 'ThreadEntity is not built yet'])
                ->and($steps)->not->toHaveKey("exception {$domain}/Sack/SackTornException")
                ->and($steps)->not->toHaveKey("exception {$domain}/Shared/SamplingPlanScaleException");
        });

        it('builds each exception the manifest designs with the generator its kind takes, a use case\'s once the use case exists', function () {
            $steps = samplingPlanSteps();
            $domain = SAMPLING_PLAN_CONTEXT;

            expect($steps["exception {$domain}/Sack/SackWetException"])->toBe([StructurePlanner::READY, "php artisan make:domain-exception SackWet --domain={$domain}/Sack --kind=value"])
                ->and($steps["exception {$domain}/Sack/SackFullException"])->toBe([StructurePlanner::READY, "php artisan make:domain-exception SackFull --domain={$domain}/Sack --kind=refusal"])
                ->and($steps["exception {$domain}/SamplingPlanQuotaException"])->toBe([StructurePlanner::READY, "php artisan make:domain-exception SamplingPlanQuota --domain={$domain} --kind=application"])
                ->and($steps["exception {$domain}/StitchSack/StitchRefusedException"])->toBe([StructurePlanner::READY, "php artisan make:domain-exception StitchRefused --domain={$domain} --kind=application --use-case=StitchSack"])
                ->and($steps["exception {$domain}/ShipSack/SackTakenException"])->toBe([StructurePlanner::WAITING, "the use case {$domain}/ShipSack comes first"]);
        });

        it('passes a method its parameters in order', function () {
            File::put(app_path('Domain/'.SAMPLING_PLAN_CONTEXT.'/Sack/Exceptions/SackFullException.php'), "<?php\n\nnamespace App\\Domain\\".SAMPLING_PLAN_CONTEXT."\\Sack\\Exceptions;\n\nfinal class SackFullException extends \\RuntimeException {}\n");
            $domain = SAMPLING_PLAN_CONTEXT;

            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                Closure::bind(fn () => $this->missingClasses = [], $loader, ClassLoader::class)();
            }

            expect(samplingPlanSteps()["method {$domain}/Sack::fill"])->toBe([StructurePlanner::READY, "php artisan make:entity-method Sack fill --domain={$domain}/Sack --param=colour:SackColour --param=tags:...string --throws=SackFullException --throws=SackTornException"]);
        });

        it('reads the generator options of a port, a service and a use case off the manifest', function () {
            $steps = samplingPlanSteps();
            $domain = SAMPLING_PLAN_CONTEXT;

            expect($steps["port {$domain}/Weigher"][1])->toBe("php artisan make:port Weigher --domain={$domain} --adapter=Digital --infra=Scales")
                ->and($steps["service {$domain}/PackSack"][1])->toBe("php artisan make:domain-service PackSack --domain={$domain} --creates=Sack")
                ->and($steps["service {$domain}/WeighSack"][1])->toBe("php artisan make:domain-service WeighSack --domain={$domain} --plain --repo=Sack --exception")
                ->and($steps["use case {$domain}/ShipSack"][1])->toBe("php artisan make:use-case ShipSack --domain={$domain} --command --result --creates --repo=Sack")
                ->and($steps["use case {$domain}/TidySacks"][1])->toBe("php artisan make:use-case TidySacks --domain={$domain} --plain")
                ->and($steps["use case {$domain}/ListSamplingSacks"][0])->toBe(StructurePlanner::DONE)
                ->and($steps["use case {$domain}/StitchSack"][0])->toBe(StructurePlanner::DONE);
        });

        it('waits to bind a port until both it and its adapter exist', function () {
            $steps = samplingPlanSteps();

            expect($steps['binding Weigher'])->toBe([StructurePlanner::WAITING, 'the port and its adapter come first'])
                ->and($steps['binding ListSamplingSacksQuery'])->toBe([StructurePlanner::WAITING, 'the port and its adapter come first']);
        });

        it('builds the model, its policy and the controller of a resource once the use cases exist', function () {
            $steps = samplingPlanSteps();
            $model = SAMPLING_PLAN_MODEL;

            expect($steps["model {$model}"])->toBe([StructurePlanner::READY, "php artisan make:model {$model} --factory"])
                ->and($steps["policy {$model}"])->toBe([StructurePlanner::READY, "php artisan make:policy {$model}"])
                ->and($steps["controller {$model}"])->toBe([StructurePlanner::WAITING, 'the use cases it calls come first: '.SAMPLING_PLAN_CONTEXT.'/RestitchSack']);
        });

        it('waits for a person to fill in the code a generator reads', function () {
            $steps = samplingPlanSteps();
            $model = SAMPLING_PLAN_MODEL;

            expect($steps["action {$model}Stitch"])->toBe([StructurePlanner::READY, "php artisan make:action Stitch --model={$model} --domain=".SAMPLING_PLAN_CONTEXT.' --use-case=StitchSack'])
                ->and($steps['list page sampling-sacks/index'])->toBe([StructurePlanner::WAITING, 'fill in the keys of the ListSamplingSacks Row first'])
                ->and($steps["form request {$model}"])->toBe([StructurePlanner::WAITING, 'store names no use case — write the requests by hand'])
                ->and($steps["form pages {$model}"])->toBe([StructurePlanner::WAITING, "make:form-request writes {$model}FormValues first"]);
        });

        it('takes a route as done once a route file registers its controller, whatever its uri', function () {
            $steps = samplingPlanSteps();

            expect($steps['route sampling-sacks'][0])->toBe(StructurePlanner::DONE)
                ->and($steps['route sampling-sacks.stitch'])->toBe([StructurePlanner::WAITING, 'its controller comes first']);
        });

        it('runs the steps in the order they depend on each other', function () {
            $titles = array_keys(samplingPlanSteps());
            $position = fn (string $title): int => (int) array_search($title, $titles, true);
            $domain = SAMPLING_PLAN_CONTEXT;

            expect($position("aggregate {$domain}/Bale"))->toBeLessThan($position("child {$domain}/Bale/Twine"))
                ->and($position("enum {$domain}/SackGrade"))->toBeLessThan($position("value object {$domain}/SackTag"))
                ->and($position("exception {$domain}/Sack/SackHeavy"))->toBeLessThan($position("method {$domain}/Sack::weigh"))
                ->and($position("child {$domain}/Sack/Thread"))->toBeLessThan($position("method {$domain}/Thread::snap"))
                ->and($position("child {$domain}/Bale/Twine"))->toBeLessThan($position("repository {$domain}/Sack"))
                ->and($position("use case {$domain}/ShipSack"))->toBeLessThan($position('binding Weigher'))
                ->and($position('model '.SAMPLING_PLAN_MODEL))->toBeLessThan($position('policy '.SAMPLING_PLAN_MODEL))
                ->and($position('controller '.SAMPLING_PLAN_MODEL))->toBeLessThan($position('route sampling-sacks'));
        });
    });

    describe('nodes', function () {
        it('names the graph nodes each step builds', function () {
            $planner = new StructurePlanner(new StructureReader(base_path()), new StructureFiles(base_path()), new StructureMarkers(samplingPlanMarkersRoot()));
            $nodes = array_column($planner->steps([SAMPLING_PLAN_CONTEXT], [SAMPLING_PLAN_MODEL]), 'nodes', 'title');
            $domain = SAMPLING_PLAN_CONTEXT;
            $model = SAMPLING_PLAN_MODEL;

            expect($nodes["child {$domain}/Sack/Thread"])->toBe(["aggregate:{$domain}/Sack"])
                ->and($nodes['binding Weigher'])->toBe(["port:{$domain}/Weigher"])
                ->and($nodes["enum {$domain}/SackGrade"])->toBe(["enum:{$domain}/SackGrade"])
                ->and($nodes["method {$domain}/Sack::fill"])->toBe(["entity:{$domain}/Sack"])
                ->and($nodes["exception {$domain}/Sack/SackHeavy"])->toBe(["entity:{$domain}/Sack"])
                ->and($nodes["value object {$domain}/SackTag"])->toBe(["valueObject:{$domain}/SackTag"])
                ->and($nodes['binding ListSamplingSacksQuery'])->toBe(["useCase:{$domain}/ListSamplingSacks"])
                ->and($nodes['route sampling-sacks'])->toBe(["controller:{$model}"])
                ->and($nodes['route sampling-sacks.stitch'])->toBe(["action:{$model}/Stitch"])
                ->and($nodes["form pages {$model}"])->toBe(["page:{$model}/sampling-sacks/edit"])
                ->and($nodes['list page sampling-sacks/index'])->toBe(["page:{$model}/sampling-sacks/index"]);
        });
    });

    describe('describe', function () {
        it('writes a line step as the line and the marker it goes above', function () {
            expect(StructurePlanner::describe(['command' => null, 'arguments' => [], 'marker' => StructureMarkers::ROUTES, 'line' => "Route::get('x', \\X::class);", 'swap' => null]))
                ->toBe("Route::get('x', \\X::class);  (above // kit:routes)");
        });

        it('writes a list\'s swap as the classes and the TypeScript names it points at the new list', function () {
            $swap = [
                'classes' => ['App\\Ship\\ListCratesHandler' => 'App\\Ship\\ListOpenCratesHandler'],
                'directories' => ['app/Http'],
                'except' => [],
                'names' => ['CrateRow' => 'OpenCrateRow'],
                'nameDirectories' => ['resources/js/pages'],
            ];

            expect(StructurePlanner::describe(['command' => null, 'arguments' => [], 'marker' => null, 'line' => null, 'swap' => $swap]))
                ->toBe('swap ListCratesHandler -> ListOpenCratesHandler in app/Http; CrateRow -> OpenCrateRow in resources/js/pages');
        });
    });

    describe('listNames', function () {
        it('names a list\'s pieces as the generators write them', function () {
            expect(StructurePlanner::listNames('ListLotteryTypes'))->toBe([
                'item' => 'LotteryType',
                'sort' => 'LotteryTypeListSort',
                'row' => 'LotteryTypeRow',
                'filters' => 'LotteryTypeFilters',
                'query' => 'LotteryTypesQuery',
            ]);
        });
    });
});
