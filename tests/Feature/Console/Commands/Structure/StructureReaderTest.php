<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch contexts of this file. They carry `Sampling` (testing.md), so no Architecture check
 * reads them, and differ from every other generator test file's, so --parallel never deletes them
 * mid-run. A class loads once per process, so the fixtures are written once and never rewritten.
 */
const SAMPLING_READER_CONTEXT = 'SamplingReader';

const SAMPLING_READER_OTHER = 'SamplingReaderOther';

/**
 * A context that holds one piece of every kind the manifest lists, in every shape.
 */
function writeSamplingReaderFixtures(): void
{
    $context = SAMPLING_READER_CONTEXT;
    $other = SAMPLING_READER_OTHER;

    $fixtures = [
        "Domain/{$context}/Crate/CrateEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate;\n\nuse App\\Domain\\{$context}\\Crate\\Enums\\CrateGrade;\nuse App\\Domain\\{$context}\\Crate\\Exceptions\\CrateSealedException;\nuse App\\Domain\\Shared\\AggregateRoot;\nuse App\\Domain\\Shared\\Exceptions\\InvalidMoneyException as MoneyRefused;\nuse App\\Domain\\Shared\\ValueObjects\\Money;\n\nfinal class CrateEntity extends AggregateRoot\n{\n    private function __construct(private string \$id, private CrateGrade \$grade, private int \$weight, private ?string \$label) {}\n\n    public function id(): string { return \$this->id; }\n\n    public function grade(): CrateGrade { return \$this->grade; }\n\n    public static function entityName(): string { return 'Crate'; }\n\n    public static function make(): self { throw new CrateSealedException; }\n\n    public function seal(CrateGrade \$grade, ?string \$note, string ...\$tags): void\n    {\n        \$this->assertIsOpen();\n        static::weigh();\n\n        try {\n            \$this->seal(\$grade, \$note);\n        } catch (\\Throwable \$e) {\n            throw \$e;\n        }\n    }\n\n    public function price(Money \$price): void\n    {\n        throw MoneyRefused::malformed('x');\n    }\n\n    public function weight(): int { return 0; }\n\n    public function isEmpty(): bool { \$this->assertIsOpen(); return true; }\n\n    public function assertIsOpen(): void\n    {\n        if (\$this->isEmpty()) {\n            throw CrateSealedException::sealed();\n        }\n    }\n\n    private static function weigh(): void\n    {\n        throw new Exceptions\\CrateEmptyException;\n    }\n}\n",
        "Domain/{$context}/Crate/Exceptions/CrateSealedException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Exceptions;\n\nfinal class CrateSealedException extends \\RuntimeException\n{\n    public static function sealed(): self { return new self; }\n}\n",
        "Domain/{$context}/Crate/Exceptions/CrateEmptyException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Exceptions;\n\nfinal class CrateEmptyException extends \\RuntimeException {}\n",
        "Domain/{$context}/Crate/Entities/LidEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Entities;\n\nuse App\\Domain\\Shared\\DomainEntity;\n\nfinal class LidEntity extends DomainEntity\n{\n    public function id(): string { return 'lid'; }\n\n    public static function entityName(): string { return 'Lid'; }\n\n    public function open(): void\n    {\n        throw new \\App\\Domain\\{$context}\\Crate\\Exceptions\\CrateSealedException;\n    }\n}\n",
        "Domain/{$context}/Crate/CrateRepository.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate;\n\ninterface CrateRepository {}\n",
        "Domain/{$context}/Pallet/PalletEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Pallet;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class PalletEntity extends AggregateRoot\n{\n    public function id(): string { return 'pallet'; }\n\n    public static function entityName(): string { return 'Pallet'; }\n}\n",
        "Domain/{$other}/Shelf/ShelfEntity.php" => "<?php\n\nnamespace App\\Domain\\{$other}\\Shelf;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class ShelfEntity extends AggregateRoot\n{\n    public function id(): string { return 'shelf'; }\n\n    public static function entityName(): string { return 'Shelf'; }\n}\n",
        "Domain/{$other}/Shelf/ShelfRepository.php" => "<?php\n\nnamespace App\\Domain\\{$other}\\Shelf;\n\ninterface ShelfRepository {}\n",
        "Domain/{$context}/Services/PackCrate/PackCrateData.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\PackCrate;\n\nfinal class PackCrateData {}\n",
        "Domain/{$context}/Services/PackCrate/PackCrateService.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\PackCrate;\n\nuse App\\Domain\\{$context}\\Crate\\CrateEntity;\nuse App\\Domain\\{$context}\\Crate\\CrateRepository;\n\nfinal class PackCrateService\n{\n    public function __construct(protected CrateRepository \$repo) {}\n\n    public function handle(string \$id, PackCrateData \$data): CrateEntity { return new CrateEntity; }\n}\n",
        "Domain/{$context}/Services/WeighCrate/WeighCrateService.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\WeighCrate;\n\nfinal class WeighCrateService\n{\n    public function handle(string \$id): int { return 0; }\n}\n",
        "Domain/{$context}/Ports/SamplingScale.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Ports;\n\ninterface SamplingScale {}\n",
        "Application/{$context}/SamplingLabeler.php" => "<?php\n\nnamespace App\\Application\\{$context};\n\ninterface SamplingLabeler {}\n",
        "Application/{$context}/CrateListSort.php" => "<?php\n\nnamespace App\\Application\\{$context};\n\nenum CrateListSort: string { case Name = 'name'; }\n",
        "Infra/{$context}/SamplingReaderScale.php" => "<?php\n\nnamespace App\\Infra\\{$context};\n\nuse App\\Domain\\{$context}\\Ports\\SamplingScale;\n\nfinal class SamplingReaderScale implements SamplingScale {}\n",
        "Infra/{$context}/SamplingReaderServiceProvider.php" => "<?php\n\nnamespace App\\Infra\\{$context};\n\nuse App\\Domain\\{$context}\\Ports\\SamplingScale;\nuse Illuminate\\Support\\ServiceProvider;\n\nclass SamplingReaderServiceProvider extends ServiceProvider\n{\n    public array \$bindings = [SamplingScale::class => SamplingReaderScale::class];\n}\n",
        "Application/{$context}/UseCases/ListCrates/ListCratesCommand.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\ListCrates;\n\nfinal class ListCratesCommand {}\n",
        "Application/{$context}/UseCases/ListCrates/ListCratesResult.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\ListCrates;\n\nfinal class ListCratesResult {}\n",
        "Application/{$context}/UseCases/ListCrates/ListCratesQuery.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\ListCrates;\n\ninterface ListCratesQuery {}\n",
        "Application/{$context}/UseCases/ListCrates/ListCratesHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\ListCrates;\n\nfinal class ListCratesHandler\n{\n    public function __construct(protected ListCratesQuery \$query) {}\n\n    public function __invoke(ListCratesCommand \$command): ListCratesResult { return new ListCratesResult; }\n}\n",
        "Application/{$context}/UseCases/CreateCrate/CreateCrateCommand.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\CreateCrate;\n\nfinal class CreateCrateCommand {}\n",
        "Application/{$context}/UseCases/CreateCrate/CreateCrateHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\CreateCrate;\n\nuse App\\Domain\\{$context}\\Crate\\CrateRepository;\nuse App\\Domain\\{$other}\\Shelf\\ShelfRepository;\nuse App\\Domain\\Shared\\Ports\\IdGenerator;\n\nfinal class CreateCrateHandler\n{\n    public function __construct(protected ShelfRepository \$shelves, protected CrateRepository \$repo, protected IdGenerator \$ids) {}\n\n    public function __invoke(CreateCrateCommand \$command): string { return ''; }\n}\n",
        "Application/{$context}/UseCases/PurgeCratesHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases;\n\nfinal class PurgeCratesHandler\n{\n    public function __invoke(): int { return 0; }\n}\n",
        "Domain/{$context}/Crate/Enums/CrateGrade.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Enums;\n\nenum CrateGrade: string\n{\n    case Top = 'top';\n    case Low = 'low';\n}\n",
        "Domain/{$context}/Crate/Enums/CrateStatus.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Enums;\n\nuse App\\Domain\\Shared\\Concerns\\HasTransitions;\n\nenum CrateStatus: string\n{\n    use HasTransitions;\n\n    case Open = 'open';\n    case Sealed = 'sealed';\n    case Shipped = 'shipped';\n\n    public function transitions(): array\n    {\n        return match (\$this) {\n            self::Open => [self::Shipped, self::Sealed],\n            self::Sealed => [self::Shipped],\n            self::Shipped => [],\n        };\n    }\n}\n",
        "Domain/{$context}/Crate/Enums/CrateSide.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Enums;\n\nenum CrateSide\n{\n    case Left;\n    case Right;\n}\n",
        "Domain/{$context}/Pallet/Enums/PalletSize.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Pallet\\Enums;\n\nenum PalletSize: int\n{\n    case Half = 50;\n    case Full = 100;\n}\n",
        "Domain/{$context}/Pallet/Enums/CrateGrade.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Pallet\\Enums;\n\nenum CrateGrade: string\n{\n    case Any = 'any';\n}\n",
        "Domain/{$other}/Shelf/Enums/ShelfKind.php" => "<?php\n\nnamespace App\\Domain\\{$other}\\Shelf\\Enums;\n\nenum ShelfKind: string\n{\n    case Wall = 'wall';\n}\n",
        "Domain/{$context}/Crate/ValueObjects/CrateLabel.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\ValueObjects;\n\nuse App\\Domain\\{$context}\\Crate\\Enums\\CrateGrade;\nuse App\\Domain\\{$other}\\Shelf\\Enums\\ShelfKind;\nuse App\\Domain\\Shared\\ValueObjects\\Money;\nuse App\\Domain\\{$context}\\Crate\\Exceptions\\CrateWeightException;\nuse DateTimeImmutable;\n\nfinal class CrateLabel\n{\n    private string \$note;\n\n    private function __construct(private CrateGrade \$grade, ?string \$note, private Money \$price, private ShelfKind \$kind, private DateTimeImmutable \$at, private int|string \$size)\n    {\n        \$this->note = (string) \$note;\n    }\n\n    public static function blank(): self { throw new CrateWeightException; }\n\n    public function regrade(CrateGrade \$grade, string ...\$tags): self { \$this->assertPriced(); return \$this; }\n\n    public function stamp(): static { return \$this; }\n\n    public function grade(): CrateGrade { return \$this->grade; }\n\n    public function assertPriced(): void { throw new CrateWeightException; }\n}\n",
        "Domain/{$context}/Crate/ValueObjects/CrateMark.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\ValueObjects;\n\nabstract class CrateMark {}\n",
        "Domain/{$context}/Crate/ValueObjects/Concerns/ReadsCrateMarks.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\ValueObjects\\Concerns;\n\ntrait ReadsCrateMarks {}\n",
        "Domain/{$context}/Pallet/ValueObjects/PalletSpot.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Pallet\\ValueObjects;\n\nfinal class PalletSpot {}\n",
        'Domain/Shared/Enums/SamplingReaderTone.php' => "<?php\n\nnamespace App\\Domain\\Shared\\Enums;\n\nenum SamplingReaderTone: string\n{\n    case Loud = 'loud';\n}\n",
        'Domain/Shared/ValueObjects/SamplingReaderSpan.php' => "<?php\n\nnamespace App\\Domain\\Shared\\ValueObjects;\n\nuse App\\Domain\\Shared\\Enums\\SamplingReaderTone;\n\nfinal class SamplingReaderSpan\n{\n    public function __construct(private SamplingReaderTone \$tone, private Money \$price) {}\n}\n",
        "Domain/{$context}/Exceptions/{$context}DomainException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Exceptions;\n\nabstract class {$context}DomainException extends \\App\\Domain\\Shared\\DomainException {}\n",
        "Domain/{$context}/Crate/Exceptions/CrateLostException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Exceptions;\n\nuse App\\Domain\\{$context}\\Exceptions\\{$context}DomainException;\n\nfinal class CrateLostException extends {$context}DomainException {}\n",
        "Domain/{$context}/Crate/Exceptions/CrateWeightException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Exceptions;\n\nuse App\\Domain\\Shared\\Exceptions\\DomainValueException;\n\nfinal class CrateWeightException extends DomainValueException {}\n",
        "Domain/{$context}/Crate/Exceptions/CrateNotFoundException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Crate\\Exceptions;\n\nuse App\\Domain\\Shared\\Exceptions\\EntityNotFoundException;\n\nfinal class CrateNotFoundException extends EntityNotFoundException {}\n",
        "Domain/{$context}/Services/PackCrate/PackCrateException.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Services\\PackCrate;\n\nfinal class PackCrateException extends \\App\\Domain\\Shared\\DomainException {}\n",
        "Application/{$context}/CrateQuotaException.php" => "<?php\n\nnamespace App\\Application\\{$context};\n\nuse App\\Application\\ApplicationException;\n\nfinal class CrateQuotaException extends ApplicationException {}\n",
        "Application/{$context}/UseCases/CreateCrate/CrateTakenException.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases\\CreateCrate;\n\nuse App\\Application\\ApplicationException;\n\nfinal class CrateTakenException extends ApplicationException {}\n",
        'Domain/Shared/Exceptions/SamplingReaderSpanException.php' => "<?php\n\nnamespace App\\Domain\\Shared\\Exceptions;\n\nfinal class SamplingReaderSpanException extends DomainValueException {}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(app_path($relative)));
        File::put(app_path($relative), $contents);
    }
}

