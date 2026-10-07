<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

#[Signature('make:entity {name} {--domain= : Domain under App\Domain that owns the entity} {--child : Create a child entity in the aggregate\'s Entities folder instead of its root}')]
#[Description('Create a new domain entity')]
class MakeEntityCommand extends GeneratorCommand implements PromptsForMissingInput
{
    use ResolvesDomain;

    protected $type = 'Entity';

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->createTest();

        return self::SUCCESS;
    }

    #[Override]
    protected function getStub()
    {
        return WorkflowKit::stubPath('entity.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Domain\\'.$this->resolveDomain().($this->isChild() ? '\Entities' : '');
    }

    #[Override]
    protected function getNameInput()
    {
        return $this->entityName().'Entity';
    }

    #[Override]
    protected function buildClass($name)
    {
        $base = $this->rootNamespace().'Domain\\Shared\\'.($this->isChild() ? 'DomainEntity' : 'AggregateRoot');

        return str_replace(
            ['{{ namespacedBase }}', '{{ base }}', '{{ entityLabel }}'],
            [$base, class_basename($base), Str::headline($this->entityName())],
            parent::buildClass($name),
        );
    }

    /**
     * Create the unit test mirroring the entity under tests/Unit (testing.md).
     */
    protected function createTest(): void
    {
        $entity = $this->qualifyClass($this->getNameInput());
        $path = base_path('tests/Unit/'.Str::chopEnd(Str::after($this->getPath($entity), app_path().DIRECTORY_SEPARATOR), '.php').'Test.php');
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Test [%s] already exists.', $relative));

            return;
        }

        $this->makeDirectory($path);
        $this->files->put($path, str_replace(
            ['{{ namespacedEntity }}', '{{ entity }}'],
            [$entity, class_basename($entity)],
            $this->files->get(WorkflowKit::stubPath('entity-test.stub')),
        ));

        $this->components->info(sprintf('Test [%s] created successfully.', $relative));
    }

    /**
     * A root is the one entity a repository loads; a child lives in Entities/ and is reached
     * only through it (layers.md), so the two differ in base class as well as in folder.
     *
     * The base classes are named, not imported: a console command may not depend on them.
     */
    protected function isChild(): bool
    {
        return (bool) $this->option('child');
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
     * The entity base name, stripped of any Entity decoration.
     */
    protected function entityName(): string
    {
        return (string) Str::of(parent::getNameInput())
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopEnd('Entity');
    }
}
