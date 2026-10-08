<?php

use App\Application\Concerns\DateRange;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

/**
 * Run make:use-case, answering the payload prompt unless the flags already settle it.
 */
function runMakeUseCase(array $arguments, ?string $carries = 'none'): PendingCommand
{
    $command = test()->artisan('make:use-case', $arguments);

    return $carries === null
        ? $command
        : $command->expectsQuestion('What does this use case carry?', $carries);
}

function useCaseClassPath(string $relative): string
{
    return app_path("Application/Sampling/UseCases/{$relative}.php");
}

function useCaseTestFilePath(string $relative): string
{
    return base_path("tests/Feature/Application/Sampling/UseCases/{$relative}.php");
}

function queryAdapterPath(string $adapter): string
{
    return app_path("Infra/Persistence/Eloquent/Queries/{$adapter}.php");
}

function queryAdapterTestPath(string $adapter): string
{
    return base_path("tests/Feature/Infra/Persistence/Eloquent/Queries/{$adapter}Test.php");
}

/**
 * The Queries folders are real, shared directories, so only the files this file generates
 * are removed — deleting the folders would take another --parallel process's fixtures with
 * them, and eventually the hand written adapters too.
 */
afterEach(function () {
    File::deleteDirectory(app_path('Application/Sampling'));
    File::deleteDirectory(base_path('tests/Feature/Application/Sampling'));

    foreach (['EloquentListSamplingItemQuery', 'EloquentListSamplingItemsQuery', 'EloquentListSamplingLoadablesQuery', 'EloquentReadSamplingItemQuery'] as $adapter) {
        File::delete([queryAdapterPath($adapter), queryAdapterTestPath($adapter)]);
    }
});

it('generates the handler in the use cases root when it carries no payload', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])
        ->assertSuccessful();

    expect(File::exists(useCaseClassPath('ArchiveSamplingItemHandler')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ArchiveSamplingItem/ArchiveSamplingItemHandler')))->toBeFalse();
});

it('builds the handler from the use case handler stub', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])->run();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))
        ->toContain('namespace App\Application\Sampling\UseCases;')
        ->toContain('final class ArchiveSamplingItemHandler')
        ->toContain('public function __invoke(): void')
        ->not->toContain('{{');
});

it('omits the constructor when no repository is given', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])->run();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))
        ->not->toContain('__construct')
        ->not->toContain('use App\Domain');
});

it('injects the given repository as a protected property', function () {
    runMakeUseCase([
        'name' => 'ArchiveSamplingItem',
        '--domain' => 'Sampling',
        '--repo' => 'Sample',
    ])->assertSuccessful();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))
        ->toContain('use App\Domain\Sampling\Sample\SampleRepository;')
        ->toContain('protected SampleRepository $repo,');
});

it('strips repository decoration from the given repository name', function () {
    runMakeUseCase([
        'name' => 'ArchiveSamplingItem',
        '--domain' => 'Sampling',
        '--repo' => 'SampleRepository',
    ])->run();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))
        ->toContain('protected SampleRepository $repo,')
        ->not->toContain('SampleRepositoryRepository');
});

it('injects the audit log and records inside one transaction when the handler writes', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling', '--repo' => 'SampleItem'])
        ->assertSuccessful();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))
        ->toContain("use App\\Application\\Audit\\AuditLog;\nuse App\\Domain\\Sampling\\SampleItem\\SampleItemRepository;\nuse Illuminate\\Support\\Facades\\DB;")
        ->toContain('protected AuditLog $audit,')
        ->toContain('DB::transaction(function () {')
        ->toContain("// \$this->audit->record('sample_item.changed', \$aggregate);");
});

it('returns the new id from the transaction when a writing handler creates a root', function () {
    runMakeUseCase(['name' => 'CreateSample', '--domain' => 'Sampling', '--command' => true, '--creates' => true, '--repo' => 'Sample'], carries: null)
        ->assertSuccessful();

    expect(File::get(useCaseClassPath('CreateSample/CreateSampleHandler')))
        ->toContain("\$id = \$this->ids->next();\n\n        return DB::transaction(function () use (\$id) {")
        ->toContain("// \$this->audit->record('sample.created', \$aggregate);")
        ->toContain("            return \$id;\n        });");
});