/**
 * One HTTP resource of every kind the manifest lists: a model with a policy, a resource controller
 * with a method outside the resource ones, a row action with its bulk twin and one without, a
 * controller named after no model, and a page of each kind.
 */
function writeSamplingReaderHttpFixtures(): void
{
    $context = SAMPLING_READER_CONTEXT;

    $fixtures = [
        'app/Models/SamplingTray.php' => "<?php\n\nnamespace App\\Models;\n\nuse App\\Policies\\SamplingTrayPolicy;\nuse Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy;\nuse Illuminate\\Database\\Eloquent\\Model;\n\n#[UsePolicy(SamplingTrayPolicy::class)]\nclass SamplingTray extends Model {}\n",
        'app/Policies/SamplingTrayPolicy.php' => "<?php\n\nnamespace App\\Policies;\n\nuse App\\Models\\User;\n\nclass SamplingTrayPolicy\n{\n    public function before(User \$user, string \$ability): ?bool { return null; }\n\n    public function viewAny(User \$user): bool { return true; }\n\n    public function ship(User \$user): bool { return true; }\n}\n",
        'app/Http/Controllers/SamplingTrayController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Application\\{$context}\\UseCases\\ListCrates\\ListCratesHandler;\n\nclass SamplingTrayController\n{\n    public function index(ListCratesHandler \$listCrates): void { \\Inertia\\Inertia::render('sampling-trays/index'); }\n\n    public function create(): void { \\Inertia\\Inertia::render('sampling-trays/create'); }\n\n    public function show(): void { \\Inertia\\Inertia::render('sampling-trays/show'); \\Inertia\\Inertia::render('sampling-trays/missing'); }\n\n    public function export(): void {}\n}\n",
        'app/Http/Controllers/SamplingTrayShipController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Application\\{$context}\\UseCases\\CreateCrate\\CreateCrateHandler;\n\nclass SamplingTrayShipController\n{\n    public function __invoke(CreateCrateHandler \$createCrate): void {}\n}\n",
        'app/Http/Controllers/SamplingTrayBulkShipController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Application\\{$context}\\UseCases\\CreateCrate\\CreateCrateHandler;\n\nclass SamplingTrayBulkShipController\n{\n    public function __invoke(CreateCrateHandler \$createCrate): void {}\n}\n",
        'app/Http/Controllers/SamplingTrayStackController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass SamplingTrayStackController\n{\n    public function __invoke(): void {}\n}\n",
        'app/Http/Controllers/SamplingReportController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass SamplingReportController\n{\n    public function index(): void { \\Inertia\\Inertia::render('sampling-reports/index'); }\n}\n",
        'resources/js/pages/sampling-trays/index.tsx' => "export default function Index() {\n    const dt = useDataTable({});\n\n    return dt;\n}\n",
        'resources/js/pages/sampling-trays/create.tsx' => "import { SamplingTrayForm } from '@/components/sampling-tray/form';\n\nexport default SamplingTrayForm;\n",
        'resources/js/pages/sampling-trays/show.tsx' => "export default function Show() {\n    return null;\n}\n",
        'resources/js/pages/sampling-reports/index.tsx' => "export default function Index() {\n    return <InfiniteScroll data=\"reports\" />;\n}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $contents);
    }
}

