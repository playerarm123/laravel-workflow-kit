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

#[Signature('make:eloquent-repository {name} {--domain= : Domain namespace under App\Domain that owns the entity} {--model= : Eloquent model backing the repository} {--only-repository : Skip the collaborators and create the repository class alone} {--force : Overwrite the repository if it already exists}')]
#[Description('Create a new Eloquent repository for a domain entity, along with the collaborators it depends on')]
class MakeEloquentRepositoryCommand extends GeneratorCommand implements PromptsForMissingInput
{
    use ResolvesDomain;
    use WritesClassesFromStubs;

    protected $type = 'Eloquent repository';

    #[Override]
    /**
     * The collaborators are written before the repository so that a repository that already
     * exists still gets the interface, exceptions and payload it is missing.
     */
    public function handle(): int
    {
        $this->forgetResolvedDomain();

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        $this->createCollaborators();

        $created = parent::handle() !== false;

        $this->warnAboutMissingCollaborators();

        return $created ? self::SUCCESS : self::FAILURE;
    }

    #[Override]
    protected function getStub()
    {
        return WorkflowKit::stubPath('eloquent-repository.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Infra\Persistence\Eloquent\Repositories';
    }

    #[Override]
    protected function getNameInput()
    {
        return 'Eloquent'.$this->entityName().'Repository';
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
        $entity = $this->entityName();
        $domainNamespace = $this->domainNamespace();

        return [
            '{{ entity }}' => $entity.'Entity',
            '{{ namespacedEntity }}' => $domainNamespace.'\\'.$entity.'Entity',
            '{{ repositoryInterface }}' => $entity.'Repository',
            '{{ namespacedRepositoryInterface }}' => $domainNamespace.'\\'.$entity.'Repository',
            '{{ notFoundException }}' => $entity.'NotFoundException',
            '{{ namespacedNotFoundException }}' => $domainNamespace.'\\Exceptions\\'.$entity.'NotFoundException',
            '{{ repositoryException }}' => $entity.'RepositoryException',
            '{{ namespacedRepositoryException }}' => $domainNamespace.'\\Exceptions\\'.$entity.'RepositoryException',
            '{{ logPayload }}' => $entity.'LogPayload',
            '{{ namespacedLogPayload }}' => $this->rootNamespace().'Infra\\Logging\\EntityPayloads\\'.$entity.'LogPayload',
            '{{ model }}' => $this->modelName(),
            '{{ namespacedModel }}' => $this->qualifyModel($this->modelName()),
        ];
    }

    /**
     * The entity base name, stripped of any Eloquent/Entity/Repository decoration.
     */
    protected function entityName(): string
    {
        return (string) Str::of($this->argument('name'))
            ->trim()
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopStart('Eloquent')
            ->chopEnd('Repository')
            ->chopEnd('Entity');
    }

    protected function modelName(): string
    {
        $model = $this->option('model');

        return filled($model) ? Str::studly($model) : $this->entityName();
    }

    protected function domainSubject(): string
    {
        return 'entity';
    }

    protected function ownedByAggregate(): bool
    {
        return true;
    }

    /**
     * The namespace of the domain owning the entity.
     */
    protected function domainNamespace(): string
    {
        return $this->rootNamespace().'Domain\\'.$this->resolveDomain();
    }

    /**
     * Create the interface, the exceptions, the log payload and the test the generated repository depends on.
     *
     * They are written by default because the repository does not compile without them; pass
     * --only-repository when they are already hand written somewhere else.
     */
    protected function createCollaborators(): void
    {
        if ($this->option('only-repository')) {
            return;
        }

        $replacements = $this->stubReplacements();

        $this->createFromStub(
            'repository-interface.stub',
            $replacements['{{ namespacedRepositoryInterface }}'],
            'Repository interface',
        );

        $this->createFromStub(
            'entity-not-found-exception.stub',
            $replacements['{{ namespacedNotFoundException }}'],
            'Not found exception',
        );

        $this->createFromStub(
            'repository-exception.stub',
            $replacements['{{ namespacedRepositoryException }}'],
            'Repository exception',
        );

        $this->createFromStub(
            'entity-log-payload.stub',
            $replacements['{{ namespacedLogPayload }}'],
            'Log payload',
        );

        $this->createTest();
    }

    /**
     * The repository's test file, already calling `repositoryContract()` with hooks that throw
     * until they are filled in — so a repository is never left without its required cases.
     */
    protected function createTest(): void
    {
        $path = base_path('tests/Feature/Infra/Persistence/Eloquent/Repositories/'.$this->getNameInput().'Test.php');

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Repository test [%s] already exists.', $this->relativePath($path)));

            return;
        }

        $contents = str_replace(
            array_keys($this->stubReplacements()),
            array_values($this->stubReplacements()),
            $this->files->get(WorkflowKit::stubPath('eloquent-repository-test.stub')),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $contents);

        $this->components->info(sprintf('Repository test [%s] created successfully.', $this->relativePath($path)));
    }

    /**
     * Point out the classes the generated repository expects but that do not exist yet.
     *
     * The entity and the model have their own generators, so they are only ever reported here.
     */
    protected function warnAboutMissingCollaborators(): void
    {
        $replacements = $this->stubReplacements();
        $domain = str_replace('\\', '/', (string) $this->resolveDomain());

        $expected = [
            'Entity' => [
                $replacements['{{ namespacedEntity }}'],
                sprintf('php artisan make:entity %s --domain=%s', $this->entityName(), $domain),
            ],
            'Repository interface' => [$replacements['{{ namespacedRepositoryInterface }}'], null],
            'Not found exception' => [$replacements['{{ namespacedNotFoundException }}'], null],
            'Repository exception' => [$replacements['{{ namespacedRepositoryException }}'], null],
            'Log payload' => [$replacements['{{ namespacedLogPayload }}'], null],
            'Model' => [
                $replacements['{{ namespacedModel }}'],
                sprintf('php artisan make:model %s', $this->modelName()),
            ],
        ];

        foreach ($expected as $label => [$class, $hint]) {
            if ($this->files->exists($this->getPath($class))) {
                continue;
            }

            $this->components->warn(trim(sprintf(
                '%s [%s] does not exist yet. %s',
                $label,
                $class,
                $hint === null ? '' : sprintf('Create it with `%s`.', $hint),
            )));
        }
    }
}
