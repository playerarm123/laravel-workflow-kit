<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureSync;

/**
 * The scratch context and resource of this file. They carry `Sampling` (testing.md), so no
 * Architecture check reads them, and differ from every other test file's, so --parallel never
 * deletes them mid-run. A class loads once per process, so the code stays the same in every case
 * and each case changes the manifest instead.
 */
const SAMPLING_SYNC_CONTEXT = 'SamplingSync';

const SAMPLING_SYNC_RESOURCE = 'SamplingSyncBin';

function writeSamplingSyncFixtures(): void
{
    $context = SAMPLING_SYNC_CONTEXT;
    $resource = SAMPLING_SYNC_RESOURCE;

    $fixtures = [
        "app/Domain/{$context}/Bin/BinEntity.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Bin;\n\nuse App\\Domain\\Shared\\AggregateRoot;\n\nfinal class BinEntity extends AggregateRoot\n{\n    private function __construct(private string \$id, private int \$size, private ?string \$label) {}\n\n    public function id(): string { return \$this->id; }\n\n    public static function entityName(): string { return 'Bin'; }\n\n    public function fill(int \$amount, string \$note): void {}\n\n    public function assertOpen(): void {}\n}\n",
        "app/Domain/{$context}/Bin/Enums/BinStatus.php" => "<?php\n\nnamespace App\\Domain\\{$context}\\Bin\\Enums;\n\nenum BinStatus: string\n{\n    case Open = 'open';\n    case Full = 'full';\n}\n",
        "app/Application/{$context}/UseCases/EmptyBinsHandler.php" => "<?php\n\nnamespace App\\Application\\{$context}\\UseCases;\n\nfinal class EmptyBinsHandler\n{\n    public function __invoke(): int { return 0; }\n}\n",
        "app/Models/{$resource}.php" => "<?php\n\nnamespace App\\Models;\n\nuse App\\Policies\\{$resource}Policy;\nuse Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy;\nuse Illuminate\\Database\\Eloquent\\Model;\n\n#[UsePolicy({$resource}Policy::class)]\nclass {$resource} extends Model {}\n",
        "app/Policies/{$resource}Policy.php" => "<?php\n\nnamespace App\\Policies;\n\nuse App\\Models\\User;\n\nclass {$resource}Policy\n{\n    public function viewAny(User \$user): bool { return true; }\n\n    public function delete(User \$user): bool { return true; }\n}\n",
        "app/Http/Controllers/{$resource}Controller.php" => "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Application\\{$context}\\UseCases\\EmptyBinsHandler;\n\nclass {$resource}Controller\n{\n    public function destroy(EmptyBinsHandler \$emptyBins): void {}\n}\n",
    ];

    foreach ($fixtures as $relative => $contents) {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $contents);
    }
}

function forgetSamplingSyncFixtures(): void
{
    $resource = SAMPLING_SYNC_RESOURCE;

    File::deleteDirectory(app_path('Domain/'.SAMPLING_SYNC_CONTEXT));
    File::deleteDirectory(app_path('Application/'.SAMPLING_SYNC_CONTEXT));
    File::delete([
        app_path("Models/{$resource}.php"),
        app_path("Policies/{$resource}Policy.php"),
        app_path("Http/Controllers/{$resource}Controller.php"),
        base_path('.kit/structure/'.SAMPLING_SYNC_CONTEXT.'.json'),
        base_path('.kit/structure/http/'.SAMPLING_SYNC_RESOURCE.'.json'),
    ]);
}

/**
 * Writes the context's manifest as the code reads, changed by `$change`.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $change
 */
function designSamplingSync(Closure $change): void
{
    $files = new StructureFiles(base_path());
    $files->write($change((new StructureReader(base_path()))->read(SAMPLING_SYNC_CONTEXT)));
}

/**
 * Writes the resource's manifest as the code reads, changed by `$change`.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $change
 */
function designSamplingSyncResource(Closure $change): void
{
    $files = new StructureFiles(base_path());
    $files->writeResource($change((new StructureReader(base_path()))->readResource(SAMPLING_SYNC_RESOURCE)));
}

beforeEach(function () {
    forgetSamplingSyncFixtures();
    writeSamplingSyncFixtures();
    $this->reader = new StructureReader(base_path());
    $this->sync = new StructureSync(new StructureFiles(base_path()), $this->reader);
});

afterEach(fn () => forgetSamplingSyncFixtures());