function forgetSamplingReaderFixtures(): void
{
    File::delete(array_map(base_path(...), [
        'app/Models/SamplingTray.php',
        'app/Policies/SamplingTrayPolicy.php',
        'app/Http/Controllers/SamplingTrayController.php',
        'app/Http/Controllers/SamplingTrayShipController.php',
        'app/Http/Controllers/SamplingTrayBulkShipController.php',
        'app/Http/Controllers/SamplingTrayStackController.php',
        'app/Http/Controllers/SamplingReportController.php',
    ]));
    File::delete(app_path('Domain/Shared/Enums/SamplingReaderTone.php'));
    File::delete(app_path('Domain/Shared/ValueObjects/SamplingReaderSpan.php'));
    File::delete(app_path('Domain/Shared/Exceptions/SamplingReaderSpanException.php'));
    File::deleteDirectory(resource_path('js/pages/sampling-trays'));
    File::deleteDirectory(resource_path('js/pages/sampling-reports'));

    foreach ([SAMPLING_READER_CONTEXT, SAMPLING_READER_OTHER] as $context) {
        File::deleteDirectory(app_path("Domain/{$context}"));
        File::deleteDirectory(app_path("Application/{$context}"));
        File::deleteDirectory(app_path("Infra/{$context}"));
    }
}

