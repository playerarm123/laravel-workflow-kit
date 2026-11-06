<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesClassesFromStubs;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

use function Laravel\Prompts\select;

#[Signature('make:use-case {name : The use case name, without the Handler suffix} {--domain= : Domain that owns the use case, e.g. LotteryDefinition} {--repo= : Domain repository injected into the handler} {--c|command : Also create the command class} {--r|result : Also create the result class; needs --command} {--query : Also create the read query port and its Eloquent adapter; implies --command --result} {--plain : Create the handler without a command or a result} {--creates : Inject the IdGenerator; the handler mints the id of the root it creates} {--force : Overwrite the handler if it already exists}')]
#[Description('Create a new application use case handler')]
class MakeUseCaseCommand extends GeneratorCommand implements PromptsForMissingInput
{
    use ResolvesDomain;
    use WritesClassesFromStubs;

    protected $type = 'Use case handler';

    /**
     * Whether the use case carries a command and a result.
     *
     * @var array{command: bool, result: bool}|null
     */
    protected ?array $payloads = null;

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();
        $this->payloads = null;

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        if ($this->carriesQuery() && filled($this->option('repo'))) {
            $this->components->error('A list reads through its query port and never through a repository, so it writes nothing and records no audit entry (list-queries.md). Drop --repo.');

            return self::FAILURE;
        }

        if ($this->option('result') && ! $this->option('command') && ! $this->carriesQuery()) {
            $this->components->error('A Result comes back only from a Command (handlers.md). Pass --command --result.');

            return self::FAILURE;
        }

        if ($this->resolvePayloads() === null) {
            $this->components->error('Could not tell what this use case carries. Pass --command, --command --result, or --plain.');

            return self::FAILURE;
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->createPayloads();

        $this->createQuery();

        $this->createTest();

        $this->warnAboutCollaborators();

        return self::SUCCESS;
    }

