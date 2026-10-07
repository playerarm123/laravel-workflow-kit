<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;

/**
 * A root of its own under storage, so these cases never touch the project's `.kit/structure`.
 */
function samplingStructureFilesRoot(): string
{
    return storage_path('framework/testing/sampling-structure-files');
}

/**
 * @return array<string, mixed>
 */
function samplingStructureManifest(): array
{
    return [
        'context' => 'Shipping',
        'useCases' => [
            'ShipCrate' => ['repositories' => ['Crate', 'Billing/Invoice'], 'shape' => 'command', 'returns' => 'void', 'creates' => false, 'query' => false],
        ],
        'aggregates' => [
            'Pallet' => ['repository' => false, 'children' => []],
            'Crate' => ['children' => ['Lid'], 'repository' => true],
        ],
        'services' => [],
        'ports' => [],
        'valueObjects' => [
            'Weight' => ['fields' => ['unit' => 'WeightUnit', 'amount' => 'int'], 'aggregate' => 'Crate'],
        ],
        'enums' => [
            'WeightUnit' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Kilogram' => 'kg', 'Gram' => 'g']],
            'CrateStatus' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Open' => 'open', 'Sealed' => 'sealed', 'Shipped' => 'shipped'], 'transitions' => [
                'Shipped' => [],
                'Open' => ['Shipped', 'Sealed'],
                'Sealed' => ['Shipped'],
            ]],
        ],
        'entities' => [
            'Lid' => ['aggregate' => 'Crate', 'behaviours' => ['open' => ['throws' => [], 'params' => []]], 'assertions' => []],
            'Crate' => [
                'assertions' => ['assertIsOpen' => ['params' => [], 'throws' => ['CrateSealedException']]],
                'behaviours' => [
                    'seal' => ['params' => ['weight' => 'Weight', 'note' => '?string'], 'throws' => ['Shared/InvalidMoneyException', 'CrateSealedException']],
                    'label' => ['params' => [], 'throws' => []],
                ],
                'aggregate' => 'Crate',
            ],
        ],
    ];
}

beforeEach(function () {
    File::deleteDirectory(samplingStructureFilesRoot());
    $this->files = new StructureFiles(samplingStructureFilesRoot());
});

afterEach(fn () => File::deleteDirectory(samplingStructureFilesRoot()));

