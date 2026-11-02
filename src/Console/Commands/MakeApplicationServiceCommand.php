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

#[Signature('make:application-service {name : The service name, without the Service suffix} {--domain= : Context under App\Application that owns the service} {--data : Data in, Result out shape} {--plain : Plain shape, with no Data and no Result} {--repo= : Repository injected into the service, Aggregate in its own context or Context/Aggregate} {--actor : Inject UserContext, the actor port} {--exception : Also create the service exception} {--force : Overwrite the service if it already exists}')]
#[Description('Create a new application service, the steps several use-case handlers share')]
class MakeApplicationServiceCommand extends GeneratorCommand implements PromptsForMissingInput
{
    use ResolvesDomain;
    use WritesClassesFromStubs;

    protected const string DATA = 'data';

    protected const string PLAIN = 'plain';

    protected $type = 'Application service';

    /**
     * The shape handle() takes.
     */
    protected ?string $shape = null;

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();
        $this->shape = null;

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        if ($this->resolveDomain() === self::SHARED_KERNEL) {
            $this->components->error('The shared kernel holds no application code. Name the context whose handlers share the service.');

            return self::FAILURE;
        }

        if ($this->resolveShape() === null) {
            $this->components->error('Could not tell which shape handle() takes. Pass exactly one of --data or --plain.');

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
        return WorkflowKit::stubPath('application-service.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Application\\'.$this->resolveDomain().'\Services\\'.$this->serviceName();
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
            '{{ parameters }}' => $this->shape === self::DATA ? $this->dataName().' $data' : '',
            '{{ returnType }}' => $this->shape === self::DATA ? $this->resultName() : 'void',
        ];
    }

    /**
     * The service's own Data and Result sit beside it and need no import; only the ports and
     * the repository it is given come from elsewhere.
     */
    protected function useStatements(): string
    {
        $imports = array_filter([
            $this->option('actor') ? $this->namespacedUserContext() : null,
            $this->namespacedRepository(),
        ]);

        sort($imports);

        return implode('', array_map(fn (string $import) => 'use '.$import.";\n", $imports));
    }

    /**
     * @return array<string, string> property name keyed by the type it is typed with
     */
    protected function dependencies(): array
    {
        $dependencies = [];

        if ($this->option('actor')) {
            $dependencies['user'] = 'UserContext';
        }

        $repository = $this->repositoryName();

        if ($repository !== null) {
            $dependencies['repo'] = $repository;
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
     * The actor port every handler reads who is acting from (handlers.md).
     */
    protected function namespacedUserContext(): string
    {
        return $this->rootNamespace().'Application\\Auth\\UserContext';
    }

    /**
     * `--repo=Crate` names an aggregate of the service's own context, `--repo=Billing/Account`
     * one of another: unlike a domain service, an application service may call into several.
     *
     * @return array{context: string, aggregate: string}|null
     */
    protected function repository(): ?array
    {
        $repository = $this->option('repo');

        if (blank($repository)) {
            return null;
        }

        $segments = array_map(
            fn (string $segment) => Str::studly($segment),
            preg_split('#[/\\\\]#', trim((string) $repository), flags: PREG_SPLIT_NO_EMPTY) ?: [],
        );

        $aggregate = Str::chopEnd((string) array_pop($segments), 'Repository');

        return ['context' => $segments === [] ? (string) $this->resolveDomain() : implode('\\', $segments), 'aggregate' => $aggregate];
    }

    protected function repositoryName(): ?string
    {
        $repository = $this->repository();

        return $repository === null ? null : $repository['aggregate'].'Repository';
    }

    /**
     * A repository lives in its aggregate's folder, taken from its own name.
     */
    protected function namespacedRepository(): ?string
    {
        $repository = $this->repository();

        if ($repository === null) {
            return null;
        }

        return $this->rootNamespace().'Domain\\'.$repository['context'].'\\'.$repository['aggregate'].'\\'.$repository['aggregate'].'Repository';
    }

    /**
     * The shape handle() takes, asked for when no flag settles it. Both flags at once are
     * refused rather than ranked.
     */
    protected function resolveShape(): ?string
    {
        if ($this->shape !== null) {
            return $this->shape;
        }

        $chosen = array_keys(array_filter([
            self::DATA => (bool) $this->option('data'),
            self::PLAIN => (bool) $this->option('plain'),
        ]));

        if (count($chosen) > 1) {
            return null;
        }

        if ($chosen !== []) {
            return $this->shape = $chosen[0];
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        return $this->shape = (string) select(
            label: 'Which shape does handle() take?',
            options: [
                self::DATA => 'Data in, Result out: (Data $data): Result',
                self::PLAIN => 'Plain: no Data and no Result',
            ],
        );
    }

    protected function domainSubject(): string
    {
        return 'application service';
    }

    /**
     * Create the Data, Result and Exception the chosen shape and flags call for, beside the service.
     */
    protected function createCollaborators(): void
    {
        if ($this->shape === self::DATA) {
            $this->createFromStub('application-service-data.stub', $this->qualifyClass($this->dataName()), 'Data');
            $this->createFromStub('application-service-result.stub', $this->qualifyClass($this->resultName()), 'Result');
        }

        if ($this->option('exception')) {
            $this->createFromStub('application-service-exception.stub', $this->qualifyClass($this->exceptionName()), 'Exception');
        }
    }

    /**
     * Create the test mirroring the service namespace: Feature when it is given anything, so
     * the container builds it, Unit when it injects nothing (testing.md).
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
            $this->files->get(WorkflowKit::stubPath($this->dependencies() === [] ? 'application-service-unit-test.stub' : 'application-service-test.stub')),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $contents);

        $this->components->info(sprintf('Test [%s] created successfully.', $this->relativePath($path)));
    }

    protected function testPath(): string
    {
        $segments = str_replace('\\', '/', $this->resolveDomain().'/Services/'.$this->serviceName());

        return base_path('tests/'.($this->dependencies() === [] ? 'Unit' : 'Feature').'/Application/'.$segments.'/'.$this->getNameInput().'Test.php');
    }

    /**
     * Point out what the generated service names but the generator did not write.
     */
    protected function warnAboutMissingCollaborators(): void
    {
        $repository = $this->namespacedRepository();

        if ($repository !== null && ! $this->files->exists($this->getPath($repository))) {
            $this->components->warn(sprintf('Repository [%s] does not exist yet.', $repository));
        }
    }
}
