<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureEditor;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch contexts of this file. They carry `Sampling`, so no Architecture check reads them,
 * and differ from every other test file's. SamplingEdit has a built aggregate and use case beside
 * ones only designed; SamplingEditOther reaches into it, so a change there has a user elsewhere.
 */
const SAMPLING_EDIT_CONTEXT = 'SamplingEdit';

const SAMPLING_EDIT_OTHER = 'SamplingEditOther';

const SAMPLING_EDIT_NEW = 'SamplingEditNew';

const SAMPLING_EDIT_DESK = 'SamplingEditDesk';

function samplingEditor(): StructureEditor
{
    return new StructureEditor(new StructureFiles(base_path()), new StructureReader(base_path()));
}

/**
 * @return array<string, mixed>
 */
function samplingEditManifest(string $context = SAMPLING_EDIT_CONTEXT): array
{
    return json_decode(File::get(base_path(".kit/structure/{$context}.json")), true);
}

function samplingEditVersion(string $context = SAMPLING_EDIT_CONTEXT): string
{
    return (new StructureFiles(base_path()))->version($context);
}

function writeSamplingEditFixtures(): void
{
    $context = SAMPLING_EDIT_CONTEXT;
    $fixtures = [
        "Domain/{$context}/Crate/CrateEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class CrateEntity extends AggregateRoot\n{\n    private function __construct(private string \$id, private int \$weight) {}\n\n    public function id(): string { return \$this->id; }\n\n    public function weight(): int { return \$this->weight; }\n\n    public static function entityName(): string { return 'Crate'; }\n\n    public function seal(): void {}\n}\n",
        "Application/{$context}/UseCases/ShipCrateHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases;\n\nfinal class ShipCrateHandler\n{\n    public function __invoke(string \$crateId): void {}\n}\n",
        "Application/{$context}/UseCases/ListCrates/ListCratesHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\ListCrates;\n\nfinal class ListCratesHandler\n{\n    public function __invoke(): void {}\n}\n",
        "Application/{$context}/UseCases/ListCrates/ListCratesQuery.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\ListCrates;\n\ninterface ListCratesQuery {}\n",
        "Domain/{$context}/Ports/Scale.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Ports;\n\ninterface Scale {}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(app_path($relative)));
        File::put(app_path($relative), $contents);
    }

    $files = new StructureFiles(base_path());
    $files->write([
        'context' => $context,
        'aggregates' => [
            'Crate' => ['children' => [], 'repository' => true],
            'Pallet' => ['children' => [], 'repository' => true],
        ],
        'services' => [],
        'ports' => ['Scale' => ['layer' => 'domain', 'adapter' => "Infra/{$context}/DigitalScale"]],
        'useCases' => [
            'ListCrates' => ['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => true, 'repositories' => []],
            'LoadPallet' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => ['Pallet']],
            'ShipCrate' => ['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => []],
        ],
    ]);
    $files->write([
        'context' => SAMPLING_EDIT_OTHER,
        'aggregates' => ['Bin' => ['children' => [], 'repository' => true]],
        'services' => [],
        'ports' => [],
        'useCases' => [
            'FillBin' => ['shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => ['Bin', "{$context}/Pallet"]],
        ],
    ]);
    $files->writeResource([
        'resource' => SAMPLING_EDIT_DESK,
        'model' => null,
        'controller' => ['destroy' => ["{$context}/ShipCrate"]],
        'actions' => ['Ship' => ['row' => true, 'bulk' => false, 'useCases' => ["{$context}/ShipCrate"]]],
        'policy' => null,
        'pages' => [],
    ]);
}

function forgetSamplingEdit(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_EDIT_CONTEXT));
    File::deleteDirectory(app_path('Application/'.SAMPLING_EDIT_CONTEXT));
    File::delete([
        ...array_map(fn (string $context): string => base_path(".kit/structure/{$context}.json"), [SAMPLING_EDIT_CONTEXT, SAMPLING_EDIT_OTHER, SAMPLING_EDIT_NEW]),
        base_path('.kit/structure/http/'.SAMPLING_EDIT_DESK.'.json'),
    ]);
}

/**
 * Designs Lorry, an aggregate the code does not have, with the child Wheel, and methods for both.
 */
function samplingEditLorry(): void
{
    $method = ['params' => [], 'throws' => []];

    (new StructureFiles(base_path()))->write([...samplingEditManifest(),
        'aggregates' => [...samplingEditManifest()['aggregates'], 'Lorry' => ['children' => ['Wheel'], 'repository' => false]],
        'entities' => [
            'Lorry' => ['aggregate' => 'Lorry', 'behaviours' => ['load' => $method, 'unhitch' => $method], 'assertions' => []],
            'Wheel' => ['aggregate' => 'Lorry', 'behaviours' => ['turn' => $method], 'assertions' => []],
        ],
    ]);
}

beforeEach(function () {
    forgetSamplingEdit();
    writeSamplingEditFixtures();
});

afterEach(fn () => forgetSamplingEdit());

