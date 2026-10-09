<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureComparer;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch context of this file. It carries `Sampling`, so no Architecture check reads it, and
 * differs from every other test file's, so --parallel never deletes it mid-run.
 */
const SAMPLING_COMPARE_CONTEXT = 'SamplingCompare';

/**
 * Two aggregates that both declare CrateGrade, a status, and a scratch enum the manifest leaves out.
 */
function writeSamplingCompareFixtures(): void
{
    $context = SAMPLING_COMPARE_CONTEXT;
    $enum = fn (string $aggregate, string $name, string $cases): string => "<?php\n\nnamespace App\\Domain\\{$context}\\{$aggregate}\\Enums;\n\nenum {$name}: string\n{\n{$cases}}\n";
    $fixtures = [
        "Domain/{$context}/Crate/Enums/CrateGrade.php" => $enum('Crate', 'CrateGrade', "    case Top = 'top';\n    case Low = 'low';\n"),
        "Domain/{$context}/Crate/Enums/SamplingCompareShade.php" => $enum('Crate', 'SamplingCompareShade', "    case Dark = 'dark';\n"),
        "Domain/{$context}/Pallet/Enums/CrateGrade.php" => $enum('Pallet', 'CrateGrade', "    case Any = 'any';\n"),
        "Domain/{$context}/Crate/Enums/CrateStatus.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Enums;\n\nuse App\\Domain\\Shared\\Concerns\\HasTransitions;\n\nenum CrateStatus: string\n{\n    use HasTransitions;\n\n    case Open = 'open';\n    case Sealed = 'sealed';\n    case Shipped = 'shipped';\n\n    public function transitions(): array\n    {\n        return match (\$this) {\n            self::Open => [self::Sealed, self::Shipped],\n            self::Sealed => [self::Shipped],\n            self::Shipped => [],\n        };\n    }\n}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(app_path($relative)));
        File::put(app_path($relative), $contents);
    }

    (new StructureFiles(base_path()))->write([
        'context' => $context,
        'enums' => [
            'CrateGrade' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Low' => 'low', 'Top' => 'top']],
            'CrateStatus' => samplingCompareStatus(['Shipped' => [], 'Open' => ['Shipped', 'Sealed'], 'Sealed' => ['Shipped']]),
        ],
        'valueObjects' => ['CrateLabel' => ['aggregate' => 'Crate', 'fields' => ['grade' => 'CrateGrade']]],
    ]);
}

/**
 * The fixture's status as a manifest lists it, with the transitions given.
 *
 * @param  array<string, list<string>>|null  $transitions
 * @return array<string, mixed>
 */
function samplingCompareStatus(?array $transitions): array
{
    return ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Open' => 'open', 'Sealed' => 'sealed', 'Shipped' => 'shipped'], 'transitions' => $transitions];
}

function forgetSamplingCompare(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_COMPARE_CONTEXT));
    File::delete(base_path('.kit/structure/'.SAMPLING_COMPARE_CONTEXT.'.json'));
}

/**
 * The differences about this file's context, as check and message. Every other scratch context,
 * and every piece named with Sampling, is skipped the way the Architecture check skips them.
 *
 * @return list<array{0: string, 1: string}|array{0: string, 1: string, 2: string|null}>
 */
function samplingCompareDifferences(bool $withNode = false): array
{
    $comparer = new StructureComparer(
        new StructureReader(base_path()),
        new StructureFiles(base_path()),
        fn (string $name): bool => str_contains($name, 'Sampling') && $name !== SAMPLING_COMPARE_CONTEXT,
    );

    return array_values(array_map(
        fn (array $difference): array => $withNode ? [$difference['check'], $difference['message'], $difference['node']] : [$difference['check'], $difference['message']],
        array_filter($comparer->differences(), fn (array $difference): bool => str_contains($difference['subject'], SAMPLING_COMPARE_CONTEXT)),
    ));
}

beforeEach(function () {
    forgetSamplingCompare();
    writeSamplingCompareFixtures();
});

afterEach(fn () => forgetSamplingCompare());

