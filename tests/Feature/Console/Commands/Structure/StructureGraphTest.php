<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureComparer;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureGraph;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch context and resource of this file. Both carry `Sampling`, so no Architecture check
 * reads them, and differ from every other test file's. The graph reads every manifest, so the
 * comparer skips the other test files' scratch names, which may be half written mid-run.
 */
const SAMPLING_GRAPH_CONTEXT = 'SamplingGraph';

const SAMPLING_GRAPH_RESOURCE = 'SamplingGraphBox';

/**
 * Built so far: the Box root, ShipBox with an empty Command and a handler that returns void where
 * the manifest says int, and TidyBoxes, which the manifest does not list.
 */
function writeSamplingGraphFixtures(): void
{
    $context = SAMPLING_GRAPH_CONTEXT;
    $useCases = "App\\Application\\{$context}\\UseCases";
    $fixtures = [
        "Domain/{$context}/Box/BoxEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Box;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class BoxEntity extends AggregateRoot\n{\n    public function id(): string { return 'box'; }\n\n    public static function entityName(): string { return 'Box'; }\n}\n",
        "Application/{$context}/UseCases/ShipBox/ShipBoxCommand.php" => "<?php\n\nnamespace {$useCases}\\ShipBox;\n\nfinal class ShipBoxCommand {}\n",
        "Application/{$context}/UseCases/ShipBox/ShipBoxHandler.php" => "<?php\n\nnamespace {$useCases}\\ShipBox;\n\nfinal class ShipBoxHandler\n{\n    public function __invoke(ShipBoxCommand \$command): void {}\n}\n",
        "Domain/{$context}/Ports/Gauge.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Ports;\n\ninterface Gauge {}\n",
        "Application/{$context}/UseCases/TidyBoxesHandler.php" => "<?php\n\nnamespace {$useCases};\n\nfinal class TidyBoxesHandler\n{\n    public function __invoke(): void {}\n}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(app_path($relative)));
        File::put(app_path($relative), $contents);
    }

    $files = new StructureFiles(base_path());
    $files->write([
        'context' => $context,
        'aggregates' => [
            'Box' => ['children' => [], 'repository' => false],
            'Lid' => ['children' => [], 'repository' => true],
        ],
        'services' => [],
        'ports' => [
            'Gauge' => ['layer' => 'domain', 'adapter' => "Infra/{$context}/LaserGauge", 'replaces' => "Infra/{$context}/DialGauge"],
        ],
        'useCases' => [
            'ShipBox' => ['shape' => 'command', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => ['Box', 'Billing/Wallet']],
            'ShipBoxSafely' => ['shape' => 'command', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => [], 'replaces' => 'ShipBox'],
        ],
    ]);
    $files->writeResource([
        'resource' => SAMPLING_GRAPH_RESOURCE,
        'model' => SAMPLING_GRAPH_RESOURCE,
        'controller' => ['index' => ["{$context}/ListBoxes"]],
        'actions' => ['Ship' => ['row' => true, 'bulk' => false, 'useCases' => ["{$context}/ShipBox"]]],
        'policy' => null,
        'pages' => ['sampling-graph-boxes/index' => 'table'],
    ]);
}

function forgetSamplingGraph(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_GRAPH_CONTEXT));
    File::deleteDirectory(app_path('Application/'.SAMPLING_GRAPH_CONTEXT));
    File::delete([
        base_path('.kit/structure/'.SAMPLING_GRAPH_CONTEXT.'.json'),
        base_path('.kit/structure/http/'.SAMPLING_GRAPH_RESOURCE.'.json'),
    ]);
}

/**
 * @return array{overview: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}, contexts: array<string, array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}>, resources: array<string, array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}>, byHand: list<array<string, mixed>>}
 */
function samplingGraph(): array
{
    $root = base_path();
    $markers = new StructureMarkers(storage_path('framework/testing/sampling-graph'));

    return (new StructureGraph(
        new StructureReader($root),
        new StructureFiles($root),
        new StructurePlanner(new StructureReader($root), new StructureFiles($root), $markers),
        new StructureComparer(new StructureReader($root), new StructureFiles($root), fn (string $name): bool => str_contains($name, 'Sampling') && ! str_starts_with($name, SAMPLING_GRAPH_CONTEXT)),
    ))->graph();
}

/**
 * @param  array{nodes: list<array<string, mixed>>}  $view
 * @return array<string, mixed>
 */
