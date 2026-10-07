<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesClassesFromStubs;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('make:domain-service {name : The service name, without the Service suffix} {--domain= : Context under App\Domain that owns the service} {--creates= : Creates shape: the aggregate whose root the service builds, e.g. Agent} {--data : Data in, Result out shape} {--plain : Plain shape, with no Data and no Result} {--exception : Also create the service exception} {--repo= : Repository of the same context injected into the service} {--force : Overwrite the service if it already exists}')]
#[Description('Create a new domain service in one of the three shapes layers.md allows')]
class MakeDomainServiceCommand extends GeneratorCommand implements PromptsForMissingInput
{
    use ResolvesDomain;
    use WritesClassesFromStubs;

    protected const string CREATES = 'creates';

    protected const string DATA = 'data';

    protected const string PLAIN = 'plain';

    protected $type = 'Domain service';

    /**
     * The shape handle() takes, and for the Creates shape the aggregate it builds.
     *
     * @var array{shape: string, aggregate: string|null}|null
     */
    protected ?array $shape = null;

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();
        $this->shape = null;

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        if ($this->resolveShape() === null) {
            $this->components->error('Could not tell which shape handle() takes. Pass exactly one of --creates=, --data or --plain.');

            return self::FAILURE;
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->createCollaborators();

        $this->createTest();

        $this->warnAboutMissingCollaborators();

        return self::SUCCESS;
    }

    #[Override]
    protected function getStub()
    {
        return WorkflowKit::stubPath('domain-service.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Domain\\'.$this->resolveDomain().'\Services\\'.$this->serviceName();
    }

    #[Override]
    protected function getNameInput()
    {
        return $this->serviceName().'Service';
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
            '{{ parameters }}' => $this->parameters(),
            '{{ returnType }}' => $this->returnType(),
        ];
    }

    /**
     * The three shapes of layers.md, spelled out as handle()'s parameters.
     */
    protected function parameters(): string
    {
        return match ($this->shapeName()) {
            self::CREATES => 'string $id, '.$this->dataName().' $data',
            self::DATA => $this->dataName().' $data',
            default => '',
        };
    }

    protected function returnType(): string
    {
        return match ($this->shapeName()) {
            self::CREATES => $this->entityName(),
            self::DATA => $this->resultName(),
            default => 'void',
        };
    }

    /**
     * The service's own Data and Result sit beside it and need no import; only the entity
     * it builds and the repository it is given come from elsewhere.
     */
    protected function useStatements(): string
    {
        $imports = array_filter([
            $this->shapeName() === self::CREATES ? $this->namespacedEntity() : null,
            $this->namespacedRepository(),
        ]);

        sort($imports);

        return implode('', array_map(fn (string $import) => 'use '.$import.";\n", $imports));
    }

    protected function constructor(): string
    {
        $repository = $this->repositoryName();

        if ($repository === null) {
            return '';
        }

        return "\n    public function __construct(\n        protected ".$repository.' $repo,'."\n    ) {}\n";
    }

    /**
     * The service base name, stripped of any Service decoration.
     */
    protected function serviceName(): string
    {
        return (string) Str::of($this->argument('name'))
            ->trim()
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopEnd('Service');
    }

    protected function dataName(): string
    {
        return $this->serviceName().'Data';
    }

    protected function resultName(): string
    {
        return $this->serviceName().'Result';
    }

    protected function exceptionName(): string
    {
        return $this->serviceName().'Exception';
    }

    /**
     * The aggregate a Creates service builds, as its folder name: `--creates=Agent`.
     */
    protected function aggregateName(): string
    {
        return (string) Str::of((string) ($this->shape['aggregate'] ?? ''))
            ->trim()
            ->studly()
            ->chopEnd('Entity');
    }

    protected function entityName(): string
    {
        return $this->aggregateName().'Entity';
    }