it('gives a handler that writes nothing neither the audit log nor a transaction', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])->assertSuccessful();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))
        ->not->toContain('AuditLog')
        ->not->toContain('DB::transaction');
});

it('adds the audit case to the test of a handler that writes', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling', '--repo' => 'Sample'])->run();
    runMakeUseCase(['name' => 'ReadSamplingItem', '--domain' => 'Sampling'])->run();

    expect(File::get(useCaseTestFilePath('ArchiveSamplingItemHandlerTest')))
        ->toContain("it('records what it changed in the audit log')->todo();")
        ->not->toContain('{{');

    expect(File::get(useCaseTestFilePath('ReadSamplingItemHandlerTest')))
        ->not->toContain('audit log')
        ->not->toContain('{{');
});

it('nests the handler under its own folder when it carries a command', function () {
    runMakeUseCase(['name' => 'ChangeSample', '--domain' => 'Sampling', '--command' => true], carries: null)
        ->assertSuccessful();

    expect(File::exists(useCaseClassPath('ChangeSample/ChangeSampleHandler')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ChangeSampleHandler')))->toBeFalse();

    expect(File::get(useCaseClassPath('ChangeSample/ChangeSampleHandler')))
        ->toContain('namespace App\Application\Sampling\UseCases\ChangeSample;')
        ->toContain('public function __invoke(ChangeSampleCommand $command): void');
});

it('creates the command class beside the handler', function () {
    runMakeUseCase(['name' => 'ChangeSample', '--domain' => 'Sampling', '--command' => true], carries: null)->run();

    expect(File::get(useCaseClassPath('ChangeSample/ChangeSampleCommand')))
        ->toContain('namespace App\Application\Sampling\UseCases\ChangeSample;')
        ->toContain('use Spatie\LaravelData\Attributes\WithCast;')
        ->toContain('use Spatie\LaravelData\Casts\EnumCast;')
        ->toContain('final class ChangeSampleCommand extends Data')
        ->not->toContain('{{');
});

it('refuses a result without a command, since only a command handler returns one', function () {
    runMakeUseCase(['name' => 'ReadSamplingItem', '--domain' => 'Sampling', '--result' => true], carries: null)
        ->expectsOutputToContain('A Result comes back only from a Command')
        ->assertFailed();

    expect(File::exists(useCaseClassPath('ReadSamplingItem/ReadSamplingItemHandler')))->toBeFalse()
        ->and(File::exists(useCaseClassPath('ReadSamplingItemHandler')))->toBeFalse();
});

it('creates the result class beside the handler when asked for a command and a result', function () {
    runMakeUseCase(['name' => 'ReadSamplingItem', '--domain' => 'Sampling', '--command' => true, '--result' => true], carries: null)->run();

    expect(File::get(useCaseClassPath('ReadSamplingItem/ReadSamplingItemResult')))
        ->toContain('final class ReadSamplingItemResult extends Data')
        ->not->toContain('{{');
});

it('injects the id port and returns the new id when the handler creates a root', function () {
    runMakeUseCase(['name' => 'CreateSample', '--domain' => 'Sampling', '--command' => true, '--creates' => true], carries: null)
        ->assertSuccessful();

    expect(File::get(useCaseClassPath('CreateSample/CreateSampleHandler')))
        ->toContain('use App\Domain\Shared\Ports\IdGenerator;')
        ->toContain('protected IdGenerator $ids,')
        ->toContain('public function __invoke(CreateSampleCommand $command): string')
        ->toContain('$id = $this->ids->next();')
        ->toContain('return $id;');
});

it('lets the result carry the new id when the handler creates a root and returns a result', function () {
    runMakeUseCase([
        'name' => 'CreateSample',
        '--domain' => 'Sampling',
        '--command' => true,
        '--result' => true,
        '--creates' => true,
        '--repo' => 'Sample',
    ], carries: null)->assertSuccessful();

    expect(File::get(useCaseClassPath('CreateSample/CreateSampleHandler')))
        ->toContain("use App\Domain\Sampling\Sample\SampleRepository;\nuse App\Domain\Shared\Ports\IdGenerator;")
        ->toContain('public function __invoke(CreateSampleCommand $command): CreateSampleResult')
        ->toContain('$id = $this->ids->next();')
        ->not->toContain('return $id;');
});

it('creates both payloads when asked for a command and a result', function () {
    runMakeUseCase([
        'name' => 'ChangeSample',
        '--domain' => 'Sampling',
        '--command' => true,
        '--result' => true,
    ], carries: null)->assertSuccessful();

    expect(File::get(useCaseClassPath('ChangeSample/ChangeSampleHandler')))
        ->toContain('public function __invoke(ChangeSampleCommand $command): ChangeSampleResult');

    expect(File::exists(useCaseClassPath('ChangeSample/ChangeSampleCommand')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ChangeSample/ChangeSampleResult')))->toBeTrue();
});

it('creates no payload classes unless asked to', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])->run();

    expect(File::exists(useCaseClassPath('ArchiveSamplingItemCommand')))->toBeFalse()
        ->and(File::exists(useCaseClassPath('ArchiveSamplingItemResult')))->toBeFalse();
});

it('strips the handler suffix from the given name', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItemHandler', '--domain' => 'Sampling'])
        ->assertSuccessful();

    expect(File::exists(useCaseClassPath('ArchiveSamplingItemHandler')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ArchiveSamplingItemHandlerHandler')))->toBeFalse();
});

it('always creates the test mirroring the handler namespace', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])->run();
    runMakeUseCase(['name' => 'ChangeSample', '--domain' => 'Sampling', '--command' => true], carries: null)->run();

    expect(File::exists(useCaseTestFilePath('ArchiveSamplingItemHandlerTest')))->toBeTrue()
        ->and(File::exists(useCaseTestFilePath('ChangeSample/ChangeSampleHandlerTest')))->toBeTrue();

    expect(File::get(useCaseTestFilePath('ChangeSample/ChangeSampleHandlerTest')))
        ->toContain('use App\Application\Sampling\UseCases\ChangeSample\ChangeSampleHandler;')
        ->toContain('$this->handler = app(ChangeSampleHandler::class);')
        ->toContain("describe('ChangeSampleHandler'")
        ->not->toContain('{{');
});