describe('StructureComparer', function () {
    describe('differences', function () {
        it('tells an enum two aggregates declare, cases in another order, and a value object not built yet', function () {
            expect(samplingCompareDifferences())->toBe([
                ['matches', 'enums.CrateGrade is declared in both Crate and Pallet — a name is unique within its context, so rename one'],
                ['matches', 'enums.CrateGrade.cases is {"Top":"top","Low":"low"} in the code but {"Low":"low","Top":"top"} in the manifest'],
                ['in-code', 'lists valueObjects.CrateLabel, which the code does not have yet — build it, or take it out of the manifest'],
            ]);
        });

        it('finds nothing once the manifest lists what the code holds', function () {
            File::deleteDirectory(app_path('Domain/'.SAMPLING_COMPARE_CONTEXT.'/Pallet'));
            (new StructureFiles(base_path()))->write([
                'context' => SAMPLING_COMPARE_CONTEXT,
                'enums' => [
                    'CrateGrade' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Top' => 'top', 'Low' => 'low']],
                    'CrateStatus' => samplingCompareStatus(['Open' => ['Sealed', 'Shipped'], 'Sealed' => ['Shipped'], 'Shipped' => []]),
                ],
            ]);

            expect(samplingCompareDifferences())->toBe([]);
        });

        it('tells a status that may go somewhere else, or that the manifest does not know is a status, whatever order the manifest lists its moves in', function () {
            $write = fn (?array $transitions) => (new StructureFiles(base_path()))->write([
                'context' => SAMPLING_COMPARE_CONTEXT,
                'enums' => [
                    'CrateGrade' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Top' => 'top', 'Low' => 'low']],
                    'CrateStatus' => samplingCompareStatus($transitions),
                ],
            ]);
            $inCode = '{"Open":["Sealed","Shipped"],"Sealed":["Shipped"],"Shipped":[]}';

            $write(['Open' => ['Shipped'], 'Sealed' => ['Shipped', 'Open'], 'Shipped' => []]);
            $moved = samplingCompareDifferences(withNode: true);
            $write(null);
            $plain = samplingCompareDifferences();

            expect($moved)->toContain(['matches', 'enums.CrateStatus.transitions is '.$inCode.' in the code but {"Open":["Shipped"],"Sealed":["Open","Shipped"],"Shipped":[]} in the manifest', 'enum:'.SAMPLING_COMPARE_CONTEXT.'/CrateStatus'])
                ->and($plain)->toContain(['matches', 'enums.CrateStatus.transitions is '.$inCode.' in the code but null in the manifest']);
        });

        it('tells an exception only the code has, one only the manifest has, one of another kind, and a service exception still to write', function () {
            $context = SAMPLING_COMPARE_CONTEXT;
            File::deleteDirectory(app_path("Domain/{$context}/Pallet"));
            $classes = [
                "Domain/{$context}/Exceptions/{$context}DomainException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Exceptions;\n\nabstract class {$context}DomainException extends \\App\\Domain\\Shared\\DomainException {}\n",
                "Domain/{$context}/Crate/Exceptions/CrateBrokenException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Exceptions;\n\nfinal class CrateBrokenException extends \\App\\Domain\\{$context}\\Exceptions\\{$context}DomainException {}\n",
                "Domain/{$context}/Crate/Exceptions/CrateBentException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Exceptions;\n\nfinal class CrateBentException extends \\App\\Domain\\Shared\\Exceptions\\DomainValueException {}\n",
                "Domain/{$context}/Services/PackCrate/PackCrateService.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\PackCrate;\n\nfinal class PackCrateService\n{\n    public function handle(): void {}\n}\n",
            ];

            foreach ($classes as $relative => $contents) {
                File::ensureDirectoryExists(dirname(app_path($relative)));
                File::put(app_path($relative), $contents);
            }

            (new StructureFiles(base_path()))->write([
                'context' => $context,
                'aggregates' => ['Crate' => ['children' => [], 'repository' => false]],
                'services' => ['PackCrate' => ['shape' => 'plain', 'creates' => null, 'repositories' => [], 'exception' => true]],
                'enums' => [
                    'CrateGrade' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Top' => 'top', 'Low' => 'low']],
                    'CrateStatus' => samplingCompareStatus(['Open' => ['Sealed', 'Shipped'], 'Sealed' => ['Shipped'], 'Shipped' => []]),
                ],
                'exceptions' => [
                    'CrateBentException' => ['kind' => 'refusal', 'aggregate' => 'Crate', 'useCase' => null],
                    'CrateLostException' => ['kind' => 'refusal', 'aggregate' => 'Crate', 'useCase' => null],
                ],
            ]);

            $differences = samplingCompareDifferences(withNode: true);

            expect($differences)->toContain(
                ['in-json', 'is not in .kit/structure/'.$context.'.json — add it under "exceptions" (`php artisan kit:import --context='.$context.' --sync` adds it, keeping what is not built yet)', null],
                ['in-code', 'lists exceptions.CrateLostException, which the code does not have yet — build it, or take it out of the manifest', 'exception:'.$context.'/CrateLostException'],
                ['matches', 'exceptions.CrateBentException.kind is "value" in the code but "refusal" in the manifest', 'exception:'.$context.'/CrateBentException'],
            )->and(array_column($differences, 1))->toContain('services.PackCrate.exception is true in the manifest, but the code has no PackCrateException beside the service — write it by hand, extending DomainException (make:domain-service adds one only to a service it builds)');
        });

        it('tells each method of an entity apart: one only the code has, one only the manifest has, and one that throws something else', function () {
            $context = SAMPLING_COMPARE_CONTEXT;
            File::deleteDirectory(app_path("Domain/{$context}/Crate"));
            File::deleteDirectory(app_path("Domain/{$context}/Pallet"));
            File::ensureDirectoryExists(app_path("Domain/{$context}/Bin"));
            File::put(app_path("Domain/{$context}/Bin/BinEntity.php"), "<?php\n\nnamespace App\\Domain\\{$context}\\Bin;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class BinEntity extends AggregateRoot\n{\n    public function id(): string { return 'bin'; }\n\n    public static function entityName(): string { return 'Bin'; }\n\n    public function empty(): void {}\n\n    public function fill(int \$items): void { throw new \\RuntimeException; }\n}\n");
            (new StructureFiles(base_path()))->write([
                'context' => $context,
                'aggregates' => ['Bin' => ['children' => [], 'repository' => false]],
                'entities' => ['Bin' => ['aggregate' => 'Bin', 'behaviours' => [
                    'fill' => ['params' => ['items' => 'int'], 'throws' => []],
                    'tip' => ['params' => [], 'throws' => []],
                ], 'assertions' => []]],
            ]);

            expect(samplingCompareDifferences(withNode: true))->toBe([
                ['in-json', 'is not in .kit/structure/'.$context.'.json — add it under "entities.Bin.behaviours" (`php artisan kit:import --context='.$context.' --sync` adds it, keeping what is not built yet)', null],
                ['matches', 'entities.Bin.fill.throws is ["RuntimeException"] in the code but [] in the manifest', "entity:{$context}/Bin"],
                ['in-code', 'lists entities.Bin.tip, which the code does not have yet — build it, or take it out of the manifest', "entity:{$context}/Bin"],
            ]);
        });

        it('tells the state of an entity apart: a property only one side has, another type, another order, and one no getter returns', function () {
            $context = SAMPLING_COMPARE_CONTEXT;
            File::deleteDirectory(app_path("Domain/{$context}/Crate"));
            File::deleteDirectory(app_path("Domain/{$context}/Pallet"));
            File::ensureDirectoryExists(app_path("Domain/{$context}/Tote"));
            File::put(app_path("Domain/{$context}/Tote/ToteEntity.php"), "<?php\n\nnamespace App\\Domain\\{$context}\\Tote;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class ToteEntity extends AggregateRoot\n{\n    private function __construct(private string \$id, private int \$size, private string \$label, private ?string \$note) {}\n\n    public function id(): string { return \$this->id; }\n\n    public static function entityName(): string { return 'Tote'; }\n\n    public function size(): int { return \$this->size; }\n\n    public function label(): int { return 0; }\n}\n");
            (new StructureFiles(base_path()))->write([
                'context' => $context,
                'aggregates' => ['Tote' => ['children' => [], 'repository' => false]],
                'entities' => ['Tote' => ['aggregate' => 'Tote', 'state' => ['label' => 'string', 'size' => 'string', 'colour' => 'string'], 'behaviours' => [], 'assertions' => []]],
            ]);

            expect(samplingCompareDifferences(withNode: true))->toBe([
                ['matches', 'entities.Tote.state.size is "int" in the code but "string" in the manifest', "entity:{$context}/Tote"],
                ['in-json', 'is not in .kit/structure/'.$context.'.json — add it under "entities.Tote.state" (`php artisan kit:import --context='.$context.' --sync` adds it, keeping what is not built yet)', null],
                ['matches', 'entities.Tote.state is in the order size, label in the code but label, size in the manifest', "entity:{$context}/Tote"],
                ['in-code', 'lists entities.Tote.state.colour, which the code does not have yet — build it, or take it out of the manifest', "entity:{$context}/Tote"],
                ['matches', 'has no getter — add public function label(): string that returns it', "entity:{$context}/Tote"],
                ['matches', 'has no getter — add public function note(): ?string that returns it', "entity:{$context}/Tote"],
            ]);
        });
    });
});