describe('StructureEditor', function () {
    describe('createContext', function () {
        it('writes the empty manifest of a new context', function () {
            expect(samplingEditor()->createContext(SAMPLING_EDIT_NEW))->toBe([])
                ->and(samplingEditManifest(SAMPLING_EDIT_NEW))->toBe(['context' => SAMPLING_EDIT_NEW, 'aggregates' => [], 'services' => [], 'ports' => [], 'useCases' => [], 'enums' => [], 'valueObjects' => [], 'exceptions' => [], 'entities' => []]);
        });

        it('refuses a name that is not a new context of the project', function (string $name, string $message) {
            expect(samplingEditor()->createContext($name))->toBe(['name' => [$message]]);
        })->with([
            'not StudlyCase' => ['samplingEditNew', 'A context name is StudlyCase.'],
            'one of the kit\'s' => ['Shared', "Shared is one of the kit's own contexts."],
            'already there' => [SAMPLING_EDIT_CONTEXT, SAMPLING_EDIT_CONTEXT.' already has a manifest.'],
        ]);
    });

    describe('savePiece', function () {
        it('adds a piece in the manifest\'s canonical shape', function () {
            $errors = samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', null, 'PackPallet', [
                'shape' => 'command',
                'returns' => 'int',
                'repositories' => [SAMPLING_EDIT_OTHER.'/Bin', 'Pallet', 'Pallet'],
            ]);

            expect($errors)->toBe([])
                ->and(samplingEditManifest()['useCases']['PackPallet'])->toBe([
                    'shape' => 'command',
                    'returns' => 'int',
                    'creates' => false,
                    'query' => false,
                    'repositories' => ['Pallet', SAMPLING_EDIT_OTHER.'/Bin'],
                ]);
        });

        it('keeps the enums and value objects, in their order, when it saves another piece', function () {
            $vocabulary = [
                'enums' => ['CrateGrade' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Top' => 'top', 'Low' => 'low'], 'transitions' => null]],
                'valueObjects' => ['CrateLabel' => ['aggregate' => 'Crate', 'fields' => ['grade' => 'CrateGrade', 'note' => '?string']]],
            ];
            (new StructureFiles(base_path()))->write([...samplingEditManifest(), ...$vocabulary]);

            expect(samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', null, 'Lorry', ['children' => [], 'repository' => false]))->toBe([])
                ->and(array_intersect_key(samplingEditManifest(), $vocabulary))->toBe($vocabulary);
        });

        it('changes and renames a piece the code does not have yet', function () {
            $errors = samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'LoadPallet', 'StackPallet', [
                'shape' => 'command',
                'returns' => 'string',
                'creates' => true,
                'repositories' => ['Pallet'],
            ]);

            expect($errors)->toBe([])
                ->and(samplingEditManifest()['useCases'])->not->toHaveKey('LoadPallet')
                ->and(samplingEditManifest()['useCases']['StackPallet'])->toMatchArray(['returns' => 'string', 'creates' => true]);
        });

        it('refuses a piece that breaks a rule, under the field that holds it, and writes nothing', function (string $section, string $name, array $entry, string $field) {
            $before = samplingEditVersion();

            expect(samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, $before, $section, null, $name, $entry))->toHaveKey($field)
                ->and(samplingEditVersion())->toBe($before);
        })->with([
            'a name not in StudlyCase' => ['useCases', 'packPallet', ['shape' => 'plain', 'returns' => 'void'], 'name'],
            'a name already in the manifest' => ['useCases', 'LoadPallet', ['shape' => 'plain', 'returns' => 'void'], 'name'],
            'a shape the schema does not know' => ['useCases', 'PackPallet', ['shape' => 'sideways', 'returns' => 'void'], 'shape'],
            'a child not in StudlyCase' => ['aggregates', 'Lid', ['children' => ['lid-flap']], 'children'],
            'a creates-shaped service that builds nothing' => ['services', 'PackCrate', ['shape' => 'creates'], 'creates'],
            'a plain service that names what it builds' => ['services', 'PackCrate', ['shape' => 'plain', 'creates' => 'Crate'], 'creates'],
            'a service reaching another context' => ['services', 'PackCrate', ['shape' => 'plain', 'repositories' => [SAMPLING_EDIT_OTHER.'/Bin']], 'repositories'],
            'a service injecting no repository there is' => ['services', 'PackCrate', ['shape' => 'plain', 'repositories' => ['Lid']], 'repositories'],
            'an adapter outside Infra' => ['ports', 'Balance', ['layer' => 'domain', 'adapter' => 'Scales/DigitalBalance'], 'adapter'],
            'a Command and Result use case returning void' => ['useCases', 'PackPallet', ['shape' => 'command-result', 'returns' => 'void'], 'returns'],
            'a Command use case returning a Result' => ['useCases', 'PackPallet', ['shape' => 'command', 'returns' => 'result'], 'returns'],
            'a query not named List' => ['useCases', 'PackPallet', ['shape' => 'command-result', 'returns' => 'result', 'query' => true], 'query'],
            'a list that injects a repository' => ['useCases', 'ListPallets', ['shape' => 'command-result', 'returns' => 'result', 'query' => true, 'repositories' => ['Pallet']], 'repositories'],
            'a repository another context lacks' => ['useCases', 'PackPallet', ['shape' => 'plain', 'returns' => 'void', 'repositories' => [SAMPLING_EDIT_OTHER.'/Lid']], 'repositories'],
        ]);

        it('adds an enum, its int cases read from the strings a form posts, and a value object that names it before it is built', function () {
            $editor = samplingEditor();

            expect($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'enums', null, 'CrateSize', [
                'aggregate' => 'Crate',
                'backing' => 'int',
                'cases' => ['Half' => '50', 'Full' => '100'],
            ]))->toBe([])
                ->and($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'valueObjects', null, 'CrateLabel', [
                    'aggregate' => 'Crate',
                    'fields' => ['size' => 'CrateSize', 'price' => '?Shared/Money', 'at' => 'DateTimeImmutable', 'pallet' => SAMPLING_EDIT_CONTEXT.'/Pallet/PalletEntity', 'code' => 'string|int'],
                ]))->toBe([])
                ->and(samplingEditManifest()['enums']['CrateSize'])->toBe(['aggregate' => 'Crate', 'backing' => 'int', 'cases' => ['Half' => 50, 'Full' => 100], 'transitions' => null])
                ->and(array_keys(samplingEditManifest()['valueObjects']['CrateLabel']['fields']))->toBe(['size', 'price', 'at', 'pallet', 'code']);
        });

        it('adds a status, its moves filled in for every case and put in the order of its cases', function () {
            expect(samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'enums', null, 'CrateStatus', [
                'aggregate' => 'Crate',
                'backing' => 'string',
                'cases' => ['Open' => 'open', 'Sealed' => 'sealed', 'Shipped' => 'shipped'],
                'transitions' => ['Sealed' => ['Shipped'], 'Open' => ['Shipped', 'Sealed']],
            ]))->toBe([])
                ->and(samplingEditManifest()['enums']['CrateStatus']['transitions'])->toBe([
                    'Open' => ['Sealed', 'Shipped'],
                    'Sealed' => ['Shipped'],
                    'Shipped' => [],
                ]);
        });

        it('refuses a status whose moves break states.md, under its moves', function (array $transitions, string $message) {
            $before = samplingEditVersion();

            expect(samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, $before, 'enums', null, 'CrateStatus', [
                'aggregate' => 'Crate',
                'backing' => null,
                'cases' => ['Open' => null, 'Shut' => null],
                'transitions' => $transitions,
            ]))->toBe(['transitions' => [$message]])
                ->and(samplingEditVersion())->toBe($before);
        })->with([
            'nothing moves' => [['Open' => [], 'Shut' => []], 'A status lets at least one case become another; an enum whose cases never change declares no transitions.'],
            'a move to no case' => [['Open' => ['Lost']], '"transitions" lets Open become Lost, which is not one of its cases.'],
            'a case that becomes itself' => [['Open' => ['Open', 'Shut']], '"transitions" lets Open become itself — staying put is no change.'],
        ]);

        it('refuses an enum or a value object that breaks a rule, under the field that holds it', function (string $section, array $entry, string $field) {
            $before = samplingEditVersion();

            expect(samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, $before, $section, null, 'CrateThing', $entry))->toHaveKey($field)
                ->and(samplingEditVersion())->toBe($before);
        })->with([
            'an aggregate the context lacks' => ['enums', ['aggregate' => 'Lorry', 'backing' => null, 'cases' => ['Left' => null]], 'aggregate'],
            'no aggregate outside the shared kernel' => ['enums', ['aggregate' => null, 'backing' => null, 'cases' => []], 'aggregate'],
            'a case not in TitleCase' => ['enums', ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['top' => 'top']], 'cases'],
            'two cases with one value' => ['enums', ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Top' => 'x', 'Low' => 'x']], 'cases'],
            'a case that does not fit the backing' => ['enums', ['aggregate' => 'Crate', 'backing' => 'int', 'cases' => ['Top' => 'top']], 'cases'],
            'moves on an enum not named Status' => ['enums', ['aggregate' => 'Crate', 'backing' => null, 'cases' => ['Open' => null, 'Shut' => null], 'transitions' => ['Open' => ['Shut']]], 'transitions'],
            'a field not in camelCase' => ['valueObjects', ['aggregate' => 'Crate', 'fields' => ['Size' => 'int']], 'fields'],
            'a type no manifest lists' => ['valueObjects', ['aggregate' => 'Crate', 'fields' => ['size' => 'CrateColour']], 'fields'],
            'an entity of another aggregate named bare' => ['valueObjects', ['aggregate' => 'Crate', 'fields' => ['pallet' => 'PalletEntity']], 'fields'],
            'a type of an aggregate that has no such class' => ['valueObjects', ['aggregate' => 'Crate', 'fields' => ['size' => SAMPLING_EDIT_OTHER.'/Bin/BinSize']], 'fields'],
        ]);

        it('lets the shared kernel hold only enums and value objects', function () {
            $before = (new StructureFiles(base_path()))->version('Shared');

            expect(samplingEditor()->savePiece('Shared', $before, 'aggregates', null, 'SamplingEditCrate', ['children' => [], 'repository' => false]))
                ->toBe(['section' => ['The shared kernel lists only enums, value objects and invalid values.']])
                ->and((new StructureFiles(base_path()))->version('Shared'))->toBe($before);
        });

        it('keeps the name of an enum a value object names, and of an aggregate that holds an enum', function () {
            $editor = samplingEditor();
            $editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'enums', null, 'PalletKind', ['aggregate' => 'Pallet', 'backing' => null, 'cases' => ['Wood' => null]]);
            $editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'valueObjects', null, 'PalletTag', ['aggregate' => 'Pallet', 'fields' => ['kind' => 'PalletKind']]);

            expect($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'enums', 'PalletKind', 'PalletSort', ['aggregate' => 'Pallet', 'backing' => null, 'cases' => ['Wood' => null]]))
                ->toBe(['name' => ['PalletKind is used by '.SAMPLING_EDIT_CONTEXT.'/PalletTag, so it keeps its name.']])
                ->and($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'enums', 'PalletKind'))
                ->toBe(['name' => ['PalletKind is used by '.SAMPLING_EDIT_CONTEXT.'/PalletTag.']])
                ->and($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Pallet')['name'][0])
                ->toContain(SAMPLING_EDIT_CONTEXT.'/PalletKind', SAMPLING_EDIT_CONTEXT.'/PalletTag')
                ->and($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'valueObjects', 'PalletTag'))->toBe([])
                ->and($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'enums', 'PalletKind'))->toBe([]);
        });

        it('keeps an aggregate and a child whose methods the manifest lists, and changes the methods only one at a time', function () {
            $method = ['params' => [], 'throws' => []];
            (new StructureFiles(base_path()))->write([...samplingEditManifest(),
                'aggregates' => [...samplingEditManifest()['aggregates'], 'Lorry' => ['children' => ['Wheel'], 'repository' => false]],
                'entities' => [
                    'Lorry' => ['aggregate' => 'Lorry', 'behaviours' => ['load' => $method], 'assertions' => []],
                    'Wheel' => ['aggregate' => 'Lorry', 'behaviours' => ['turn' => $method], 'assertions' => []],
                ],
            ]);
            $editor = samplingEditor();

            expect($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Lorry', 'Lorry', ['children' => [], 'repository' => false]))
                ->toBe(['children' => ['Wheel lists its methods under entities, so it stays a child.']])
                ->and($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Lorry'))
                ->toBe(['name' => ['Lorry is used by '.SAMPLING_EDIT_CONTEXT.'/Lorry, '.SAMPLING_EDIT_CONTEXT.'/Wheel.']])
                ->and($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'entities', null, 'Lorry', ['aggregate' => 'Lorry']))
                ->toBe(['section' => ["An entity's methods change one at a time."]])
                ->and(samplingEditManifest()['entities'])->toHaveKeys(['Lorry', 'Wheel']);
        });

        it('refuses a change to a manifest that changed since the page loaded', function () {
            expect(samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, 'stale', 'aggregates', null, 'Lid', []))->toHaveKey('version');
        });

        it('leaves a piece the code already has alone', function () {
            $errors = samplingEditor()->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate', 'ShipCrate', ['shape' => 'plain', 'returns' => 'int']);

            expect($errors['name'][0])->toContain('The code already has ShipCrate');
        });

        it('keeps an aggregate\'s name and repository while something injects it', function () {
            $editor = samplingEditor();

            expect($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Pallet', 'Skid', ['repository' => true]))->toHaveKey('name')
                ->and($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Pallet', 'Pallet', ['repository' => false]))->toHaveKey('repository');
        });
    });

    describe('saveMethod', function () {
        it('adds a method to a built entity, grouped by its name, with its parameters in order and its exceptions sorted', function () {
            $editor = samplingEditor();

            expect($editor->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'stack', ['height' => 'int', 'labels' => '...string'], ['Shared/InvalidMoneyException', 'CrateFullException', 'CrateFullException']))->toBe([])
                ->and($editor->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'assertIsOpen', [], ['CrateSealedException']))->toBe([])
                ->and(samplingEditManifest()['entities'])->toBe(['Crate' => [
                    'aggregate' => 'Crate',
                    'state' => [],
                    'behaviours' => ['stack' => ['params' => ['height' => 'int', 'labels' => '...string'], 'throws' => ['CrateFullException', 'Shared/InvalidMoneyException']]],
                    'assertions' => ['assertIsOpen' => ['params' => [], 'throws' => ['CrateSealedException']]],
                ]]);
        });

        it('renames a designed method, into the other group when its name says so', function () {
            samplingEditLorry();

            expect(samplingEditor()->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Wheel', 'turn', 'assertTurns', ['speed' => 'int'], []))->toBe([])
                ->and(samplingEditManifest()['entities']['Wheel'])->toBe([
                    'aggregate' => 'Lorry',
                    'state' => [],
                    'behaviours' => [],
                    'assertions' => ['assertTurns' => ['params' => ['speed' => 'int'], 'throws' => []]],
                ]);
        });

        it('leaves a method the code already has alone', function () {
            $editor = samplingEditor();

            expect($editor->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'seal', [], [])['name'][0])->toContain('kit:import');

            (new StructureFiles(base_path()))->write([...samplingEditManifest(), 'entities' => [
                'Crate' => ['aggregate' => 'Crate', 'behaviours' => ['seal' => ['params' => [], 'throws' => []]], 'assertions' => []],
            ]]);

            expect($editor->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'seal', 'seal', ['force' => 'bool'], []))
                ->toBe(['name' => ['The code already has Crate::seal, so the screen leaves it alone.']]);
        });

        it('refuses a method the rules or the manifests do not allow', function (string $entity, ?string $previous, string $name, array $params, array $throws, string $field) {
            samplingEditLorry();
            $before = samplingEditManifest();

            expect(samplingEditor()->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), $entity, $previous, $name, $params, $throws))->toHaveKey($field)
                ->and(samplingEditManifest())->toBe($before);
        })->with([
            'an entity no aggregate holds' => ['Barrel', null, 'roll', [], [], 'entity'],
            'a method not in the manifest' => ['Lorry', 'park', 'park', [], [], 'name'],
            'a name not camelCase' => ['Lorry', null, 'Unload', [], [], 'name'],
            'a name the manifest has' => ['Lorry', null, 'load', [], [], 'name'],
            'a parameter not camelCase' => ['Lorry', null, 'unload', ['Weight' => 'int'], [], 'params'],
            'a parameter with no type' => ['Lorry', null, 'unload', ['weight' => ''], [], 'params'],
            'a variadic parameter before the last' => ['Lorry', null, 'unload', ['crates' => '...string', 'weight' => 'int'], [], 'params'],
            'a type no manifest designs' => ['Lorry', null, 'unload', ['weight' => 'Tonnage'], [], 'params'],
            'an exception not named one' => ['Lorry', null, 'unload', [], ['LorryEmpty'], 'throws'],
            'an exception elsewhere that is not built' => ['Lorry', null, 'unload', [], ['Shared/LorryEmptyException'], 'throws'],
        ]);

        it('refuses the shared kernel and a manifest that changed since the page loaded', function () {
            $editor = samplingEditor();

            expect($editor->saveMethod('Shared', (new StructureFiles(base_path()))->version('Shared'), 'Money', null, 'add', [], []))->toBe(['entity' => ['The shared kernel has no entities.']])
                ->and($editor->saveMethod(SAMPLING_EDIT_CONTEXT, 'stale', 'Crate', null, 'stack', [], []))->toHaveKey('version');
        });
    });

    describe('removeMethod', function () {
        it('removes a designed method, and the entity with its last one', function () {
            samplingEditLorry();
            $editor = samplingEditor();

            expect($editor->removeMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Lorry', 'load'))->toBe([])
                ->and(samplingEditManifest()['entities']['Lorry']['behaviours'])->toBe(['unhitch' => ['params' => [], 'throws' => []]])
                ->and($editor->removeMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Lorry', 'unhitch'))->toBe([])
                ->and(samplingEditManifest()['entities'])->toHaveKey('Wheel')->not->toHaveKey('Lorry');
        });

        it('keeps a method the code already has, and names one the manifest lacks', function () {
            (new StructureFiles(base_path()))->write([...samplingEditManifest(), 'entities' => [
                'Crate' => ['aggregate' => 'Crate', 'behaviours' => ['seal' => ['params' => [], 'throws' => []]], 'assertions' => []],
            ]]);
            $editor = samplingEditor();

            expect($editor->removeMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'seal'))->toBe(['name' => ['The code already has Crate::seal, so the screen leaves it alone.']])
                ->and($editor->removeMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'open'))->toBe(['name' => ['The manifest has no Crate::open.']])
                ->and(samplingEditManifest()['entities'])->toHaveKey('Crate');
        });
    });

    describe('saveState', function () {
        it('adds state to a built entity, each new property last, and renames one in its place', function () {
            $editor = samplingEditor();

            expect($editor->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'status', 'string'))->toBe([])
                ->and($editor->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'price', '?Shared/Money'))->toBe([])
                ->and($editor->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'status', 'grade', 'int|string'))->toBe([])
                ->and(samplingEditManifest()['entities']['Crate'])->toBe([
                    'aggregate' => 'Crate',
                    'state' => ['grade' => 'int|string', 'price' => '?Shared/Money'],
                    'behaviours' => [],
                    'assertions' => [],
                ]);
        });

        it('leaves state the code already has alone', function () {
            $editor = samplingEditor();

            expect($editor->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'weight', 'int')['name'][0])->toContain('kit:import');

            (new StructureFiles(base_path()))->write([...samplingEditManifest(), 'entities' => [
                'Crate' => ['aggregate' => 'Crate', 'state' => ['weight' => 'int'], 'behaviours' => [], 'assertions' => []],
            ]]);

            expect($editor->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'weight', 'weight', 'string'))
                ->toBe(['name' => ['The code already has Crate.weight, so the screen leaves it alone.']])
                ->and($editor->removeState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'weight'))
                ->toBe(['name' => ['The code already has Crate.weight, so the screen leaves it alone.']]);
        });

        it('refuses state the rules or the manifests do not allow', function (string $entity, ?string $previous, string $name, string $type, string $field) {
            samplingEditLorry();
            samplingEditor()->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Lorry', null, 'axles', 'int');
            $before = samplingEditManifest();

            expect(samplingEditor()->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), $entity, $previous, $name, $type))->toHaveKey($field)
                ->and(samplingEditManifest())->toBe($before);
        })->with([
            'an entity no aggregate holds' => ['Barrel', null, 'size', 'int', 'entity'],
            'a property not in the manifest' => ['Lorry', 'cab', 'cab', 'int', 'name'],
            'a name not camelCase' => ['Lorry', null, 'Cab', 'int', 'name'],
            'the id' => ['Lorry', null, 'id', 'string', 'name'],
            'a name the manifest has' => ['Lorry', null, 'axles', 'int', 'name'],
            'no type' => ['Lorry', null, 'cab', ' ', 'type'],
            'a type no manifest designs' => ['Lorry', null, 'cab', '?Tonnage', 'type'],
        ]);
    });

    describe('removeState', function () {
        it('removes designed state, and the entity once it lists nothing', function () {
            samplingEditLorry();
            $editor = samplingEditor();
            $editor->saveState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Lorry', null, 'axles', 'int');
            (new StructureFiles(base_path()))->write([...samplingEditManifest(), 'entities' => [
                ...samplingEditManifest()['entities'],
                'Wheel' => ['aggregate' => 'Lorry', 'state' => ['spokes' => 'int'], 'behaviours' => [], 'assertions' => []],
            ]]);

            expect($editor->removeState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Lorry', 'axles'))->toBe([])
                ->and(samplingEditManifest()['entities']['Lorry']['state'])->toBe([])
                ->and($editor->removeState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Wheel', 'spokes'))->toBe([])
                ->and(samplingEditManifest()['entities'])->toHaveKey('Lorry')->not->toHaveKey('Wheel')
                ->and($editor->removeState(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Lorry', 'cab'))->toBe(['name' => ['The manifest has no Lorry.cab.']]);
        });
    });

    describe('replace', function () {
        it('starts replacing a use case beside the old one and points every resource at it', function () {
            expect(samplingEditor()->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate', 'ShipCrateSafely'))->toBe([])
                ->and(samplingEditManifest()['useCases']['ShipCrateSafely'])->toBe(['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => [], 'replaces' => 'ShipCrate'])
                ->and(samplingEditManifest()['useCases'])->toHaveKey('ShipCrate')
                ->and(json_decode(File::get(base_path('.kit/structure/http/'.SAMPLING_EDIT_DESK.'.json')), true))->toMatchArray([
                    'controller' => ['destroy' => [SAMPLING_EDIT_CONTEXT.'/ShipCrateSafely']],
                    'actions' => ['Ship' => ['row' => true, 'bulk' => false, 'useCases' => [SAMPLING_EDIT_CONTEXT.'/ShipCrateSafely']]],
                ]);
        });

        it('starts replacing a list use case with one that lists something else', function () {
            expect(samplingEditor()->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ListCrates', 'ListOpenCrates'))->toBe([])
                ->and(samplingEditManifest()['useCases']['ListOpenCrates'])->toBe(['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => true, 'repositories' => [], 'replaces' => 'ListCrates'])
                ->and(samplingEditor()->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ListCrates', 'ListShutCrates')['name'][0])->toContain('being replaced by ListOpenCrates');
        });

        it('starts replacing a port\'s adapter', function () {
            expect(samplingEditor()->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'ports', 'Scale', 'Infra/'.SAMPLING_EDIT_CONTEXT.'/AnalogScale'))->toBe([])
                ->and(samplingEditManifest()['ports']['Scale'])->toBe(['layer' => 'domain', 'adapter' => 'Infra/'.SAMPLING_EDIT_CONTEXT.'/AnalogScale', 'replaces' => 'Infra/'.SAMPLING_EDIT_CONTEXT.'/DigitalScale']);
        });

        it('refuses what cannot be replaced, or a replacement that does not fit', function (string $section, string $name, string $replacement, string $field) {
            expect(samplingEditor()->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), $section, $name, $replacement))->toHaveKey($field);
        })->with([
            'a piece the code does not have yet' => ['useCases', 'LoadPallet', 'LoadPalletSafely', 'name'],
            'a list not named List{Name}' => ['useCases', 'ListCrates', 'CratesInStock', 'replacement'],
            'a list of what the old one lists' => ['useCases', 'ListCrates', 'ListCrate', 'replacement'],
            'a name not in StudlyCase' => ['useCases', 'ShipCrate', 'shipCrateSafely', 'replacement'],
            'a name the context has' => ['useCases', 'ShipCrate', 'LoadPallet', 'replacement'],
            'an adapter outside Infra' => ['ports', 'Scale', 'Scales/AnalogScale', 'replacement'],
            'the adapter it has' => ['ports', 'Scale', 'Infra/'.SAMPLING_EDIT_CONTEXT.'/DigitalScale', 'replacement'],
            'an aggregate' => ['aggregates', 'Crate', 'Box', 'section'],
        ]);

        it('refuses a second replacement of a piece already being replaced', function () {
            $editor = samplingEditor();
            $editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate', 'ShipCrateSafely');

            expect($editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate', 'ShipCrateFaster')['name'][0])->toContain('being replaced by ShipCrateSafely');
        });

        it('keeps what a replacement replaces while the new piece is changed', function () {
            $editor = samplingEditor();
            $editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate', 'ShipCrateSafely');

            expect($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrateSafely', 'ShipCrateSafely', ['shape' => 'plain', 'returns' => 'int', 'replaces' => null]))->toBe([])
                ->and(samplingEditManifest()['useCases']['ShipCrateSafely'])->toMatchArray(['returns' => 'int', 'replaces' => 'ShipCrate']);
        });
    });

    describe('cancelReplacement', function () {
        it('drops the new use case and points every resource back at the old one', function () {
            $editor = samplingEditor();
            $editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate', 'ShipCrateSafely');

            expect($editor->cancelReplacement(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrateSafely'))->toBe([])
                ->and(samplingEditManifest()['useCases'])->not->toHaveKey('ShipCrateSafely')
                ->and(json_decode(File::get(base_path('.kit/structure/http/'.SAMPLING_EDIT_DESK.'.json')), true)['controller'])->toBe(['destroy' => [SAMPLING_EDIT_CONTEXT.'/ShipCrate']]);
        });

        it('puts a port back on its old adapter', function () {
            $editor = samplingEditor();
            $editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'ports', 'Scale', 'Infra/'.SAMPLING_EDIT_CONTEXT.'/AnalogScale');

            expect($editor->cancelReplacement(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'ports', 'Scale'))->toBe([])
                ->and(samplingEditManifest()['ports']['Scale'])->toBe(['layer' => 'domain', 'adapter' => 'Infra/'.SAMPLING_EDIT_CONTEXT.'/DigitalScale']);
        });

        describe('of a domain service', function () {
            beforeEach(function () {
                $context = SAMPLING_EDIT_CONTEXT;
                File::ensureDirectoryExists(app_path("Domain/{$context}/Services/PackCrate"));
                File::put(app_path("Domain/{$context}/Services/PackCrate/PackCrateService.php"), "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\PackCrate;\n\nfinal class PackCrateService\n{\n    public function handle(): void {}\n}\n");
                (new StructureFiles(base_path()))->write([...samplingEditManifest(), 'services' => ['PackCrate' => ['shape' => 'plain', 'creates' => null, 'repositories' => ['Crate']]]]);
            });

            it('starts replacing a domain service beside the old one', function () {
                expect(samplingEditor()->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'services', 'PackCrate', 'PackCrateTightly'))->toBe([])
                    ->and(samplingEditManifest()['services'])->toBe([
                        'PackCrate' => ['shape' => 'plain', 'creates' => null, 'repositories' => ['Crate'], 'exception' => false],
                        'PackCrateTightly' => ['shape' => 'plain', 'creates' => null, 'repositories' => ['Crate'], 'exception' => false, 'replaces' => 'PackCrate'],
                    ]);
            });

            it('refuses a name that is not StudlyCase or that the context has, and a second replacement', function () {
                $editor = samplingEditor();

                expect($editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'services', 'PackCrate', 'packCrateTightly'))->toHaveKey('replacement')
                    ->and($editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'services', 'PackCrate', 'PackCrate'))->toHaveKey('replacement');

                $editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'services', 'PackCrate', 'PackCrateTightly');

                expect($editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'services', 'PackCrate', 'PackCrateLoosely')['name'][0])->toContain('being replaced by PackCrateTightly');
            });

            it('cancels by removing the new service', function () {
                $editor = samplingEditor();
                $editor->replace(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'services', 'PackCrate', 'PackCrateTightly');

                expect($editor->cancelReplacement(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'services', 'PackCrateTightly'))->toBe([])
                    ->and(samplingEditManifest()['services'])->toBe(['PackCrate' => ['shape' => 'plain', 'creates' => null, 'repositories' => ['Crate'], 'exception' => false]]);
            });
        });

        it('refuses a piece that replaces nothing', function () {
            expect(samplingEditor()->cancelReplacement(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'LoadPallet'))->toHaveKey('name');
        });
    });

    describe('exceptions', function () {
        $refusal = fn (string $aggregate): array => ['kind' => 'refusal', 'aggregate' => $aggregate, 'useCase' => null];

        it('designs an exception of each kind, in the home its kind gives it', function () {
            $editor = samplingEditor();

            expect($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'CrateLostException', ['kind' => 'refusal', 'aggregate' => 'Crate']))->toBe([])
                ->and($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'CrateBentException', ['kind' => 'value', 'aggregate' => 'Crate', 'useCase' => '']))->toBe([])
                ->and($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'PalletLateException', ['kind' => 'application', 'aggregate' => null, 'useCase' => 'LoadPallet']))->toBe([])
                ->and(samplingEditManifest()['exceptions'])->toBe([
                    'CrateBentException' => ['kind' => 'value', 'aggregate' => 'Crate', 'useCase' => null],
                    'CrateLostException' => ['kind' => 'refusal', 'aggregate' => 'Crate', 'useCase' => null],
                    'PalletLateException' => ['kind' => 'application', 'aggregate' => null, 'useCase' => 'LoadPallet'],
                ]);
        });

        it('refuses an aggregate or a use case the context does not have, and a name without Exception', function () {
            $editor = samplingEditor();

            expect($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'LorryLostException', ['kind' => 'refusal', 'aggregate' => 'Lorry']))->toHaveKey('aggregate')
                ->and($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'LorryLateException', ['kind' => 'application', 'aggregate' => null, 'useCase' => 'DriveLorry']))->toHaveKey('useCase')
                ->and($editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'CrateLost', ['kind' => 'refusal', 'aggregate' => 'Crate']))->toHaveKey('name')
                ->and(samplingEditManifest()['exceptions'])->toBe([]);
        });

        it('keeps an exception a method throws, an aggregate it lives in and a use case it refuses for', function () use ($refusal) {
            $editor = samplingEditor();
            $editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'CrateLostException', $refusal('Pallet'));
            $editor->savePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', null, 'PalletLateException', ['kind' => 'application', 'aggregate' => null, 'useCase' => 'LoadPallet']);
            $editor->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'stack', [], [SAMPLING_EDIT_CONTEXT.'/Pallet/CrateLostException']);

            expect($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'exceptions', 'CrateLostException')['name'][0])->toContain(SAMPLING_EDIT_CONTEXT.'/Crate::stack')
                ->and($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Pallet')['name'][0])->toContain(SAMPLING_EDIT_CONTEXT.'/CrateLostException')
                ->and($editor->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'LoadPallet')['name'][0])->toContain(SAMPLING_EDIT_CONTEXT.'/PalletLateException');
        });

        it('lets a method throw an exception another context designs, but not one nobody designs', function () use ($refusal) {
            $editor = samplingEditor();
            $editor->savePiece(SAMPLING_EDIT_OTHER, samplingEditVersion(SAMPLING_EDIT_OTHER), 'exceptions', null, 'BinFullException', $refusal('Bin'));

            expect($editor->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'stack', [], [SAMPLING_EDIT_OTHER.'/Bin/BinFullException']))->toBe([])
                ->and($editor->saveMethod(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', null, 'tip', [], [SAMPLING_EDIT_OTHER.'/Bin/BinGoneException']))->toHaveKey('throws');
        });
    });

    describe('removePiece', function () {
        it('removes a piece the code does not have yet', function () {
            expect(samplingEditor()->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'LoadPallet'))->toBe([])
                ->and(samplingEditManifest()['useCases'])->toHaveKeys(['ShipCrate'])->not->toHaveKey('LoadPallet');
        });

        it('keeps an aggregate something in any context still injects', function () {
            $errors = samplingEditor()->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Pallet');

            expect($errors['name'][0])->toContain(SAMPLING_EDIT_CONTEXT.'/LoadPallet')->toContain(SAMPLING_EDIT_OTHER.'/FillBin');
        });

        it('keeps a piece the code already has', function () {
            expect(samplingEditor()->removePiece(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate'))->toHaveKey('name')
                ->and(samplingEditManifest()['useCases'])->toHaveKey('ShipCrate');
        });
    });

    describe('sync', function () {
        it('takes a built piece, or one built method, back from the code', function () {
            (new StructureFiles(base_path()))->write([...samplingEditManifest(),
                'useCases' => [...samplingEditManifest()['useCases'], 'ShipCrate' => ['shape' => 'plain', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => []]],
                'entities' => ['Crate' => ['aggregate' => 'Crate', 'behaviours' => ['seal' => ['params' => ['force' => 'bool'], 'throws' => []]], 'assertions' => []]],
            ]);
            $editor = samplingEditor();

            expect($editor->sync(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'useCases', 'ShipCrate'))->toBe([])
                ->and(samplingEditManifest()['useCases']['ShipCrate']['returns'])->toBe('void')
                ->and($editor->sync(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'entities', 'seal', 'Crate'))->toBe([])
                ->and(samplingEditManifest()['entities']['Crate']['behaviours']['seal']['params'])->toBe([]);
        });

        it('takes one property of an entity\'s state back from the code', function () {
            (new StructureFiles(base_path()))->write([...samplingEditManifest(),
                'entities' => ['Crate' => ['aggregate' => 'Crate', 'state' => ['size' => 'int', 'weight' => 'string'], 'behaviours' => [], 'assertions' => []]],
            ]);
            $editor = samplingEditor();

            expect($editor->sync(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'entities', 'state.weight', 'Crate'))->toBe([])
                ->and(samplingEditManifest()['entities']['Crate']['state'])->toBe(['size' => 'int', 'weight' => 'int'])
                ->and($editor->sync(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'entities', 'state.size', 'Crate'))->toBe(['name' => ['The code has no Crate.size, so there is nothing to sync from.']]);
        });

        it('refuses a stale page, an entity as a whole, and a piece the code does not have', function () {
            $editor = samplingEditor();

            expect($editor->sync(SAMPLING_EDIT_CONTEXT, 'stale', 'useCases', 'ShipCrate'))->toHaveKey('version')
                ->and($editor->sync(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'entities', 'Crate'))->toBe(['section' => ["An entity's methods change one at a time."]])
                ->and($editor->sync(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'aggregates', 'Pallet'))->toBe(['name' => ['The code has no Pallet, so there is nothing to sync from.']]);
        });
    });

    describe('children', function () {
        it('adds a child to an aggregate the code already has, and removes one it does not have yet', function () {
            $editor = samplingEditor();

            expect($editor->addChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Lid'))->toBe([])
                ->and(samplingEditManifest()['aggregates']['Crate'])->toBe(['children' => ['Lid'], 'repository' => true])
                ->and($editor->removeChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Lid'))->toBe([])
                ->and(samplingEditManifest()['aggregates']['Crate']['children'])->toBe([]);
        });

        it('refuses a child that is no name, the root\'s name, one already listed, or one the code has', function () {
            $built = app_path('Domain/'.SAMPLING_EDIT_CONTEXT.'/Crate/Entities/HingeEntity.php');
            File::ensureDirectoryExists(dirname($built));
            File::put($built, "<?php\n\nnamespace App\\Domain\\".SAMPLING_EDIT_CONTEXT."\\Crate\\Entities;\n\nuse App\\Domain\\Shared\\DomainEntity;\n\nfinal class HingeEntity extends DomainEntity\n{\n    public function id(): string { return 'hinge'; }\n\n    public static function entityName(): string { return 'Hinge'; }\n}\n");
            $editor = samplingEditor();
            $editor->addChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Lid');

            expect($editor->addChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'lid-flap'))->toBe(['child' => ["A child is a StudlyCase name other than the root's: lid-flap is not."]])
                ->and($editor->addChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Crate'))->toBe(['child' => ["A child is a StudlyCase name other than the root's: Crate is not."]])
                ->and($editor->addChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Lid'))->toBe(['child' => ['Crate already has the child Lid.']])
                ->and($editor->addChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Hinge'))->toBe(['child' => ['The code already has Hinge. Run `php artisan kit:import --context='.SAMPLING_EDIT_CONTEXT.' --sync` to read it into the manifest.']])
                ->and($editor->addChild(SAMPLING_EDIT_CONTEXT, 'stale', 'Crate', 'Strap'))->toHaveKey('version')
                ->and($editor->addChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Barrel', 'Strap'))->toBe(['aggregate' => ['The manifest has no aggregate Barrel.']]);
        });

        it('keeps a child the code has, one the manifest lists methods for, and one it never listed', function () {
            $built = app_path('Domain/'.SAMPLING_EDIT_CONTEXT.'/Crate/Entities/HingeEntity.php');
            File::ensureDirectoryExists(dirname($built));
            File::put($built, "<?php\n\nnamespace App\\Domain\\".SAMPLING_EDIT_CONTEXT."\\Crate\\Entities;\n\nuse App\\Domain\\Shared\\DomainEntity;\n\nfinal class HingeEntity extends DomainEntity\n{\n    public function id(): string { return 'hinge'; }\n\n    public static function entityName(): string { return 'Hinge'; }\n}\n");
            (new StructureFiles(base_path()))->write([...samplingEditManifest(),
                'aggregates' => [...samplingEditManifest()['aggregates'], 'Crate' => ['children' => ['Hinge', 'Lid'], 'repository' => true]],
                'entities' => ['Lid' => ['aggregate' => 'Crate', 'behaviours' => ['open' => ['params' => [], 'throws' => []]], 'assertions' => []]],
            ]);
            $editor = samplingEditor();

            expect($editor->removeChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Hinge'))->toBe(['name' => ['The code already has Hinge, so it stays.']])
                ->and($editor->removeChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Lid'))->toBe(['name' => ['Lid lists its methods under entities, so it stays a child.']])
                ->and($editor->removeChild(SAMPLING_EDIT_CONTEXT, samplingEditVersion(), 'Crate', 'Strap'))->toBe(['name' => ['Crate has no child Strap.']])
                ->and(samplingEditManifest()['aggregates']['Crate']['children'])->toBe(['Hinge', 'Lid']);
        });
    });
});