it('asks which domain owns the use case when none is given', function () {
    File::ensureDirectoryExists(app_path('Application/Sampling'));

    $this->artisan('make:use-case', ['name' => 'ArchiveSamplingItem'])
        ->expectsQuestion('Which domain owns this use case?', 'Sampling')
        ->expectsQuestion('What does this use case carry?', 'none')
        ->assertSuccessful();

    expect(File::exists(useCaseClassPath('ArchiveSamplingItemHandler')))->toBeTrue();
});

it('offers the domains found under app/Application and app/Domain', function () {
    File::ensureDirectoryExists(app_path('Application/Sampling'));

    expect(domainChoicesOf('make:use-case'))
        ->toContain('Sampling')
        ->toContain('Catalog')
        ->not->toContain('Shared');

    $this->artisan('make:use-case', ['name' => 'ArchiveSamplingItem'])
        ->expectsQuestion('Which domain owns this use case?', 'Sampling')
        ->expectsQuestion('What does this use case carry?', 'none')
        ->assertSuccessful();
});

it('refuses to overwrite an existing handler unless forced', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])->run();

    File::put(useCaseClassPath('ArchiveSamplingItemHandler'), '<?php // hand written');

    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])
        ->assertFailed();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))->toBe('<?php // hand written');

    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling', '--force' => true])
        ->assertSuccessful();

    expect(File::get(useCaseClassPath('ArchiveSamplingItemHandler')))
        ->toContain('final class ArchiveSamplingItemHandler');
});

it('leaves an existing payload and test untouched', function () {
    runMakeUseCase(['name' => 'ChangeSample', '--domain' => 'Sampling', '--command' => true], carries: null)->run();

    File::put(useCaseClassPath('ChangeSample/ChangeSampleCommand'), '<?php // hand written');
    File::put(useCaseTestFilePath('ChangeSample/ChangeSampleHandlerTest'), '<?php // hand written');

    runMakeUseCase([
        'name' => 'ChangeSample',
        '--domain' => 'Sampling',
        '--command' => true,
        '--force' => true,
    ], carries: null)->expectsOutputToContain('already exists')->assertSuccessful();

    expect(File::get(useCaseClassPath('ChangeSample/ChangeSampleCommand')))->toBe('<?php // hand written')
        ->and(File::get(useCaseTestFilePath('ChangeSample/ChangeSampleHandlerTest')))->toBe('<?php // hand written');
});