beforeEach(function () {
    writeSamplingReaderFixtures();
    writeSamplingReaderHttpFixtures();
    $this->reader = new StructureReader(base_path());
});

afterEach(fn () => forgetSamplingReaderFixtures());

describe('StructureReader', function () {
    describe('contexts', function () {
        it('lists the contexts under app/Domain and app/Application, the kit\'s own aside but the shared kernel', function () {
            expect($this->reader->contexts())
                ->toContain(SAMPLING_READER_CONTEXT, SAMPLING_READER_OTHER, 'Shared')
                ->not->toContain('Audit', 'Auth', 'Concerns');
        });
    });

    describe('read', function () {
        it('reads each aggregate with its child entities and whether it has a repository', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['aggregates'])->toBe([
                'Crate' => ['children' => ['Lid'], 'repository' => true],
                'Pallet' => ['children' => [], 'repository' => false],
            ]);
        });

        it('reads each domain service with its shape and the repositories it injects', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['services'])->toBe([
                'PackCrate' => ['shape' => 'creates', 'creates' => 'Crate', 'repositories' => ['Crate'], 'exception' => true],
                'WeighCrate' => ['shape' => 'plain', 'creates' => null, 'repositories' => [], 'exception' => false],
            ]);
        });

        it('reads each port with its layer and the adapter a provider binds it to', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['ports'])->toBe([
                'SamplingLabeler' => ['layer' => 'application', 'adapter' => null],
                'SamplingScale' => ['layer' => 'domain', 'adapter' => 'Infra/SamplingReader/SamplingReaderScale'],
            ]);
        });

        it('reads each use case with its shape, and names a repository of another context in full', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['useCases'])->toBe([
                'CreateCrate' => ['shape' => 'command', 'returns' => 'string', 'creates' => true, 'query' => false, 'repositories' => ['Crate', SAMPLING_READER_OTHER.'/Shelf']],
                'ListCrates' => ['shape' => 'command-result', 'returns' => 'result', 'creates' => false, 'query' => true, 'repositories' => []],
                'PurgeCrates' => ['shape' => 'plain', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => []],
            ]);
        });

        it('reads a context with nothing in it as empty sections', function () {
            expect($this->reader->read('SamplingReaderNowhere'))->toBe([
                'context' => 'SamplingReaderNowhere',
                'aggregates' => [],
                'services' => [],
                'ports' => [],
                'useCases' => [],
                'enums' => [],
                'valueObjects' => [],
                'exceptions' => [],
                'entities' => [],
            ]);
        });

        it('reads the state, behaviours and assertions of each entity, with its parameters in order and what it throws, however deep', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['entities'])->toBe([
                'Crate' => [
                    'aggregate' => 'Crate',
                    'state' => ['grade' => 'CrateGrade', 'weight' => 'int', 'label' => '?string'],
                    'behaviours' => [
                        'price' => ['params' => ['price' => 'Shared/Money'], 'throws' => ['Shared/InvalidMoneyException']],
                        'seal' => ['params' => ['grade' => 'CrateGrade', 'note' => '?string', 'tags' => '...string'], 'throws' => ['CrateEmptyException', 'CrateSealedException']],
                    ],
                    'assertions' => [
                        'assertIsOpen' => ['params' => [], 'throws' => ['CrateSealedException']],
                    ],
                ],
                'Lid' => [
                    'aggregate' => 'Crate',
                    'state' => [],
                    'behaviours' => ['open' => ['params' => [], 'throws' => ['CrateSealedException']]],
                    'assertions' => [],
                ],
            ]);
        });

        it('reads the state of an entity from its constructor, but for its id, and names each property no getter returns', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['entities']['Crate']['state'])->toBe(['grade' => 'CrateGrade', 'weight' => 'int', 'label' => '?string'])
                ->and($this->reader->stateWithoutGetter(SAMPLING_READER_CONTEXT))->toBe(['Crate' => ['label']]);
        });

        it('reads each enum of an aggregate with its backing, its cases in order, and where a status may go next in case order', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['enums'])->toBe([
                'CrateGrade' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Top' => 'top', 'Low' => 'low'], 'transitions' => null],
                'CrateSide' => ['aggregate' => 'Crate', 'backing' => null, 'cases' => ['Left' => null, 'Right' => null], 'transitions' => null],
                'CrateStatus' => ['aggregate' => 'Crate', 'backing' => 'string', 'cases' => ['Open' => 'open', 'Sealed' => 'sealed', 'Shipped' => 'shipped'], 'transitions' => [
                    'Open' => ['Sealed', 'Shipped'],
                    'Sealed' => ['Shipped'],
                    'Shipped' => [],
                ]],
                'PalletSize' => ['aggregate' => 'Pallet', 'backing' => 'int', 'cases' => ['Half' => 50, 'Full' => 100], 'transitions' => null],
            ]);
        });

        it('reads each value object with its constructor in order, naming each type from where it lives, and its behaviours (returning a new one) and assertions', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['valueObjects'])->toBe([
                'CrateLabel' => ['aggregate' => 'Crate', 'fields' => [
                    'grade' => 'CrateGrade',
                    'note' => '?string',
                    'price' => 'Shared/Money',
                    'kind' => SAMPLING_READER_OTHER.'/Shelf/ShelfKind',
                    'at' => 'DateTimeImmutable',
                    'size' => 'string|int',
                ], 'behaviours' => [
                    'regrade' => ['params' => ['grade' => 'CrateGrade', 'tags' => '...string'], 'throws' => ['CrateWeightException']],
                    'stamp' => ['params' => [], 'throws' => []],
                ], 'assertions' => [
                    'assertPriced' => ['params' => [], 'throws' => ['CrateWeightException']],
                ]],
                'PalletSpot' => ['aggregate' => 'Pallet', 'fields' => [], 'behaviours' => [], 'assertions' => []],
            ]);
        });

        it('reads only the enums and value objects of the shared kernel, the kit\'s own aside', function () {
            $shared = $this->reader->read('Shared');

            expect($shared['enums']['SamplingReaderTone'])->toBe(['aggregate' => null, 'backing' => 'string', 'cases' => ['Loud' => 'loud'], 'transitions' => null])
                ->and($shared['valueObjects']['SamplingReaderSpan'])->toBe(['aggregate' => null, 'fields' => ['tone' => 'SamplingReaderTone', 'price' => 'Money'], 'behaviours' => [], 'assertions' => []])
                ->and($shared['valueObjects'])->not->toHaveKeys(['Money', 'Percent'])
                ->and([$shared['aggregates'], $shared['services'], $shared['ports'], $shared['useCases']])->toBe([[], [], [], []]);
        });
    });

    describe('exceptions', function () {
        it('reads each refusal, invalid value and use case refusal by kind and home, and leaves out what another generator owns', function () {
            expect($this->reader->read(SAMPLING_READER_CONTEXT)['exceptions'])->toBe([
                'CrateLostException' => ['kind' => 'refusal', 'aggregate' => 'Crate', 'useCase' => null],
                'CrateQuotaException' => ['kind' => 'application', 'aggregate' => null, 'useCase' => null],
                'CrateTakenException' => ['kind' => 'application', 'aggregate' => null, 'useCase' => 'CreateCrate'],
                'CrateWeightException' => ['kind' => 'value', 'aggregate' => 'Crate', 'useCase' => null],
            ]);
        });

        it('reads the shared kernel\'s invalid values, the kit\'s own aside', function () {
            expect($this->reader->read('Shared')['exceptions'])->toHaveKey('SamplingReaderSpanException')
                ->and($this->reader->read('Shared')['exceptions']['SamplingReaderSpanException'])->toBe(['kind' => 'value', 'aggregate' => null, 'useCase' => null])
                ->and(array_keys($this->reader->read('Shared')['exceptions']))->not->toContain(...StructureReader::KIT_SHARED_EXCEPTIONS);
        });

        it('names the class of an exception, wherever it lives', function () {
            $context = SAMPLING_READER_CONTEXT;

            expect($this->reader->classOf($context, 'exceptions', 'CrateLostException'))->toBe("App\\Domain\\{$context}\\Crate\\Exceptions\\CrateLostException")
                ->and($this->reader->classOf($context, 'exceptions', 'CrateTakenException'))->toBe("App\\Application\\{$context}\\UseCases\\CreateCrate\\CrateTakenException");
        });

        it('lets a manifest written before exceptions read what it predates from the code, so a project that has them stays as it was', function () {
            $context = SAMPLING_READER_CONTEXT;
            $path = base_path(".kit/structure/{$context}.json");
            File::put($path, (string) json_encode([
                'context' => $context,
                'aggregates' => [], 'ports' => [], 'useCases' => [], 'enums' => [], 'valueObjects' => [], 'entities' => [],
                'services' => [
                    'PackCrate' => ['shape' => 'creates', 'creates' => 'Crate', 'repositories' => ['Crate']],
                    'WeighCrate' => ['shape' => 'plain', 'creates' => null, 'repositories' => []],
                ],
            ]));

            try {
                $read = (new StructureFiles(base_path()))->read($context);
            } finally {
                File::delete($path);
            }

            expect($read['exceptions'])->toBe($this->reader->read($context)['exceptions'])
                ->and($read['exceptions'])->toHaveKey('CrateLostException')
                ->and($read['services']['PackCrate']['exception'])->toBeTrue()
                ->and($read['services']['WeighCrate']['exception'])->toBeFalse();
        });
    });

    describe('clashes', function () {
        it('names an enum two aggregates of one context both declare', function () {
            expect($this->reader->clashes(SAMPLING_READER_CONTEXT))->toBe(['enums' => ['CrateGrade' => ['Crate', 'Pallet']]]);
        });
    });

    describe('vocabularyClass', function () {
        it('finds the class a field\'s type names, in the same aggregate, the shared kernel, another aggregate or anywhere', function () {
            $context = SAMPLING_READER_CONTEXT;

            expect($this->reader->vocabularyClass('CrateGrade', $context, 'Crate'))->toBe("App\\Domain\\{$context}\\Crate\\Enums\\CrateGrade")
                ->and($this->reader->vocabularyClass('CrateEntity', $context, 'Crate'))->toBe("App\\Domain\\{$context}\\Crate\\CrateEntity")
                ->and($this->reader->vocabularyClass('LidEntity', $context, 'Crate'))->toBe("App\\Domain\\{$context}\\Crate\\Entities\\LidEntity")
                ->and($this->reader->vocabularyClass('Shared/Money', $context, 'Crate'))->toBe('App\\Domain\\Shared\\ValueObjects\\Money')
                ->and($this->reader->vocabularyClass('Money', 'Shared', null))->toBe('App\\Domain\\Shared\\ValueObjects\\Money')
                ->and($this->reader->vocabularyClass(SAMPLING_READER_OTHER.'/Shelf/ShelfKind', $context, 'Crate'))->toBe('App\\Domain\\'.SAMPLING_READER_OTHER.'\\Shelf\\Enums\\ShelfKind')
                ->and($this->reader->vocabularyClass('DateTimeImmutable', $context, 'Crate'))->toBe('DateTimeImmutable');
        });

        it('finds nothing for a type that is not built yet', function () {
            expect($this->reader->vocabularyClass('CrateColour', SAMPLING_READER_CONTEXT, 'Crate'))->toBeNull()
                ->and($this->reader->vocabularyClass('Shared/Nowhere', SAMPLING_READER_CONTEXT, 'Crate'))->toBeNull()
                ->and($this->reader->vocabularyClass('Too/Many/Parts/Here', SAMPLING_READER_CONTEXT, 'Crate'))->toBeNull();
        });
    });

    describe('exceptionClass', function () {
        it('finds the exception a method\'s throws names, in its aggregate or the shared kernel', function () {
            $context = SAMPLING_READER_CONTEXT;

            expect($this->reader->exceptionClass('CrateSealedException', $context, 'Crate'))->toBe("App\\Domain\\{$context}\\Crate\\Exceptions\\CrateSealedException")
                ->and($this->reader->exceptionClass('Shared/InvalidMoneyException', $context, 'Crate'))->toBe('App\\Domain\\Shared\\Exceptions\\InvalidMoneyException')
                ->and($this->reader->exceptionClass('CrateStolenException', $context, 'Crate'))->toBeNull()
                ->and($this->reader->exceptionClass('CrateGrade', $context, 'Crate'))->toBeNull();
        });
    });

    describe('classOf', function () {
        it('names the class a manifest entry stands for', function () {
            expect($this->reader->classOf(SAMPLING_READER_CONTEXT, 'useCases', 'PurgeCrates'))
                ->toBe('App\Application\\'.SAMPLING_READER_CONTEXT.'\UseCases\PurgeCratesHandler')
                ->and($this->reader->classOf(SAMPLING_READER_CONTEXT, 'ports', 'SamplingLabeler'))
                ->toBe('App\Application\\'.SAMPLING_READER_CONTEXT.'\SamplingLabeler')
                ->and($this->reader->classOf(SAMPLING_READER_CONTEXT, 'enums', 'PalletSize'))
                ->toBe('App\Domain\\'.SAMPLING_READER_CONTEXT.'\Pallet\Enums\PalletSize')
                ->and($this->reader->classOf(SAMPLING_READER_CONTEXT, 'entities', 'Lid'))
                ->toBe('App\Domain\\'.SAMPLING_READER_CONTEXT.'\Crate\Entities\LidEntity');
        });
    });

    describe('resources', function () {
        it('names a resource after its model, folds the actions into it, and keeps a controller with no model', function () {
            expect($this->reader->resources())
                ->toContain('SamplingTray', 'SamplingReport')
                ->not->toContain('SamplingTrayShip', 'SamplingTrayBulkShip', 'AuditEntry');
        });
    });

    describe('readResource', function () {
        it('reads the controller, the actions, the policy and the pages of a model', function () {
            expect($this->reader->readResource('SamplingTray'))->toBe([
                'resource' => 'SamplingTray',
                'model' => 'SamplingTray',
                'controller' => [
                    'create' => [],
                    'export' => [],
                    'index' => [SAMPLING_READER_CONTEXT.'/ListCrates'],
                    'show' => [],
                ],
                'actions' => [
                    'Ship' => ['row' => true, 'bulk' => true, 'useCases' => [SAMPLING_READER_CONTEXT.'/CreateCrate']],
                    'Stack' => ['row' => true, 'bulk' => false, 'useCases' => []],
                ],
                'policy' => ['ship', 'viewAny'],
                'pages' => [
                    'sampling-trays/create' => 'form',
                    'sampling-trays/index' => 'table',
                    'sampling-trays/show' => 'page',
                ],
            ]);
        });

        it('reads a controller named after no model, with no policy', function () {
            expect($this->reader->readResource('SamplingReport'))->toBe([
                'resource' => 'SamplingReport',
                'model' => null,
                'controller' => ['index' => []],
                'actions' => [],
                'policy' => null,
                'pages' => ['sampling-reports/index' => 'grid'],
            ]);
        });

        it('names the class a resource stands for', function () {
            expect($this->reader->resourceClass('SamplingTray'))->toBe('App\\Http\\Controllers\\SamplingTrayController')
                ->and($this->reader->resourceClass('SamplingTrayNowhere'))->toBe('App\\Models\\SamplingTrayNowhere');
        });
    });
});