describe('StructureFiles', function () {
    describe('encode', function () {
        it('writes the canonical form: sections in schema order, keys, lists and methods sorted, cases, fields and params as written, transitions in case order, empty maps as objects', function () {
            expect($this->files->encode(samplingStructureManifest()))->toBe(<<<'JSON'
{
    "context": "Shipping",
    "aggregates": {
        "Crate": {
            "children": [
                "Lid"
            ],
            "repository": true
        },
        "Pallet": {
            "children": [],
            "repository": false
        }
    },
    "services": {},
    "ports": {},
    "useCases": {
        "ShipCrate": {
            "shape": "command",
            "returns": "void",
            "creates": false,
            "query": false,
            "repositories": [
                "Billing/Invoice",
                "Crate"
            ]
        }
    },
    "enums": {
        "CrateStatus": {
            "aggregate": "Crate",
            "backing": "string",
            "cases": {
                "Open": "open",
                "Sealed": "sealed",
                "Shipped": "shipped"
            },
            "transitions": {
                "Open": [
                    "Sealed",
                    "Shipped"
                ],
                "Sealed": [
                    "Shipped"
                ],
                "Shipped": []
            }
        },
        "WeightUnit": {
            "aggregate": "Crate",
            "backing": "string",
            "cases": {
                "Kilogram": "kg",
                "Gram": "g"
            },
            "transitions": null
        }
    },
    "valueObjects": {
        "Weight": {
            "aggregate": "Crate",
            "fields": {
                "unit": "WeightUnit",
                "amount": "int"
            }
        }
    },
    "entities": {
        "Crate": {
            "aggregate": "Crate",
            "behaviours": {
                "label": {
                    "params": {},
                    "throws": []
                },
                "seal": {
                    "params": {
                        "weight": "Weight",
                        "note": "?string"
                    },
                    "throws": [
                        "CrateSealedException",
                        "Shared/InvalidMoneyException"
                    ]
                }
            },
            "assertions": {
                "assertIsOpen": {
                    "params": {},
                    "throws": [
                        "CrateSealedException"
                    ]
                }
            }
        },
        "Lid": {
            "aggregate": "Crate",
            "behaviours": {
                "open": {
                    "params": {},
                    "throws": []
                }
            },
            "assertions": {}
        }
    }
}

JSON);
        });

        it('writes an enum with no cases and a value object with no fields as empty objects', function () {
            $manifest = [...samplingStructureManifest(),
                'enums' => ['Flag' => ['aggregate' => 'Crate', 'backing' => null, 'cases' => []]],
                'valueObjects' => ['Nothing' => ['aggregate' => 'Crate', 'fields' => []]],
            ];

            expect($this->files->encode($manifest))
                ->toContain('"cases": {}')
                ->toContain('"fields": {}')
                ->and($this->files->problems('Shipping', json_decode($this->files->encode($manifest), true)))->toBe([]);
        });

        it('writes what a piece replaces only while it replaces something', function () {
            $manifest = [...samplingStructureManifest(), 'useCases' => [
                'ShipCrate' => ['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => [], 'replaces' => null],
                'ShipCrateSafely' => ['shape' => 'plain', 'returns' => 'void', 'creates' => false, 'query' => false, 'repositories' => [], 'replaces' => 'ShipCrate'],
            ]];
            $useCases = json_decode($this->files->encode($manifest), true)['useCases'];

            expect($useCases['ShipCrate'])->not->toHaveKey('replaces')
                ->and($useCases['ShipCrateSafely']['replaces'])->toBe('ShipCrate')
                ->and($this->files->problems('Shipping', json_decode($this->files->encode($manifest), true)))->toBe([]);
        });

        it('writes what a domain service replaces, and refuses a replacement that is not a name', function () {
            $manifest = [...samplingStructureManifest(), 'services' => [
                'PackCrate' => ['shape' => 'plain', 'creates' => null, 'repositories' => [], 'replaces' => null],
                'PackCrateTightly' => ['shape' => 'plain', 'creates' => null, 'repositories' => [], 'replaces' => 'PackCrate'],
            ]];
            $services = json_decode($this->files->encode($manifest), true)['services'];

            expect($services['PackCrate'])->not->toHaveKey('replaces')
                ->and($services['PackCrateTightly']['replaces'])->toBe('PackCrate')
                ->and($this->files->problems('Shipping', json_decode($this->files->encode($manifest), true)))->toBe([])
                ->and($this->files->problems('Shipping', [...$manifest, 'services' => ['PackCrateTightly' => [...$manifest['services']['PackCrateTightly'], 'replaces' => 7]]]))->not->toBe([]);
        });
    });

    describe('write and read', function () {
        it('writes one file per context and reads it back', function () {
            $this->files->write(samplingStructureManifest());

            expect($this->files->exists('Shipping'))->toBeTrue()
                ->and($this->files->contexts())->toBe(['Shipping'])
                ->and($this->files->relativePath('Shipping'))->toBe('.kit/structure/Shipping.json')
                ->and($this->files->read('Shipping')['aggregates']['Crate'])->toBe(['children' => ['Lid'], 'repository' => true]);
        });

        it('lists no context when the folder does not exist', function () {
            expect($this->files->contexts())->toBe([]);
        });
    });

    describe('problems', function () {
        it('finds nothing wrong with a manifest it wrote', function () {
            $this->files->write(samplingStructureManifest());

            expect($this->files->problems('Shipping', $this->files->read('Shipping')))->toBe([]);
        });

        it('refuses what is not a JSON object', function () {
            expect($this->files->problems('Shipping', null))->toBe(['is not a JSON object']);
        });

        it('names every fault of a manifest with the wrong shape', function () {
            $document = [
                'context' => 'Billing',
                'extra' => true,
                'aggregates' => ['crate' => ['children' => [], 'repository' => true]],
                'services' => ['Pack' => ['shape' => 'fancy', 'repositories' => []]],
                'ports' => ['Scale' => ['layer' => 'domain', 'adapter' => 7]],
            ];

            expect($this->files->problems('Shipping', $document))->toBe([
                'says "context": "Billing", but the file is named Shipping.json',
                'has an unknown key "extra"',
                'aggregates.crate: a name is StudlyCase',
                'services.Pack: "shape" must be one of creates, data, plain',
                'ports.Scale: "adapter" must be string|null',
                'has no "useCases" object',
                'has no "enums" object',
                'has no "valueObjects" object',
                'has no "entities" object',
            ]);
        });

        it('refuses cases that do not fit the backing, and fields that are not types', function () {
            $document = samplingStructureManifest();
            $document['enums'] = [
                'WeightUnit' => ['aggregate' => 'Crate', 'backing' => 'int', 'cases' => ['Kilogram' => 'kg']],
                'Grade' => ['aggregate' => 'Crate', 'backing' => null, 'cases' => ['Top' => 'top']],
                'Size' => ['aggregate' => 'Crate', 'backing' => 'float', 'cases' => []],
            ];
            $document['valueObjects'] = [
                'Weight' => ['aggregate' => 'Crate', 'fields' => ['amount' => '']],
                'Label' => ['aggregate' => 'Crate', 'fields' => ['text', 'string']],
            ];

            expect($this->files->problems('Shipping', $document))->toBe([
                'enums.WeightUnit: "cases" holds Kilogram, which must be an int',
                'enums.Grade: "cases" holds Top, which must be null, because the enum is not backed',
                'enums.Size: "backing" must be one of string, int, null',
                'valueObjects.Weight: "fields" must be an object of names to a type',
                'valueObjects.Label: "fields" must be an object of names to a type',
            ]);
        });

        it('refuses transitions of the wrong shape, a case left out or unknown, a target that is no case, itself, or named twice', function () {
            $status = fn (mixed $transitions): array => ['aggregate' => 'Crate', 'backing' => null, 'cases' => ['Open' => null, 'Sealed' => null], 'transitions' => $transitions];
            $document = samplingStructureManifest();
            $document['enums'] = [
                'ListStatus' => $status(['Open', 'Sealed']),
                'NamedStatus' => $status(['Open' => 'Sealed', 'Sealed' => []]),
                'LeftStatus' => $status(['Open' => ['Sealed']]),
                'UnknownStatus' => $status(['Open' => ['Sealed'], 'Sealed' => [], 'Lost' => []]),
                'TargetStatus' => $status(['Open' => ['Sealed', 'Lost'], 'Sealed' => []]),
                'LoopStatus' => $status(['Open' => ['Open', 'Sealed', 'Sealed'], 'Sealed' => []]),
            ];

            expect($this->files->problems('Shipping', $document))->toBe([
                'enums.ListStatus: "transitions" must be null, or an object of each case to the list of cases it may become',
                'enums.NamedStatus: "transitions" must be null, or an object of each case to the list of cases it may become',
                'enums.LeftStatus: "transitions" leaves out Sealed — list every case, a final one with []',
                'enums.UnknownStatus: "transitions" lists Lost, which is not one of its cases',
                'enums.TargetStatus: "transitions" lets Open become Lost, which is not one of its cases',
                'enums.LoopStatus: "transitions" lets Open become itself — staying put is no change',
                'enums.LoopStatus: "transitions" names a case Open may become twice',
            ]);
        });

        it('puts an enum or a value object in an aggregate, and in no aggregate in the shared kernel', function () {
            $vocabulary = fn (?string $aggregate): array => [
                'enums' => ['Unit' => ['aggregate' => $aggregate, 'backing' => null, 'cases' => ['Gram' => null]]],
                'valueObjects' => ['Weight' => ['aggregate' => $aggregate, 'fields' => ['grams' => 'int']]],
            ];
            $empty = ['aggregates' => [], 'services' => [], 'ports' => [], 'useCases' => [], 'entities' => []];

            expect($this->files->problems('Shared', ['context' => 'Shared', ...$empty, ...$vocabulary(null)]))->toBe([])
                ->and($this->files->problems('Shared', ['context' => 'Shared', ...$empty, ...$vocabulary('Crate')]))->toBe([
                    'enums.Unit: "aggregate" must be null, because the shared kernel has no aggregates',
                    'valueObjects.Weight: "aggregate" must be null, because the shared kernel has no aggregates',
                ])
                ->and($this->files->problems('Shipping', ['context' => 'Shipping', ...$empty, ...$vocabulary(null)]))->toBe([
                    'enums.Unit: "aggregate" must name the aggregate whose folder holds it',
                    'valueObjects.Weight: "aggregate" must name the aggregate whose folder holds it',
                ]);
        });

        it('refuses methods of the wrong shape, an assertion in the wrong group, and an entity that lists nothing', function () {
            $document = samplingStructureManifest();
            $document['entities'] = [
                'Crate' => ['aggregate' => 'Crate', 'behaviours' => ['Seal' => ['params' => [], 'throws' => []]], 'assertions' => []],
                'Lid' => ['aggregate' => 'Crate', 'behaviours' => ['open' => ['params' => ['a' => ''], 'throws' => []]], 'assertions' => []],
                'Pallet' => ['aggregate' => 'Pallet', 'behaviours' => ['stack' => ['params' => [], 'throws' => 'Nope']], 'assertions' => []],
                'Weight' => ['aggregate' => 'Crate', 'behaviours' => ['assertHeavy' => ['params' => [], 'throws' => []]], 'assertions' => ['heavy' => ['params' => [], 'throws' => []]]],
            ];

            expect($this->files->problems('Shipping', $document))->toBe([
                'entities.Crate: "behaviours" must be an object of camelCase method names, each with "params" (names to a type) and "throws" (a list of exceptions)',
                'entities.Lid: "behaviours" must be an object of camelCase method names, each with "params" (names to a type) and "throws" (a list of exceptions)',
                'entities.Pallet: "behaviours" must be an object of camelCase method names, each with "params" (names to a type) and "throws" (a list of exceptions)',
                'entities.Weight: "aggregate" must name the aggregate whose root or child it is',
                'entities.Weight: "behaviours" holds assertHeavy, which is named like an assertion — list it under "assertions"',
                'entities.Weight: "assertions" holds heavy, whose name must start with assert',
            ]);
        });

        it('refuses an entity that lists no method, and any entity in the shared kernel', function () {
            $document = samplingStructureManifest();
            $document['entities'] = ['Pallet' => ['aggregate' => 'Pallet', 'behaviours' => [], 'assertions' => []]];
            $shared = ['context' => 'Shared', 'aggregates' => [], 'services' => [], 'ports' => [], 'useCases' => [], 'enums' => [], 'valueObjects' => [], 'entities' => [
                'Crate' => ['aggregate' => 'Crate', 'behaviours' => ['seal' => ['params' => [], 'throws' => []]], 'assertions' => []],
            ]];

            expect($this->files->problems('Shipping', $document))->toBe(['entities.Pallet: lists no behaviour and no assertion, so leave it out'])
                ->and($this->files->problems('Shared', $shared))->toBe(['entities.Crate: the shared kernel has no entities']);
        });

        it('refuses an unknown key and a missing one inside an entry', function () {
            $document = samplingStructureManifest();
            $document['useCases']['ShipCrate']['colour'] = 'red';
            unset($document['useCases']['ShipCrate']['creates']);

            expect($this->files->problems('Shipping', $document))->toBe([
                'useCases.ShipCrate: has an unknown key "colour"',
                'useCases.ShipCrate: "creates" must be bool',
            ]);
        });
    });

    describe('resources', function () {
        it('writes a resource in the canonical form and reads it back', function () {
            $this->files->writeResource([
                'pages' => ['crates/index' => 'table'],
                'policy' => ['viewAny', 'ship'],
                'actions' => ['Ship' => ['useCases' => ['Shipping/ShipCrate'], 'bulk' => true, 'row' => true]],
                'controller' => ['store' => ['Shipping/CreateCrate'], 'index' => []],
                'model' => 'Crate',
                'resource' => 'Crate',
            ]);

            expect(File::get($this->files->resourcePath('Crate')))->toBe(<<<'JSON'
{
    "resource": "Crate",
    "model": "Crate",
    "controller": {
        "index": [],
        "store": [
            "Shipping/CreateCrate"
        ]
    },
    "actions": {
        "Ship": {
            "row": true,
            "bulk": true,
            "useCases": [
                "Shipping/ShipCrate"
            ]
        }
    },
    "policy": [
        "ship",
        "viewAny"
    ],
    "pages": {
        "crates/index": "table"
    }
}

JSON)
                ->and($this->files->resources())->toBe(['Crate'])
                ->and($this->files->relativeResourcePath('Crate'))->toBe('.kit/structure/http/Crate.json')
                ->and($this->files->resourceProblems('Crate', $this->files->readResource('Crate')))->toBe([]);
        });

        it('writes a resource with no model, no policy and nothing else as empty objects', function () {
            expect($this->files->encodeResource(['resource' => 'Report', 'model' => null, 'controller' => [], 'actions' => [], 'policy' => null, 'pages' => []]))
                ->toContain('"model": null,')
                ->toContain('"controller": {},')
                ->toContain('"policy": null,');
        });

        it('names every fault of a resource with the wrong shape', function () {
            expect($this->files->resourceProblems('Crate', [
                'resource' => 'Box',
                'model' => 'crate',
                'controller' => ['Index' => ['ListCrates']],
                'actions' => ['ship' => [], 'Pack' => ['row' => 'yes', 'bulk' => false, 'useCases' => []]],
                'policy' => ['ViewAny'],
                'pages' => ['Crates/Index' => 'chart'],
                'routes' => [],
            ]))->toBe([
                'says "resource": "Box", but the file is named Crate.json',
                'has an unknown key "routes"',
                '"model" must be a StudlyCase model name or null',
                'controller.Index: a method name is camelCase',
                'controller.Index: must list use cases as "Context/UseCase"',
                'actions.ship: a verb is StudlyCase',
                'actions.Pack: must be {"row": bool, "bulk": bool, "useCases": ["Context/UseCase"]}',
                '"policy" must list camelCase abilities, or be null',
                'pages.Crates/Index: a page is its path under resources/js/pages, in kebab case',
                'pages.Crates/Index: must be one of table, grid, form, page',
            ]);
        });
    });
});