    protected function namespacedEntity(): string
    {
        return $this->contextNamespace().'\\'.$this->aggregateName().'\\'.$this->entityName();
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
     * A repository lives in its aggregate's folder, taken from its own name, and a service
     * may only be given one of its own context (layers.md).
     */
    protected function namespacedRepository(): ?string
    {
        $repository = $this->repositoryName();

        if ($repository === null) {
            return null;
        }

        return $this->contextNamespace().'\\'.Str::chopEnd($repository, 'Repository').'\\'.$repository;
    }

    protected function contextNamespace(): string
    {
        return $this->rootNamespace().'Domain\\'.$this->resolveDomain();
    }

    protected function shapeName(): string
    {
        return $this->shape['shape'] ?? self::PLAIN;
    }

    /**
     * The shape handle() takes, asked for when no flag settles it. Two flags at once are
     * refused rather than ranked, because a fourth shape is what the rule forbids.
     *
     * @return array{shape: string, aggregate: string|null}|null
     */
    protected function resolveShape(): ?array
    {
        if ($this->shape !== null) {
            return $this->shape;
        }

        $creates = $this->option('creates');
        $chosen = array_keys(array_filter([
            self::CREATES => filled($creates),
            self::DATA => (bool) $this->option('data'),
            self::PLAIN => (bool) $this->option('plain'),
        ]));

        if (count($chosen) > 1) {
            return null;
        }

        if ($chosen !== []) {
            return $this->shape = ['shape' => $chosen[0], 'aggregate' => filled($creates) ? (string) $creates : null];
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $shape = (string) select(
            label: 'Which shape does handle() take?',
            options: [
                self::CREATES => 'Creates: (string $id, Data $data): RootEntity',
                self::DATA => 'Data in, Result out: (Data $data): Result',
                self::PLAIN => 'Plain: no Data and no Result',
            ],
        );

        return $this->shape = [
            'shape' => $shape,
            'aggregate' => $shape === self::CREATES
                ? text(label: 'Which aggregate does it create?', placeholder: 'Agent', required: true)
                : null,
        ];
    }

    protected function domainSubject(): string
    {
        return 'domain service';
    }

    /**
     * Create the Data, Result and Exception the chosen shape and flags call for, beside the service.
     */
    protected function createCollaborators(): void
    {
        if (in_array($this->shapeName(), [self::CREATES, self::DATA], true)) {
            $this->createFromStub('domain-service-data.stub', $this->qualifyClass($this->dataName()), 'Data');
        }

        if ($this->shapeName() === self::DATA) {
            $this->createFromStub('domain-service-result.stub', $this->qualifyClass($this->resultName()), 'Result');
        }

        if ($this->option('exception')) {
            $this->createFromStub('domain-service-exception.stub', $this->qualifyClass($this->exceptionName()), 'Exception');
        }
    }

    /**
     * Create the test mirroring the service namespace: Feature when it is given a repository,
     * Unit when it only computes (testing.md).
     */
    protected function createTest(): void
    {
        $path = $this->testPath();

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Test [%s] already exists.', $this->relativePath($path)));

            return;
        }

        $service = $this->qualifyClass($this->getNameInput());

        $contents = str_replace(
            ['{{ namespacedService }}', '{{ service }}'],
            [$service, class_basename($service)],
            $this->files->get(WorkflowKit::stubPath($this->repositoryName() === null ? 'domain-service-unit-test.stub' : 'domain-service-test.stub')),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $contents);

        $this->components->info(sprintf('Test [%s] created successfully.', $this->relativePath($path)));
    }

    protected function testPath(): string
    {
        $segments = str_replace('\\', '/', $this->resolveDomain().'/Services/'.$this->serviceName());

        return base_path('tests/'.($this->repositoryName() === null ? 'Unit' : 'Feature').'/Domain/'.$segments.'/'.$this->getNameInput().'Test.php');
    }

    /**
     * Point out what the generated service names but the generator did not write.
     */
    protected function warnAboutMissingCollaborators(): void
    {
        $expected = array_filter([
            'Entity' => $this->shapeName() === self::CREATES ? $this->namespacedEntity() : null,
            'Repository' => $this->namespacedRepository(),
        ]);

        foreach ($expected as $label => $class) {
            if (! $this->files->exists($this->getPath($class))) {
                $this->components->warn(sprintf('%s [%s] does not exist yet.', $label, $class));
            }
        }
    }
}