    #[Override]
    protected function getStub()
    {
        return WorkflowKit::stubPath('use-case-handler.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Application\\'.$this->resolveDomain().'\UseCases'.$this->ownFolderNamespace();
    }

    #[Override]
    protected function getNameInput()
    {
        return $this->useCaseName().'Handler';
    }

    #[Override]
    protected function buildClass($name)
    {
        return str_replace(
            array_keys($this->stubReplacements()),
            array_values($this->stubReplacements()),
            parent::buildClass($name),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function stubReplacements(): array
    {
        return [
            '{{ useStatements }}' => $this->useStatements(),
            '{{ constructor }}' => $this->constructor(),
            '{{ invokeParameters }}' => $this->carriesCommand() ? $this->commandName().' $command' : '',
            '{{ returnType }}' => $this->returnType(),
            '{{ handlerBody }}' => $this->handlerBody(),
            '{{ query }}' => $this->queryName(),
            '{{ namespacedQuery }}' => $this->namespacedQuery(),
            '{{ criteria }}' => $this->criteriaName(),
            '{{ namespacedCriteria }}' => $this->namespacedCriteria(),
            '{{ adapter }}' => $this->adapterName(),
            '{{ namespacedAdapter }}' => $this->namespacedAdapter(),
            '{{ rowType }}' => $this->rowTypeName(),
            '{{ namespacedRow }}' => $this->namespacedRow(),
            '{{ sort }}' => $this->sortName(),
            '{{ namespacedSort }}' => $this->namespacedSort(),
            '{{ rows }}' => $this->rowsName(),
            '{{ model }}' => (string) $this->listSubject(),
            '{{ namespacedModel }}' => (string) $this->namespacedModel(),
            '{{ modelVariable }}' => Str::camel((string) $this->listSubject()),
            '{{ table }}' => (string) $this->tableName(),
        ];
    }

    /**
     * A handler that creates a root hands its new id back, unless a Result carries it.
     */
    protected function returnType(): string
    {
        return match (true) {
            $this->carriesResult() => $this->resultName(),
            $this->creates() => 'string',
            default => 'void',
        };
    }

    protected function handlerBody(): string
    {
        if ($this->carriesQuery()) {
            return $this->listHandlerBody();
        }

        if ($this->writes()) {
            return $this->writingHandlerBody();
        }

        if (! $this->creates()) {
            return '        // implement the use case here';
        }

        $body = "        \$id = \$this->ids->next();\n\n        // build the aggregate with \$id and save it";

        return $this->carriesResult() ? $body : $body."\n\n        return \$id;";
    }

    /**
     * A handler that writes records what it changed, in the same transaction (audit-log.md).
     * The record() call is left commented until the aggregate exists, so the audit-log check
     * keeps naming the handler until someone writes it. The transaction call is split in two
     * so handlers.md's check, which reads every entry point's code, does not take this
     * generator for an entry point that opens one.
     */
    protected function writingHandlerBody(): string
    {
        $event = $this->auditSubject().'.'.($this->creates() ? 'created' : 'changed');
        $returnsId = $this->creates() && ! $this->carriesResult();
        $mint = $this->creates() ? "        \$id = \$this->ids->next();\n\n" : '';
        $uses = $this->creates() ? ' use ($id)' : '';
        $steps = $this->creates()
            ? '// build the aggregate with $id and save it'
            : '// load the aggregate, change it through its own methods and update it';
        $return = $returnsId ? "\n\n            return \$id;" : '';

        return $mint
            .'        '.($returnsId ? 'return ' : '').'DB::transaction'."(function (){$uses} {\n"
            ."            {$steps}\n"
            ."            // \$this->audit->record('{$event}', \$aggregate); — name the verb in the past tense{$return}\n"
            .'        });';
    }

    /**
     * A list settles every raw value of its Command into the Criteria, each with a fallback
     * so a hand-edited url still gets a page, and hands back what it settled on
     * (list-queries.md). `desc` is the natural order of the default sort, newest first.
     */
    protected function listHandlerBody(): string
    {
        return '        $criteria = new '.$this->criteriaName()."(\n"
            .'            sort: '.$this->sortName()."::fromInput(\$command->sort),\n"
            ."            direction: \$command->direction === 'asc' ? 'asc' : 'desc',\n"
            ."            search: trim(\$command->search),\n"
            ."            // one settled value per filter, null meaning \"do not filter\", e.g.\n"
            ."            // status: SampleStatus::tryFrom(\$command->status),\n"
            ."            createdAt: DateRange::fromInput(\$command->createdFrom, \$command->createdTo),\n"
            ."            perPage: PageSize::fromInput(\$command->perPage)->value,\n"
            ."        );\n\n"
            .'        return new '.$this->resultName()."(\n"
            .'            '.$this->rowsName().": \$this->query->paginate(\$criteria),\n"
            ."            sort: \$criteria->toSort(),\n"
            ."            filters: \$criteria->toFilters(),\n"
            .'        );';
    }

    /**
     * A handler writes when it is given a repository to write through.
     */
    protected function writes(): bool
    {
        return $this->repositoryName() !== null;
    }

    /**
     * `SampleItemRepository` records events about `sample_item`, the subject type the audit
     * adapter stores for `SampleItemEntity`.
     */
    protected function auditSubject(): string
    {
        return Str::snake((string) Str::of((string) $this->repositoryName())->chopEnd('Repository'));
    }

    /**
     * The import block of the handler, empty when it injects nothing from the domain.
     */
    protected function useStatements(): string
    {
        $imports = array_filter([
            $this->creates() ? $this->namespacedIdGenerator() : null,
            $this->namespacedRepository(),
            $this->writes() ? $this->rootNamespace().'Application\\Audit\\AuditLog' : null,
            $this->writes() ? 'Illuminate\\Support\\Facades\\DB' : null,
            ...($this->carriesQuery() ? [
                $this->namespacedSort(),
                $this->rootNamespace().'Application\\Concerns\\DateRange',
                $this->rootNamespace().'Application\\Concerns\\PageSize',
            ] : []),
        ]);
        sort($imports);

        return $imports === [] ? '' : "\n".implode('', array_map(fn (string $import) => 'use '.$import.";\n", $imports));
    }

    protected function creates(): bool
    {
        return (bool) $this->option('creates');
    }

    /**
     * The id port every handler mints new ids through (handlers.md).
     */
    protected function namespacedIdGenerator(): string
    {
        return $this->rootNamespace().'Domain\\Shared\\Ports\\IdGenerator';
    }

    /**
     * The constructor of the handler, empty when the handler depends on nothing.
     *
     * @return array<string, string> property name keyed by the type it is typed with
     */
    protected function dependencies(): array
    {
        $dependencies = [];

        if ($this->creates()) {
            $dependencies['ids'] = 'IdGenerator';
        }

        $repository = $this->repositoryName();

        if ($repository !== null) {
            $dependencies['repo'] = $repository;
            $dependencies['audit'] = 'AuditLog';
        }

        if ($this->carriesQuery()) {
            $dependencies['query'] = $this->queryName();
        }

        return $dependencies;
    }

    protected function constructor(): string
    {
        $dependencies = $this->dependencies();

        if ($dependencies === []) {
            return '';
        }

        $properties = '';

        foreach ($dependencies as $property => $type) {
            $properties .= "\n        protected ".$type.' $'.$property.',';
        }

        return "\n    public function __construct(".$properties."\n    ) {}\n";
    }

    /**
     * The use case base name, stripped of any Handler decoration.
     */
    protected function useCaseName(): string
    {
        return (string) Str::of($this->argument('name'))
            ->trim()
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopEnd('Handler');
    }

    protected function commandName(): string
    {
        return $this->useCaseName().'Command';
    }

    protected function resultName(): string
    {
        return $this->useCaseName().'Result';
    }

    protected function queryName(): string
    {
        return $this->useCaseName().'Query';
    }

    protected function adapterName(): string
    {
        return 'Eloquent'.$this->queryName();
    }

    protected function criteriaName(): string
    {
        return $this->useCaseName().'Criteria';
    }

    /**
     * `ListLotteryTypes` reads rows of `LotteryTypeListRow` — the name every list row carries
     * (list-queries.md). A use case that does not start with List keeps `{UseCase}Row`.
     */
    protected function rowTypeName(): string
    {
        return $this->listItemName().'Row';
    }

    /**
     * `ListLotteryTypes` sorts by `LotteryTypeListSort`, named like its row.
     */
    protected function sortName(): string
    {
        return $this->listItemName().'Sort';
    }

    /**
     * The singular subject of a List use case with `List` appended, or the use case itself.
     */
    protected function listItemName(): string
    {
        $subject = $this->listSubject();

        return $subject === null ? $this->useCaseName() : $subject.'List';
    }

    /**
     * `ListLotteryTypes` lists `LotteryType`, the model its adapter reads. A use case that
     * does not start with List names no model.
     */
    protected function listSubject(): ?string
    {
        $name = $this->useCaseName();

        return str_starts_with($name, 'List') && strlen($name) > 4
            ? Str::singular(substr($name, 4))
            : null;
    }

    protected function namespacedModel(): ?string
    {
        $subject = $this->listSubject();

        return $subject === null ? null : $this->qualifyModel($subject);
    }

    /**
     * The table the adapter qualifies its columns with: the model's own when it exists,
     * otherwise the one Laravel would give it.
     */
    protected function tableName(): ?string
    {
        $model = $this->namespacedModel();

        if ($model === null) {
            return null;
        }

        return class_exists($model) && is_subclass_of($model, Model::class)
            ? (new $model)->getTable()
            : Str::snake(Str::pluralStudly((string) $this->listSubject()));
    }

    /**
     * A list names its model, so its adapter and that adapter's test are written out in full.
     * Any other read port keeps the commented outline.
     */
    protected function queryStub(string $stub): string
    {
        return $this->listSubject() === null ? $stub.'.no-model.stub' : $stub.'.stub';
    }

    /**
     * The Result property the rows travel in: `ListLotteryTypes` hands back `lotteryTypes`,
     * the name make:controller reads for the rows prop. A use case that does not start with
     * List hands back `rows`.
     */
    protected function rowsName(): string
    {
        $subject = $this->listSubject();

        return $subject === null ? 'rows' : Str::camel(Str::pluralStudly($subject));
    }

    /**
     * The port sits in the use case's own folder, so the handler never imports it.
     */
    protected function namespacedQuery(): string
    {
        return $this->qualifyClass($this->queryName());
    }

    protected function namespacedCriteria(): string
    {
        return $this->qualifyClass($this->criteriaName());
    }

    protected function namespacedRow(): string
    {
        return $this->qualifyClass($this->rowTypeName());
    }

    /**
     * The sort enum sits at the context level, where every list's enum already lives —
     * a controller or a second use case reads the same one.
     */
    protected function namespacedSort(): string
    {
        return $this->rootNamespace().'Application\\'.$this->resolveDomain().'\\'.$this->sortName();
    }

    /**
     * The adapter lives with the other read queries, never in Repositories/ — the classes
     * there all extend EloquentRepository, which a read query is not.
     */
    protected function namespacedAdapter(): string
    {
        return $this->rootNamespace().'Infra\\Persistence\\Eloquent\\Queries\\'.$this->adapterName();
    }

    /**
     * The payload classes the use case carries, asked for when no flag settles it.
     *
     * @return array{command: bool, result: bool}|null
     */
    protected function resolvePayloads(): ?array
    {
        if ($this->payloads !== null) {
            return $this->payloads;
        }

        if ($this->carriesQuery()) {
            return $this->payloads = ['command' => true, 'result' => true];
        }

        if ($this->option('command')) {
            return $this->payloads = [
                'command' => true,
                'result' => (bool) $this->option('result'),
            ];
        }

        if ($this->option('plain')) {
            return $this->payloads = ['command' => false, 'result' => false];
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $carries = select(
            label: 'What does this use case carry?',
            options: [
                'none' => 'Nothing, the handler stands alone',
                'command' => 'A command',
                'both' => 'A command and a result',
            ],
        );

        return $this->payloads = [
            'command' => in_array($carries, ['command', 'both'], true),
            'result' => $carries === 'both',
        ];
    }

    protected function domainSubject(): string
    {
        return 'use case';
    }

    protected function carriesCommand(): bool
    {
        return $this->payloads['command'] ?? false;
    }

    protected function carriesResult(): bool
    {
        return $this->payloads['result'] ?? false;
    }

    protected function carriesQuery(): bool
    {
        return (bool) $this->option('query');
    }

    /**
     * A use case carrying a command, a result or a read port gets a folder of its own —
     * the port has to land beside the handler for the handler to reach it unimported.
     */
    protected function hasOwnFolder(): bool
    {
        return $this->carriesCommand() || $this->carriesResult() || $this->carriesQuery();
    }

    protected function ownFolderNamespace(): string
    {
        return $this->hasOwnFolder() ? '\\'.$this->useCaseName() : '';
    }

    protected function repositoryName(): ?string
    {
        $repository = $this->option('repo');

        if (blank($repository)) {
            return null;
        }

        return (string) Str::of($repository)
            ->trim()
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopEnd('Repository')
            ->append('Repository');
    }

    /**
     * A use case names its context, but a repository lives inside an aggregate, so the
     * aggregate folder is taken from the repository itself: LotteryTypeRepository sits
     * in Domain\{Context}\LotteryType.
     */
    protected function namespacedRepository(): ?string
    {
        $repository = $this->repositoryName();

        if ($repository === null) {
            return null;
        }

        $aggregate = Str::of($repository)->chopEnd('Repository');

        return $this->rootNamespace().'Domain\\'.$this->resolveDomain().'\\'.$aggregate.'\\'.$repository;
    }

    /**
     * Create the command and result classes the handler was asked to carry.
     */
    protected function createPayloads(): void
    {
        if ($this->carriesCommand()) {
            $this->createFromStub(
                $this->carriesQuery() ? 'use-case-list-command.stub' : 'use-case-command.stub',
                $this->qualifyClass($this->commandName()),
                'Command',
            );
        }

        if ($this->carriesResult()) {
            $this->createFromStub(
                $this->carriesQuery() ? 'use-case-list-result.stub' : 'use-case-result.stub',
                $this->qualifyClass($this->resultName()),
                'Result',
            );
        }
    }

    /**
     * Create the read port with the sort, criteria and row it speaks in, and the Eloquent
     * adapter that satisfies it.
     */
    protected function createQuery(): void
    {
        if (! $this->carriesQuery()) {
            return;
        }

        $this->createFromStub(
            'use-case-sort.stub',
            $this->namespacedSort(),
            'Sort',
        );

        $this->createFromStub(
            'use-case-criteria.stub',
            $this->namespacedCriteria(),
            'Criteria',
        );

        $this->createFromStub(
            'use-case-row.stub',
            $this->namespacedRow(),
            'Row',
        );

        $this->createFromStub(
            'use-case-query.stub',
            $this->namespacedQuery(),
            'Query',
        );

        $this->createFromStub(
            $this->queryStub('eloquent-query'),
            $this->namespacedAdapter(),
            'Query adapter',
        );

        $this->createQueryTest();
    }

    /**
     * Create the feature test mirroring the use case namespace under tests/Feature.
     */
    protected function createTest(): void
    {
        $path = $this->testPath();

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Test [%s] already exists.', $this->relativePath($path)));

            return;
        }

        $handler = $this->qualifyClass($this->getNameInput());

        $contents = str_replace(
            ['{{ namespacedHandler }}', '{{ handler }}', '{{ writeTodos }}'],
            [$handler, class_basename($handler), $this->writes() ? "\n    it('records what it changed in the audit log')->todo();" : ''],
            $this->files->get(WorkflowKit::stubPath('use-case-test.stub')),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $contents);

        $this->components->info(sprintf('Test [%s] created successfully.', $this->relativePath($path)));
    }

    protected function testPath(): string
    {
        $segments = str_replace('\\', '/', $this->resolveDomain().'/UseCases'.$this->ownFolderNamespace());

        return base_path('tests/Feature/Application/'.$segments.'/'.$this->getNameInput().'Test.php');
    }

    /**
     * Create the feature test for the adapter, mirroring where the adapter itself lands.
     */
    protected function createQueryTest(): void
    {
        $path = $this->queryTestPath();

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Query adapter test [%s] already exists.', $this->relativePath($path)));

            return;
        }

        $contents = str_replace(
            array_keys($this->stubReplacements()),
            array_values($this->stubReplacements()),
            $this->files->get(WorkflowKit::stubPath($this->queryStub('eloquent-query-test'))),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $this->sortImports($contents));

        $this->components->info(sprintf('Query adapter test [%s] created successfully.', $this->relativePath($path)));
    }

    protected function queryTestPath(): string
    {
        return base_path('tests/Feature/Infra/Persistence/Eloquent/Queries/'.$this->adapterName().'Test.php');
    }

    /**
     * Point out what the generated handler expects but that the generator did not write.
     */
    protected function warnAboutCollaborators(): void
    {
        $repository = $this->namespacedRepository();

        if ($repository !== null && ! $this->files->exists($this->getPath($repository))) {
            $this->components->warn(sprintf('Repository [%s] does not exist yet.', $repository));
        }

        $this->warnAboutMissingModel();

        $this->warnAboutMissingBinding();
    }

    /**
     * The adapter reads through the model the list is named after, and its test seeds through
     * that model's factory.
     */
    protected function warnAboutMissingModel(): void
    {
        $model = $this->namespacedModel();

        if (! $this->carriesQuery() || $model === null || class_exists($model)) {
            return;
        }

        $this->components->warn(sprintf('Model [%s] does not exist yet — %s reads through it.', $model, $this->adapterName()));
    }

    /**
     * The adapter is useless until the port resolves to it, and nothing else in the
     * generated code says so — the provider is edited by hand, as it is for repositories.
     */
    protected function warnAboutMissingBinding(): void
    {
        if (! $this->carriesQuery()) {
            return;
        }

        $this->components->warn(sprintf(
            'Bind the query in PersistenceServiceProvider::$bindings: %s::class => %s::class,',
            $this->queryName(),
            $this->adapterName(),
        ));
    }
}