function samplingGraphNode(array $view, string $id): array
{
    $nodes = array_values(array_filter($view['nodes'], fn (array $node): bool => $node['id'] === $id));

    expect($nodes)->toHaveCount(1);

    return $nodes[0];
}

/**
 * @param  array{edges: list<array<string, mixed>>}  $view
 * @return list<array{0: mixed, 1: mixed, 2: mixed}>
 */
function samplingGraphEdgesFrom(array $view, string $source): array
{
    return array_values(array_map(
        fn (array $edge): array => [$edge['source'], $edge['target'], $edge['label']],
        array_filter($view['edges'], fn (array $edge): bool => $edge['source'] === $source),
    ));
}

beforeEach(function () {
    forgetSamplingGraph();
    writeSamplingGraphFixtures();
});

afterEach(fn () => forgetSamplingGraph());

describe('StructureGraph', function () {
    describe('graph', function () {
        it('draws each context and resource on the overview, counting what is inside it', function () {
            $overview = samplingGraph()['overview'];
            $context = samplingGraphNode($overview, 'context:'.SAMPLING_GRAPH_CONTEXT);

            expect($context)->toMatchArray([
                'kind' => 'context',
                'label' => SAMPLING_GRAPH_CONTEXT,
                'items' => ['1 done', '3 ready', '1 differs'],
                'status' => StructureGraph::DIFFERS,
                'target' => ['view' => 'context', 'name' => SAMPLING_GRAPH_CONTEXT],
            ])
                ->and(samplingGraphNode($overview, 'resource:'.SAMPLING_GRAPH_RESOURCE)['status'])->toBe(StructurePlanner::WAITING)
                ->and(samplingGraphEdgesFrom($overview, 'context:'.SAMPLING_GRAPH_CONTEXT))->toBe([['context:'.SAMPLING_GRAPH_CONTEXT, 'context:Billing', 'uses']])
                ->and(samplingGraphEdgesFrom($overview, 'resource:'.SAMPLING_GRAPH_RESOURCE))->toBe([['resource:'.SAMPLING_GRAPH_RESOURCE, 'context:'.SAMPLING_GRAPH_CONTEXT, '2 use cases']]);
        });

        it('draws a context with the status of each piece and a card for what lives elsewhere', function () {
            $view = samplingGraph()['contexts'][SAMPLING_GRAPH_CONTEXT];
            $domain = SAMPLING_GRAPH_CONTEXT;
            $shipBox = samplingGraphNode($view, "useCase:{$domain}/ShipBox");

            expect(samplingGraphNode($view, "aggregate:{$domain}/Box"))->toMatchArray(['kind' => 'aggregate', 'status' => StructurePlanner::DONE, 'command' => null])
                ->and(samplingGraphNode($view, "aggregate:{$domain}/Lid"))->toMatchArray(['status' => StructurePlanner::READY, 'command' => "php artisan make:entity Lid --domain={$domain}/Lid"])
                ->and($shipBox)->toMatchArray(['items' => ['command', 'returns int'], 'status' => StructureGraph::DIFFERS])
                ->and($shipBox['reason'])->toBe('useCases.ShipBox.returns is "void" in the code but "int" in the manifest')
                ->and(samplingGraphNode($view, 'external:Billing/Wallet'))->toMatchArray(['kind' => 'external', 'status' => null, 'target' => ['view' => 'context', 'name' => 'Billing']])
                ->and(samplingGraphEdgesFrom($view, "useCase:{$domain}/ShipBox"))->toBe([
                    ["useCase:{$domain}/ShipBox", 'external:Billing/Wallet', null],
                    ["useCase:{$domain}/ShipBox", "aggregate:{$domain}/Box", null],
                ]);
        });

        it('draws a resource with the steps still waiting on code a person writes', function () {
            $view = samplingGraph()['resources'][SAMPLING_GRAPH_RESOURCE];
            $resource = SAMPLING_GRAPH_RESOURCE;
            $domain = SAMPLING_GRAPH_CONTEXT;

            expect(samplingGraphNode($view, "model:{$resource}"))->toMatchArray(['status' => StructurePlanner::READY, 'command' => "php artisan make:model {$resource} --factory"])
                ->and(samplingGraphNode($view, "controller:{$resource}"))->toMatchArray(['items' => ['index'], 'status' => StructurePlanner::WAITING, 'reason' => "the use cases it calls come first: {$domain}/ListBoxes"])
                ->and(samplingGraphNode($view, "action:{$resource}/Ship"))->toMatchArray(['items' => ['row'], 'status' => StructurePlanner::WAITING, 'reason' => 'fill in the fields of ShipBoxCommand first'])
                ->and(samplingGraphNode($view, "page:{$resource}/sampling-graph-boxes/index"))->toMatchArray(['items' => ['table'], 'status' => StructurePlanner::WAITING])
                ->and(samplingGraphEdgesFrom($view, "controller:{$resource}"))->toBe([
                    ["controller:{$resource}", "external:{$domain}/ListBoxes", 'index'],
                    ["controller:{$resource}", "page:{$resource}/sampling-graph-boxes/index", null],
                ]);
        });

        it('lets the screen change only the pieces the code does not have yet', function () {
            $graph = samplingGraph();
            $view = $graph['contexts'][SAMPLING_GRAPH_CONTEXT];
            $domain = SAMPLING_GRAPH_CONTEXT;

            expect(samplingGraphNode($view, "aggregate:{$domain}/Box")['editable'])->toBeFalse()
                ->and(samplingGraphNode($view, "aggregate:{$domain}/Lid")['editable'])->toBeTrue()
                ->and(samplingGraphNode($view, 'external:Billing/Wallet')['editable'])->toBeFalse()
                ->and(samplingGraphNode($graph['resources'][SAMPLING_GRAPH_RESOURCE], 'controller:'.SAMPLING_GRAPH_RESOURCE)['editable'])->toBeFalse()
                ->and($graph['manifests'][$domain]['aggregates'])->toHaveKeys(['Box', 'Lid'])
                ->and($graph['versions'][$domain])->toBe((new StructureFiles(base_path()))->version($domain));
        });

        it('hands the screen each resource manifest and the entries the code already has', function () {
            $graph = samplingGraph();
            $resource = SAMPLING_GRAPH_RESOURCE;
            $view = $graph['resources'][$resource];

            expect($graph['resourceManifests'][$resource]['model'])->toBe($resource)
                ->and($graph['resourceVersions'][$resource])->toBe((new StructureFiles(base_path()))->resourceVersion($resource))
                ->and($graph['resourceBuilt'][$resource])->toBe([])
                ->and($graph['resourceBuilt']['Product'])->toContain('model', 'controller.index', 'pages.products/index')
                ->and(samplingGraphNode($view, "action:{$resource}/Ship")['editable'])->toBeTrue()
                ->and(samplingGraphNode($view, "page:{$resource}/sampling-graph-boxes/index")['editable'])->toBeTrue()
                ->and(samplingGraphNode($graph['resources']['Product'], 'page:Product/products/index')['editable'])->toBeFalse();
        });

        it('hands the screen the built entries a sync from the code would rewrite, leaving a replacement to finish on its own', function () {
            $graph = samplingGraph();

            expect($graph['outOfStep'][SAMPLING_GRAPH_CONTEXT])->toBe([])
                ->and($graph['resourceOutOfStep'][SAMPLING_GRAPH_RESOURCE])->toBe([])
                ->and(samplingGraphNode($graph['contexts'][SAMPLING_GRAPH_CONTEXT], 'useCase:'.SAMPLING_GRAPH_CONTEXT.'/ShipBox')['status'])->toBe(StructureGraph::DIFFERS);
        });

        it('draws a replacement beside what it replaces, waiting on the swap', function () {
            $view = samplingGraph()['contexts'][SAMPLING_GRAPH_CONTEXT];
            $domain = SAMPLING_GRAPH_CONTEXT;

            expect(samplingGraphNode($view, "useCase:{$domain}/ShipBoxSafely"))->toMatchArray([
                'items' => ['command', 'returns int', 'replaces ShipBox'],
                'status' => StructurePlanner::READY,
                'command' => "php artisan make:use-case ShipBoxSafely --domain={$domain} --command",
            ])
                ->and(samplingGraphEdgesFrom($view, "useCase:{$domain}/ShipBoxSafely"))->toBe([["useCase:{$domain}/ShipBoxSafely", "useCase:{$domain}/ShipBox", 'replaces']])
                ->and(samplingGraphNode($view, "port:{$domain}/Gauge"))->toMatchArray([
                    'items' => ['domain', "adapter Infra/{$domain}/LaserGauge", "replacing adapter Infra/{$domain}/DialGauge"],
                    'status' => StructurePlanner::READY,
                    'command' => "php artisan make:port Gauge --domain={$domain} --adapter=Laser --infra={$domain}",
                ]);
        });

        it('draws a domain service that replaces another beside it', function () {
            $domain = SAMPLING_GRAPH_CONTEXT;
            $files = new StructureFiles(base_path());
            $files->write([...$files->read($domain), 'services' => [
                'PackBox' => ['shape' => 'plain', 'creates' => null, 'repositories' => []],
                'PackBoxTightly' => ['shape' => 'plain', 'creates' => null, 'repositories' => [], 'replaces' => 'PackBox'],
            ]]);
            $view = samplingGraph()['contexts'][$domain];

            expect(samplingGraphNode($view, "service:{$domain}/PackBoxTightly")['items'])->toBe(['plain', 'replaces PackBox'])
                ->and(samplingGraphEdgesFrom($view, "service:{$domain}/PackBoxTightly"))->toBe([["service:{$domain}/PackBoxTightly", "service:{$domain}/PackBox", 'replaces']]);
        });

        it('tells a list use case and each kind of page apart within their kind', function () {
            $graph = samplingGraph();
            $domain = SAMPLING_GRAPH_CONTEXT;

            expect(samplingGraphNode($graph['contexts'][$domain], "useCase:{$domain}/ShipBox")['variant'])->toBeNull()
                ->and(samplingGraphNode($graph['contexts']['Catalog'], 'useCase:Catalog/ListProducts')['variant'])->toBe('query')
                ->and(samplingGraphNode($graph['resources'][SAMPLING_GRAPH_RESOURCE], 'page:'.SAMPLING_GRAPH_RESOURCE.'/sampling-graph-boxes/index')['variant'])->toBe('table')
                ->and(samplingGraphNode($graph['resources']['Product'], 'page:Product/products/create')['variant'])->toBe('form')
                ->and(samplingGraphNode($graph['overview'], "context:{$domain}")['variant'])->toBeNull();
        });

        it('draws each enum and value object, tied to its aggregate and to the classes its fields name, and a status by where each case may go', function () {
            $context = SAMPLING_GRAPH_CONTEXT;
            File::ensureDirectoryExists(app_path("Domain/{$context}/Box/Enums"));
            File::put(app_path("Domain/{$context}/Box/Enums/BoxSide.php"), "<?php\n\nnamespace App\\Domain\\{$context}\\Box\\Enums;\n\nenum BoxSide\n{\n    case Left;\n    case Right;\n}\n");

            $files = new StructureFiles(base_path());
            $files->write([...$files->read($context),
                'enums' => [
                    'BoxGrade' => ['aggregate' => 'Box', 'backing' => 'string', 'cases' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd', 'E' => 'e', 'F' => 'f', 'G' => 'g']],
                    'BoxSide' => ['aggregate' => 'Box', 'backing' => null, 'cases' => ['Left' => null, 'Right' => null]],
                    'BoxStatus' => ['aggregate' => 'Box', 'backing' => 'string', 'cases' => ['Open' => 'open', 'Shut' => 'shut', 'Sent' => 'sent'], 'transitions' => ['Open' => ['Shut', 'Sent'], 'Shut' => ['Open'], 'Sent' => []]],
                ],
                'valueObjects' => [
                    'BoxLabel' => ['aggregate' => 'Box', 'fields' => ['grade' => 'BoxGrade', 'side' => '?BoxSide', 'price' => 'Shared/Money', 'lid' => 'LidEntity', 'at' => 'DateTimeImmutable', 'size' => 'string|int']],
                ],
            ]);

            $view = samplingGraph()['contexts'][$context];

            expect(samplingGraphNode($view, "enum:{$context}/BoxGrade"))->toMatchArray([
                'kind' => 'enum',
                'items' => ['string', 'A = a', 'B = b', 'C = c', 'D = d', '… 3 more'],
                'status' => StructurePlanner::READY,
                'editable' => true,
            ])
                ->and(samplingGraphNode($view, "enum:{$context}/BoxGrade")['variant'])->toBeNull()
                ->and(samplingGraphNode($view, "enum:{$context}/BoxSide"))->toMatchArray(['items' => ['pure', 'Left', 'Right'], 'status' => StructurePlanner::DONE, 'editable' => false])
                ->and(samplingGraphNode($view, "enum:{$context}/BoxStatus"))->toMatchArray([
                    'kind' => 'enum',
                    'variant' => 'status',
                    'items' => ['string', 'Open → Shut, Sent', 'Shut → Open', 'Sent · final'],
                ])
                ->and(samplingGraphNode($view, "valueObject:{$context}/BoxLabel"))->toMatchArray([
                    'kind' => 'valueObject',
                    'items' => ['grade: BoxGrade', 'side: ?BoxSide', 'price: Shared/Money', 'lid: LidEntity', 'at: DateTimeImmutable', 'size: string|int'],
                    'status' => StructurePlanner::WAITING,
                ])
                ->and(samplingGraphNode($view, 'external:Shared/Money'))->toMatchArray(['label' => 'Shared / Money', 'target' => ['view' => 'context', 'name' => 'Shared']])
                ->and(array_values(array_filter(samplingGraphEdgesFrom($view, "aggregate:{$context}/Box"), fn (array $edge): bool => $edge[2] === 'vocabulary')))->toBe([
                    ["aggregate:{$context}/Box", "enum:{$context}/BoxGrade", 'vocabulary'],
                    ["aggregate:{$context}/Box", "enum:{$context}/BoxSide", 'vocabulary'],
                    ["aggregate:{$context}/Box", "enum:{$context}/BoxStatus", 'vocabulary'],
                    ["aggregate:{$context}/Box", "valueObject:{$context}/BoxLabel", 'vocabulary'],
                ])
                ->and(samplingGraphEdgesFrom($view, "valueObject:{$context}/BoxLabel"))->toBe([
                    ["valueObject:{$context}/BoxLabel", "enum:{$context}/BoxGrade", 'grade'],
                    ["valueObject:{$context}/BoxLabel", "enum:{$context}/BoxSide", 'side'],
                    ["valueObject:{$context}/BoxLabel", 'external:Shared/Money', 'price'],
                    ["valueObject:{$context}/BoxLabel", "aggregate:{$context}/Lid", 'lid'],
                ]);
        });

        it('draws each entity that lists methods, tied to its aggregate and to the classes its parameters name', function () {
            $context = SAMPLING_GRAPH_CONTEXT;
            File::ensureDirectoryExists(app_path("Domain/{$context}/Box/Entities"));
            File::put(app_path("Domain/{$context}/Box/Entities/HingeEntity.php"), "<?php\n\nnamespace App\\Domain\\{$context}\\Box\\Entities;\n\nuse App\\Domain\\Shared\\DomainEntity;\n\nfinal class HingeEntity extends DomainEntity\n{\n    public function id(): string { return 'hinge'; }\n\n    public static function entityName(): string { return 'Hinge'; }\n\n    public function swing(): void {}\n}\n");

            $none = ['params' => [], 'throws' => []];
            $files = new StructureFiles(base_path());
            $manifest = $files->read($context);
            $files->write([...$manifest,
                'aggregates' => [...$manifest['aggregates'], 'Box' => ['children' => ['Hinge'], 'repository' => false]],
                'entities' => [
                    'Box' => ['aggregate' => 'Box', 'behaviours' => [
                        'close' => $none,
                        'open' => $none,
                        'pack' => ['params' => ['size' => 'string', 'lid' => 'LidEntity', 'price' => 'Shared/Money', 'tags' => '...string'], 'throws' => ['BoxFullException']],
                        'stack' => $none,
                        'tip' => $none,
                    ], 'assertions' => ['assertIsOpen' => $none, 'assertIsShut' => $none]],
                    'Hinge' => ['aggregate' => 'Box', 'behaviours' => ['swing' => $none], 'assertions' => []],
                ],
            ]);

            $graph = samplingGraph();
            $view = $graph['contexts'][$context];

            expect(samplingGraphNode($view, "entity:{$context}/Box"))->toMatchArray([
                'kind' => 'entity',
                'items' => ['close()', 'open()', 'pack(string, LidEntity, Shared/Money, ...string)', 'stack()', 'tip()', '… 2 more'],
                'status' => StructurePlanner::READY,
                'editable' => false,
            ])
                ->and(samplingGraphNode($view, "entity:{$context}/Hinge"))->toMatchArray(['items' => ['swing()'], 'status' => StructurePlanner::DONE])
                ->and(samplingGraphNode($view, "aggregate:{$context}/Box")['status'])->toBe(StructurePlanner::DONE)
                ->and(array_values(array_filter(samplingGraphEdgesFrom($view, "aggregate:{$context}/Box"), fn (array $edge): bool => in_array($edge[2], ['root', 'child'], true))))->toBe([
                    ["aggregate:{$context}/Box", "entity:{$context}/Box", 'root'],
                    ["aggregate:{$context}/Box", "entity:{$context}/Hinge", 'child'],
                ])
                ->and(samplingGraphEdgesFrom($view, "entity:{$context}/Box"))->toBe([
                    ["entity:{$context}/Box", "aggregate:{$context}/Lid", 'pack'],
                    ["entity:{$context}/Box", 'external:Shared/Money', 'pack'],
                ])
                ->and($graph['entityMethodsBuilt'][$context])->toBe(['Hinge.swing'])
                ->and($graph['childrenBuilt'][$context])->toBe(['Box.Hinge']);
        });

        it('draws a value object\'s methods under its fields, tied to the classes its parameters name', function () {
            $context = SAMPLING_GRAPH_CONTEXT;
            File::ensureDirectoryExists(app_path("Domain/{$context}/Box/ValueObjects"));
            File::put(app_path("Domain/{$context}/Box/ValueObjects/BoxTag.php"), "<?php\n\nnamespace App\\Domain\\{$context}\\Box\\ValueObjects;\n\nfinal class BoxTag\n{\n    public function __construct(private string \$code) {}\n\n    public function widen(): self { return \$this; }\n}\n");

            $none = ['params' => [], 'throws' => []];
            $files = new StructureFiles(base_path());
            $files->write([...$files->read($context), 'valueObjects' => [
                'BoxTag' => ['aggregate' => 'Box', 'fields' => ['code' => 'string'], 'behaviours' => [
                    'widen' => $none,
                    'grow' => ['params' => ['by' => 'int', 'lid' => 'LidEntity'], 'throws' => []],
                ], 'assertions' => ['assertNarrow' => $none]],
            ]]);

            $graph = samplingGraph();
            $view = $graph['contexts'][$context];

            expect(samplingGraphNode($view, "valueObject:{$context}/BoxTag"))->toMatchArray([
                'kind' => 'valueObject',
                'items' => ['code: string', 'grow(int, LidEntity)', 'widen()', 'assertNarrow()'],
                'status' => StructurePlanner::READY,
                'editable' => false,
            ])
                ->and(samplingGraphEdgesFrom($view, "valueObject:{$context}/BoxTag"))->toBe([
                    ["valueObject:{$context}/BoxTag", "aggregate:{$context}/Lid", 'grow'],
                ])
                ->and($graph['valueObjectMethodsBuilt'][$context])->toBe(['BoxTag.widen'])
                ->and($graph['entityMethodsBuilt'][$context])->toBe([]);
        });

        it('draws an entity\'s state above its methods, tied to the classes each property names', function () {
            $context = SAMPLING_GRAPH_CONTEXT;
            $files = new StructureFiles(base_path());
            $manifest = $files->read($context);
            $files->write([...$manifest,
                'aggregates' => [...$manifest['aggregates'], 'Box' => ['children' => [], 'repository' => false]],
                'enums' => ['BoxGrade' => ['aggregate' => 'Box', 'backing' => 'string', 'cases' => ['A' => 'a']]],
                'entities' => [
                    'Box' => ['aggregate' => 'Box', 'state' => ['grade' => 'BoxGrade', 'price' => '?Shared/Money', 'count' => 'int'], 'behaviours' => ['tip' => ['params' => [], 'throws' => []]], 'assertions' => []],
                ],
            ]);

            $graph = samplingGraph();
            $view = $graph['contexts'][$context];

            expect(samplingGraphNode($view, "entity:{$context}/Box")['items'])->toBe(['grade: BoxGrade', 'price: ?Shared/Money', 'count: int', 'tip()'])
                ->and(samplingGraphEdgesFrom($view, "entity:{$context}/Box"))->toBe([
                    ["entity:{$context}/Box", "enum:{$context}/BoxGrade", 'state'],
                    ["entity:{$context}/Box", 'external:Shared/Money', 'state'],
                ])
                ->and($graph['entityStateBuilt'][$context])->toBe([]);
        });

        it('draws the root and every child of an aggregate as an entity card, before either lists a method', function () {
            $context = SAMPLING_GRAPH_CONTEXT;
            $files = new StructureFiles(base_path());
            $manifest = $files->read($context);
            $files->write([...$manifest, 'aggregates' => [...$manifest['aggregates'], 'Box' => ['children' => ['Flap'], 'repository' => false]]]);

            $graph = samplingGraph();
            $view = $graph['contexts'][$context];

            expect(samplingGraphNode($view, "entity:{$context}/Box"))->toMatchArray(['items' => [StructureGraph::NOTHING_DESIGNED], 'status' => StructurePlanner::DONE, 'editable' => false])
                ->and(samplingGraphNode($view, "entity:{$context}/Flap"))->toMatchArray([
                    'items' => [StructureGraph::NOTHING_DESIGNED],
                    'status' => StructurePlanner::READY,
                    'command' => "php artisan make:entity Flap --domain={$context}/Box --child",
                    'editable' => true,
                ])
                ->and(samplingGraphNode($view, "entity:{$context}/Lid"))->toMatchArray(['status' => StructurePlanner::READY, 'editable' => false])
                ->and(array_values(array_filter(samplingGraphEdgesFrom($view, "aggregate:{$context}/Box"), fn (array $edge): bool => in_array($edge[2], ['root', 'child'], true))))->toBe([
                    ["aggregate:{$context}/Box", "entity:{$context}/Box", 'root'],
                    ["aggregate:{$context}/Box", "entity:{$context}/Flap", 'child'],
                ]);
        });

        it('draws each exception tied to what refuses with it, and to each method that throws it', function () {
            $context = SAMPLING_GRAPH_CONTEXT;
            $files = new StructureFiles(base_path());
            $manifest = $files->read($context);
            $files->write([...$manifest,
                'exceptions' => [
                    'BoxFullException' => ['kind' => 'refusal', 'aggregate' => 'Box', 'useCase' => null],
                    'BoxWetException' => ['kind' => 'value', 'aggregate' => 'Box', 'useCase' => null],
                    'BoxLateException' => ['kind' => 'application', 'aggregate' => null, 'useCase' => 'ShipBox'],
                ],
                'entities' => [
                    'Box' => ['aggregate' => 'Box', 'behaviours' => ['pack' => ['params' => [], 'throws' => ['BoxFullException', 'Shared/InvalidMoneyException', 'BoxGoneException']]], 'assertions' => []],
                ],
            ]);

            $view = samplingGraph()['contexts'][$context];

            expect(samplingGraphNode($view, "exception:{$context}/BoxFullException"))->toMatchArray([
                'kind' => 'exception',
                'variant' => 'refusal',
                'items' => ['refusal', 'in Box'],
                'status' => StructurePlanner::READY,
                'editable' => true,
            ])
                ->and(samplingGraphNode($view, "exception:{$context}/BoxWetException")['items'])->toBe(['invalid value', 'in Box'])
                ->and(samplingGraphNode($view, "exception:{$context}/BoxLateException")['items'])->toBe(['use case refusal', 'of ShipBox'])
                ->and(array_values(array_filter(samplingGraphEdgesFrom($view, "aggregate:{$context}/Box"), fn (array $edge): bool => in_array($edge[2], ['refuses', 'rejects'], true))))->toBe([
                    ["aggregate:{$context}/Box", "exception:{$context}/BoxFullException", 'refuses'],
                    ["aggregate:{$context}/Box", "exception:{$context}/BoxWetException", 'rejects'],
                ])
                ->and(samplingGraphEdgesFrom($view, "useCase:{$context}/ShipBox"))->toContain(["useCase:{$context}/ShipBox", "exception:{$context}/BoxLateException", 'refuses'])
                ->and(samplingGraphEdgesFrom($view, "entity:{$context}/Box"))->toBe([
                    ["entity:{$context}/Box", "exception:{$context}/BoxFullException", 'pack'],
                ]);
        });

        it('draws the shared kernel as its enums and value objects alone', function () {
            $shared = samplingGraph()['contexts']['Shared'];
            $kinds = array_unique(array_column($shared['nodes'], 'kind'));
            sort($kinds);

            expect($kinds)->toBe(['enum', 'valueObject'])
                ->and(samplingGraphNode($shared, 'enum:Shared/CurrencyCode')['editable'])->toBeFalse();
        });

        it('lists what the code holds and the manifest does not as work by hand', function () {
            $subjects = array_column(samplingGraph()['byHand'], 'subject');

            expect($subjects)->toContain('App\\Application\\'.SAMPLING_GRAPH_CONTEXT.'\\UseCases\\TidyBoxesHandler');
        });
    });
});