describe('StructureSync', function () {
    describe('syncContext', function () {
        it('takes every built piece from the code, and keeps what only the manifest lists', function () {
            designSamplingSync(function (array $manifest): array {
                $manifest['enums']['BinStatus']['cases'] = ['Open' => 'open'];
                $manifest['entities']['Bin']['behaviours']['fill']['params'] = ['amount' => 'int'];
                $manifest['entities']['Bin']['behaviours']['drain'] = ['params' => [], 'throws' => []];
                $manifest['useCases']['RefillBins'] = ['shape' => 'plain', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => []];

                return $manifest;
            });

            $synced = $this->sync->syncContext(SAMPLING_SYNC_CONTEXT);

            expect($synced['manifest']['enums']['BinStatus']['cases'])->toBe(['Open' => 'open', 'Full' => 'full'])
                ->and($synced['manifest']['entities']['Bin']['behaviours']['fill']['params'])->toBe(['amount' => 'int', 'note' => 'string'])
                ->and($synced['manifest']['entities']['Bin']['behaviours'])->toHaveKey('drain')
                ->and($synced['manifest']['useCases'])->toHaveKey('RefillBins')
                ->and($synced['changes'])->toBe([
                    'enums.BinStatus: cases {"Open":"open"} → {"Open":"open","Full":"full"}',
                    'entities.Bin.fill: params {"amount":"int"} → {"amount":"int","note":"string"}',
                ])
                ->and($synced['kept'])->toBe(['useCases.RefillBins', 'entities.Bin.drain']);
        });

        it('takes out what only the manifest lists when it prunes, and an entity left with no method', function () {
            designSamplingSync(function (array $manifest): array {
                $manifest['useCases']['RefillBins'] = ['shape' => 'plain', 'returns' => 'int', 'creates' => false, 'query' => false, 'repositories' => []];
                $manifest['entities']['Lid'] = ['aggregate' => 'Bin', 'behaviours' => ['open' => ['params' => [], 'throws' => []]], 'assertions' => []];

                return $manifest;
            });

            $synced = $this->sync->syncContext(SAMPLING_SYNC_CONTEXT, prune: true);

            expect($synced['manifest']['useCases'])->not->toHaveKey('RefillBins')
                ->and($synced['manifest']['entities'])->not->toHaveKey('Lid')
                ->and($synced['changes'])->toBe(['useCases.RefillBins: removed', 'entities.Lid.open: removed'])
                ->and($synced['kept'])->toBe([]);
        });

        it('keeps a child the manifest designs for a built aggregate, and takes it out when it prunes', function () {
            designSamplingSync(function (array $manifest): array {
                $manifest['aggregates']['Bin']['children'] = ['Lid'];

                return $manifest;
            });

            $synced = $this->sync->syncContext(SAMPLING_SYNC_CONTEXT);
            $pruned = $this->sync->syncContext(SAMPLING_SYNC_CONTEXT, prune: true);

            expect($synced['manifest']['aggregates']['Bin']['children'])->toBe(['Lid'])
                ->and($synced['changes'])->toBe([])
                ->and($synced['kept'])->toBe(['aggregates.Bin.children.Lid'])
                ->and($this->sync->syncPiece(SAMPLING_SYNC_CONTEXT, 'aggregates', 'Bin')['manifest']['aggregates']['Bin']['children'])->toBe(['Lid'])
                ->and($pruned['manifest']['aggregates']['Bin']['children'])->toBe([])
                ->and($pruned['changes'])->toBe(['aggregates.Bin.children.Lid: removed', 'aggregates.Bin: children ["Lid"] → []']);
        });

        it('changes nothing when the manifest already says what the code holds', function () {
            designSamplingSync(fn (array $manifest): array => $manifest);

            expect($this->sync->syncContext(SAMPLING_SYNC_CONTEXT)['changes'])->toBe([])
                ->and($this->sync->contextOutOfStep(SAMPLING_SYNC_CONTEXT))->toBe([]);
        });

        it('leaves a piece in the middle of a replacement as the design says, and never prunes it', function () {
            designSamplingSync(function (array $manifest): array {
                $manifest['useCases']['EmptyBins']['returns'] = 'void';
                $manifest['useCases']['ClearBins'] = [...$manifest['useCases']['EmptyBins'], 'replaces' => 'EmptyBins'];

                return $manifest;
            });

            $synced = $this->sync->syncContext(SAMPLING_SYNC_CONTEXT, prune: true);

            expect($synced['manifest']['useCases']['EmptyBins']['returns'])->toBe('void')
                ->and($synced['manifest']['useCases']['ClearBins']['replaces'])->toBe('EmptyBins')
                ->and($synced['changes'])->toBe([]);
        });

        it('takes an entity\'s state from the code in the constructor\'s order, keeping a property not built yet last', function () {
            designSamplingSync(function (array $manifest): array {
                $manifest['entities']['Bin']['state'] = ['label' => 'string', 'size' => 'string', 'colour' => 'int'];

                return $manifest;
            });

            $synced = $this->sync->syncContext(SAMPLING_SYNC_CONTEXT);
            $pruned = $this->sync->syncContext(SAMPLING_SYNC_CONTEXT, prune: true);

            expect($synced['manifest']['entities']['Bin']['state'])->toBe(['size' => 'int', 'label' => '?string', 'colour' => 'int'])
                ->and($synced['changes'])->toBe([
                    'entities.Bin.state.size: "string" → "int"',
                    'entities.Bin.state.label: "string" → "?string"',
                    'entities.Bin.state: order label, size → size, label',
                ])
                ->and($synced['kept'])->toBe(['entities.Bin.state.colour'])
                ->and($this->sync->contextOutOfStep(SAMPLING_SYNC_CONTEXT))->toBe(['entities.Bin.state.size', 'entities.Bin.state.label', 'entities.Bin.state'])
                ->and($pruned['manifest']['entities']['Bin']['state'])->toBe(['size' => 'int', 'label' => '?string'])
                ->and($pruned['changes'])->toContain('entities.Bin.state.colour: removed')
                ->and($this->sync->syncProperty(SAMPLING_SYNC_CONTEXT, 'Bin', 'label')['manifest']['entities']['Bin']['state'])->toBe(['label' => '?string', 'size' => 'string', 'colour' => 'int']);
        });

        it('names the built pieces the code describes another way, but not the ones it would add', function () {
            designSamplingSync(function (array $manifest): array {
                $manifest['enums']['BinStatus']['cases'] = ['Open' => 'open'];
                $manifest['entities']['Bin']['assertions']['assertOpen']['throws'] = ['BinFullException'];
                unset($manifest['useCases']['EmptyBins']);

                return $manifest;
            });

            expect($this->sync->contextOutOfStep(SAMPLING_SYNC_CONTEXT))->toBe(['enums.BinStatus', 'entities.Bin.assertOpen']);
        });
    });

    describe('syncResource', function () {
        it('takes the model, the policy and every built entry from the code, and keeps a page not built yet', function () {
            designSamplingSyncResource(function (array $manifest): array {
                $manifest['policy'] = ['viewAny'];
                $manifest['controller']['destroy'] = [];
                $manifest['pages'] = ['sampling-sync-bins/index' => 'table'];

                return $manifest;
            });

            $synced = $this->sync->syncResource(SAMPLING_SYNC_RESOURCE);

            expect($synced['manifest']['policy'])->toBe(['delete', 'viewAny'])
                ->and($synced['manifest']['controller']['destroy'])->toBe([SAMPLING_SYNC_CONTEXT.'/EmptyBins'])
                ->and($synced['manifest']['pages'])->toBe(['sampling-sync-bins/index' => 'table'])
                ->and($synced['kept'])->toBe(['pages.sampling-sync-bins/index'])
                ->and($this->sync->resourceOutOfStep(SAMPLING_SYNC_RESOURCE))->toBe(['policy', 'controller.destroy']);
        });
    });

    describe('one piece', function () {
        it('takes one piece from the code and nothing else', function () {
            designSamplingSync(function (array $manifest): array {
                $manifest['enums']['BinStatus']['cases'] = ['Open' => 'open'];
                $manifest['entities']['Bin']['behaviours']['fill']['params'] = [];

                return $manifest;
            });

            $piece = $this->sync->syncPiece(SAMPLING_SYNC_CONTEXT, 'enums', 'BinStatus');
            $method = $this->sync->syncMethod(SAMPLING_SYNC_CONTEXT, 'Bin', 'fill');

            expect($piece['manifest']['enums']['BinStatus']['cases'])->toBe(['Open' => 'open', 'Full' => 'full'])
                ->and($piece['manifest']['entities']['Bin']['behaviours']['fill']['params'])->toBe([])
                ->and($method['manifest']['entities']['Bin']['behaviours']['fill']['params'])->toBe(['amount' => 'int', 'note' => 'string'])
                ->and($method['manifest']['enums']['BinStatus']['cases'])->toBe(['Open' => 'open']);
        });

        it('refuses a piece the code does not have', function () {
            designSamplingSync(fn (array $manifest): array => $manifest);
            designSamplingSyncResource(fn (array $manifest): array => $manifest);

            expect($this->sync->syncPiece(SAMPLING_SYNC_CONTEXT, 'useCases', 'RefillBins'))->toBe(['error' => 'The code has no RefillBins, so there is nothing to sync from.'])
                ->and($this->sync->syncMethod(SAMPLING_SYNC_CONTEXT, 'Bin', 'drain'))->toBe(['error' => 'The code has no Bin::drain(), so there is nothing to sync from.'])
                ->and($this->sync->syncResourcePiece(SAMPLING_SYNC_RESOURCE, 'controller', 'index'))->toBe(['error' => 'The code has no index, so there is nothing to sync from.']);
        });

        it('takes one entry of an HTTP resource from the code', function () {
            designSamplingSyncResource(function (array $manifest): array {
                $manifest['policy'] = ['viewAny'];
                $manifest['controller']['destroy'] = [];

                return $manifest;
            });

            $synced = $this->sync->syncResourcePiece(SAMPLING_SYNC_RESOURCE, 'policy', '');

            expect($synced['manifest']['policy'])->toBe(['delete', 'viewAny'])
                ->and($synced['manifest']['controller']['destroy'])->toBe([]);
        });
    });
});