it('warns about a repository that does not exist yet', function () {
    runMakeUseCase([
        'name' => 'ArchiveSamplingItem',
        '--domain' => 'Sampling',
        '--repo' => 'Sample',
    ])
        ->expectsOutputToContain('App\Domain\Sampling\Sample\SampleRepository] does not exist yet')
        ->assertSuccessful();
});

it('asks what the use case carries when neither flag is given', function () {
    $this->artisan('make:use-case', ['name' => 'ChangeSample', '--domain' => 'Sampling'])
        ->expectsChoice('What does this use case carry?', 'both', [
            'none' => 'Nothing, the handler stands alone',
            'command' => 'A command',
            'both' => 'A command and a result',
        ])
        ->assertSuccessful();

    expect(File::exists(useCaseClassPath('ChangeSample/ChangeSampleCommand')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ChangeSample/ChangeSampleResult')))->toBeTrue();
});

it('honours each answer to the payload prompt', function (string $carries, bool $command, bool $result) {
    runMakeUseCase(['name' => 'ChangeSample', '--domain' => 'Sampling'], carries: $carries)
        ->assertSuccessful();

    $folder = $command || $result ? 'ChangeSample/' : '';

    expect(File::exists(useCaseClassPath($folder.'ChangeSampleHandler')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ChangeSample/ChangeSampleCommand')))->toBe($command)
        ->and(File::exists(useCaseClassPath('ChangeSample/ChangeSampleResult')))->toBe($result);
})->with([
    'none' => ['none', false, false],
    'command only' => ['command', true, false],
    'both' => ['both', true, true],
]);

it('does not ask what the use case carries when a flag is given', function () {
    $this->artisan('make:use-case', ['name' => 'ChangeSample', '--domain' => 'Sampling', '--command' => true])
        ->doesntExpectOutputToContain('What does this use case carry?')
        ->assertSuccessful();

    expect(File::exists(useCaseClassPath('ChangeSample/ChangeSampleResult')))->toBeFalse();
});

it('skips the payload prompt when told the use case is plain', function () {
    $this->artisan('make:use-case', ['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling', '--plain' => true])
        ->assertSuccessful();

    expect(File::exists(useCaseClassPath('ArchiveSamplingItemHandler')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ArchiveSamplingItemCommand')))->toBeFalse()
        ->and(File::exists(useCaseClassPath('ArchiveSamplingItemResult')))->toBeFalse();
});

it('fails instead of guessing the payload when it cannot ask', function () {
    $this->artisan('make:use-case', [
        'name' => 'ArchiveSamplingItem',
        '--domain' => 'Sampling',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Pass --command, --command --result, or --plain.')
        ->assertFailed();

    expect(File::exists(useCaseClassPath('ArchiveSamplingItemHandler')))->toBeFalse();
});

it('fails instead of guessing the domain when it cannot ask', function () {
    $this->artisan('make:use-case', [
        'name' => 'ArchiveSamplingItem',
        '--plain' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Pass --domain to name the domain that owns the use case.')
        ->assertFailed();
});

it('creates the read port beside the handler when asked for a query', function () {
    runMakeUseCase([
        'name' => 'ListSamplingItem',
        '--domain' => 'Sampling',
        '--command' => true,
        '--result' => true,
        '--query' => true,
    ], carries: null)->assertSuccessful();

    expect(File::get(useCaseClassPath('ListSamplingItem/ListSamplingItemQuery')))
        ->toContain('namespace App\Application\Sampling\UseCases\ListSamplingItem;')
        ->toContain('interface ListSamplingItemQuery')
        ->toContain('public function paginate(ListSamplingItemCriteria $criteria): LengthAwarePaginator;')
        ->toContain('@return LengthAwarePaginator<int, SamplingItemListRow>')
        ->not->toContain('{{');
});

it('creates the criteria and the row the port speaks in beside it', function () {
    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)
        ->assertSuccessful();

    expect(File::get(useCaseClassPath('ListSamplingItem/ListSamplingItemCriteria')))
        ->toContain('namespace App\Application\Sampling\UseCases\ListSamplingItem;')
        ->toContain('final class ListSamplingItemCriteria')
        ->toContain('use App\Application\Sampling\SamplingItemListSort;')
        ->toContain('use SortsAList;')
        ->toContain('public readonly SamplingItemListSort $sort,')
        ->toContain('public function toFilters(): array')
        ->not->toContain('{{');

    expect(File::get(useCaseClassPath('ListSamplingItem/SamplingItemListRow')))
        ->toContain('namespace App\Application\Sampling\UseCases\ListSamplingItem;')
        ->toContain('final class SamplingItemListRow implements Arrayable')
        ->toContain('public function toArray(): array')
        ->not->toContain('{{');
});

it('creates the sort enum at the context level, named like the row', function () {
    runMakeUseCase(['name' => 'ListSamplingItems', '--domain' => 'Sampling', '--query' => true], carries: null)
        ->assertSuccessful();

    expect(File::get(app_path('Application/Sampling/SamplingItemListSort.php')))
        ->toContain('namespace App\Application\Sampling;')
        ->toContain('enum SamplingItemListSort: string')
        ->toContain('public static function fromInput(string $value): self')
        ->not->toContain('{{')
        ->and(File::exists(useCaseClassPath('ListSamplingItems/SamplingItemListSort')))->toBeFalse();
});

it('names the sort after the use case when it is not a list', function () {
    runMakeUseCase(['name' => 'ReadSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)
        ->assertSuccessful();

    expect(File::exists(app_path('Application/Sampling/ReadSamplingItemSort.php')))->toBeTrue()
        ->and(File::get(useCaseClassPath('ReadSamplingItem/ReadSamplingItemCriteria')))
        ->toContain('public readonly ReadSamplingItemSort $sort,');
});

it('keeps a sort enum that already exists, since two lists may share one', function () {
    File::ensureDirectoryExists(app_path('Application/Sampling'));
    File::put(app_path('Application/Sampling/SamplingItemListSort.php'), '<?php // hand written sort');

    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)
        ->expectsOutputToContain('Sort [App\Application\Sampling\SamplingItemListSort] already exists.')
        ->assertSuccessful();

    expect(File::get(app_path('Application/Sampling/SamplingItemListSort.php')))->toBe('<?php // hand written sort');
});

it('generates a sort and a criteria that load and settle as written', function () {
    runMakeUseCase(['name' => 'ListSamplingLoadables', '--domain' => 'Sampling', '--query' => true], carries: null)->run();

    $sort = 'App\Application\Sampling\SamplingLoadableListSort';
    $criteria = 'App\Application\Sampling\UseCases\ListSamplingLoadables\ListSamplingLoadablesCriteria';

    $built = new $criteria(
        sort: $sort::fromInput('not-a-column'),
        direction: 'desc',
        search: '',
        createdAt: DateRange::fromInput('', ''),
        perPage: 10,
    );

    expect($built->toSort())->toBe(['column' => 'created_at', 'direction' => 'desc'])
        ->and($built->toFilters())->toBe(['search' => '', 'created_from' => null, 'created_to' => null]);
});

it('creates the eloquent adapter under the queries folder, never under repositories', function () {
    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)
        ->assertSuccessful();

    expect(File::get(queryAdapterPath('EloquentListSamplingItemQuery')))
        ->toContain('namespace App\Infra\Persistence\Eloquent\Queries;')
        ->toContain('use App\Application\Sampling\UseCases\ListSamplingItem\ListSamplingItemQuery;')
        ->toContain('use App\Application\Sampling\UseCases\ListSamplingItem\ListSamplingItemCriteria;')
        ->toContain('use App\Application\Sampling\UseCases\ListSamplingItem\SamplingItemListRow;')
        ->toContain('class EloquentListSamplingItemQuery extends EloquentListQuery implements ListSamplingItemQuery')
        ->toContain('public function paginate(ListSamplingItemCriteria $criteria): LengthAwarePaginator')
        ->toContain('return $this->paginateRows(')
        ->not->toContain('makePaginator')
        ->not->toContain('{{');

    expect(File::exists(app_path('Infra/Persistence/Eloquent/Repositories/EloquentListSamplingItemQuery.php')))
        ->toBeFalse();
});

it('injects the port into the handler without importing it', function () {
    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)->run();

    expect(File::get(useCaseClassPath('ListSamplingItem/ListSamplingItemHandler')))
        ->toContain('protected ListSamplingItemQuery $query,')
        ->not->toContain('use App\Application\Sampling\UseCases\ListSamplingItem\ListSamplingItemQuery;');
});

it('writes a command and a result whenever a query is asked for, since a list takes both', function () {
    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)
        ->doesntExpectOutputToContain('What does this use case carry?')
        ->assertSuccessful();

    expect(File::get(useCaseClassPath('ListSamplingItem/ListSamplingItemHandler')))
        ->toContain('public function __invoke(ListSamplingItemCommand $command): ListSamplingItemResult');

    expect(File::exists(useCaseClassPath('ListSamplingItem/ListSamplingItemCommand')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ListSamplingItem/ListSamplingItemResult')))->toBeTrue()
        ->and(File::exists(useCaseClassPath('ListSamplingItemHandler')))->toBeFalse();
});

it('injects the repository and the port together when both are asked for', function () {
    runMakeUseCase([
        'name' => 'ListSamplingItem',
        '--domain' => 'Sampling',
        '--repo' => 'Sample',
        '--query' => true,
    ], carries: null)->assertSuccessful();

    expect(File::get(useCaseClassPath('ListSamplingItem/ListSamplingItemHandler')))
        ->toContain('use App\Domain\Sampling\Sample\SampleRepository;')
        ->toContain('protected SampleRepository $repo,')
        ->toContain('protected ListSamplingItemQuery $query,');
});

it('creates the adapter test mirroring where the adapter lands', function () {
    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)->run();

    expect(File::get(queryAdapterTestPath('EloquentListSamplingItemQuery')))
        ->toContain("require_once __DIR__.'/../ListQueryContract.php';")
        ->toContain('listQueryContract(')
        ->toContain('query: ListSamplingItemQuery::class')
        ->toContain('use App\Application\Sampling\UseCases\ListSamplingItem\ListSamplingItemCriteria;')
        ->not->toContain('{{');
});

it('spells out the binding the generated adapter still needs', function () {
    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)
        ->expectsOutputToContain('ListSamplingItemQuery::class => EloquentListSamplingItemQuery::class')
        ->assertSuccessful();
});

it('says nothing about a binding when no query was asked for', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])
        ->doesntExpectOutputToContain('PersistenceServiceProvider')
        ->assertSuccessful();
});

it('leaves a hand written port and adapter untouched', function () {
    runMakeUseCase(['name' => 'ListSamplingItem', '--domain' => 'Sampling', '--query' => true], carries: null)->run();

    File::put(useCaseClassPath('ListSamplingItem/ListSamplingItemQuery'), '<?php // hand written port');
    File::put(queryAdapterPath('EloquentListSamplingItemQuery'), '<?php // hand written adapter');

    runMakeUseCase([
        'name' => 'ListSamplingItem',
        '--domain' => 'Sampling',
        '--query' => true,
        '--force' => true,
    ], carries: null)->assertSuccessful();

    expect(File::get(useCaseClassPath('ListSamplingItem/ListSamplingItemQuery')))->toBe('<?php // hand written port')
        ->and(File::get(queryAdapterPath('EloquentListSamplingItemQuery')))->toBe('<?php // hand written adapter');
});

it('creates no query classes unless asked to', function () {
    runMakeUseCase(['name' => 'ArchiveSamplingItem', '--domain' => 'Sampling'])->run();

    expect(File::exists(useCaseClassPath('ArchiveSamplingItemQuery')))->toBeFalse()
        ->and(File::exists(queryAdapterPath('EloquentArchiveSamplingItemQuery')))->toBeFalse();
});
