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

use function Laravel\Prompts\select;

#[Signature('make:domain-exception {name : The exception name, without the Exception suffix} {--domain= : Context/Aggregate for a refusal or a value, Shared for a shared value, the bare Context for an application exception} {--kind= : refusal, value or application} {--use-case= : The use case folder an application exception belongs to} {--force : Overwrite the exception if it already exists}')]
#[Description('Create a refusal, invalid value or application exception on the base exceptions.md names for its kind')]
class MakeDomainExceptionCommand extends GeneratorCommand implements PromptsForMissingInput
{
    use ResolvesDomain;

    protected const string REFUSAL = 'refusal';

    protected const string VALUE = 'value';

    protected const string APPLICATION = 'application';

    protected $type = 'Exception';

    protected ?string $kind = null;

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();
        $this->kind = null;

        $kind = $this->resolveKind();

        if ($kind === null) {
            $this->components->error('Could not tell which kind of exception this is. Pass --kind=refusal, --kind=value or --kind=application.');

            return self::FAILURE;
        }

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        $problem = $this->domainProblem();

        if ($problem !== null) {
            $this->components->error($problem);

            return self::FAILURE;
        }

        if ($kind === self::REFUSAL) {
            $this->createContextBase();
        }

        return parent::handle() === false ? self::FAILURE : self::SUCCESS;
    }

    #[Override]
    protected function getStub()
    {
        return WorkflowKit::stubPath('domain-exception.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        $domain = (string) $this->resolveDomain();

        if ($this->kind === self::APPLICATION) {
            $useCase = $this->useCaseName();

            return $rootNamespace.'\Application\\'.$domain.($useCase === null ? '' : '\UseCases\\'.$useCase);
        }

        return $rootNamespace.'\Domain\\'.$domain.'\Exceptions';
    }

    #[Override]
    protected function getNameInput()
    {
        return $this->exceptionName().'Exception';
    }

    #[Override]
    protected function buildClass($name)
    {
        $base = $this->baseClass();
        $imports = $this->getNamespace($name) === Str::beforeLast($base, '\\') ? '' : 'use '.$base.";\n\n";

        return str_replace(
            ['{{ useStatements }}', '{{ base }}', '{{ summary }}'],
            [$imports, class_basename($base), $this->summary()],
            parent::buildClass($name),
        );
    }

    /**
     * The name without any Exception decoration.
     */
    protected function exceptionName(): string
    {
        return (string) Str::of($this->argument('name'))
            ->trim()
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopEnd('Exception');
    }

    protected function useCaseName(): ?string
    {
        $useCase = $this->option('use-case');

        return blank($useCase) ? null : Str::studly(trim((string) $useCase));
    }

    /**
     * The base exceptions.md names for the kind: a refusal extends its context's base, so a
     * catch of the base is visibly a catch of every reason in the context.
     */
    protected function baseClass(): string
    {
        return match ($this->kind) {
            self::REFUSAL => $this->contextBaseClass(),
            self::VALUE => $this->rootNamespace().'Domain\Shared\Exceptions\DomainValueException',
            default => $this->rootNamespace().'Application\ApplicationException',
        };
    }

    protected function contextBaseClass(): string
    {
        $context = $this->contextName();

        return $this->rootNamespace().'Domain\\'.$context.'\Exceptions\\'.$context.'DomainException';
    }

    protected function contextName(): string
    {
        return Str::before((string) $this->resolveDomain(), '\\');
    }

    protected function summary(): string
    {
        return match ($this->kind) {
            self::REFUSAL => "A business rule of this aggregate says no. The controller that can tell the user what to\n * do next catches it by name (exceptions.md).",
            self::VALUE => "A value the domain refuses to hold. It is the caller's bug, because the FormRequest should\n * have stopped it, so no entry point catches it (exceptions.md).",
            default => "A use case says no. The controller that can tell the user what to do next catches it by\n * name (exceptions.md).",
        };
    }

    /**
     * Why the domain cannot hold this kind of exception, or null when it can. A refusal and a
     * value belong to an aggregate, an application exception to a bare context.
     */
    protected function domainProblem(): ?string
    {
        $segments = explode('\\', (string) $this->resolveDomain());
        $shared = $segments === [self::SHARED_KERNEL];

        return match (true) {
            $this->kind === self::VALUE && $shared => null,
            $segments[0] === self::SHARED_KERNEL => 'Only --kind=value may go to the shared kernel (--domain=Shared).',
            $this->kind === self::APPLICATION && count($segments) !== 1 => 'An application exception belongs to a context: pass --domain={Context}.',
            $this->kind === self::APPLICATION => null,
            $this->useCaseName() !== null => 'Only --kind=application takes --use-case.',
            count($segments) !== 2 && $this->kind === self::REFUSAL => 'A refusal belongs to an aggregate: pass --domain={Context}/{Aggregate}. A domain service writes its own with make:domain-service --exception.',
            count($segments) !== 2 => 'An invalid value belongs to an aggregate or the shared kernel: pass --domain={Context}/{Aggregate} or --domain=Shared.',
            default => null,
        };
    }

    /**
     * Write `{Context}DomainException` the first time a refusal lands in a context. An existing
     * base, abstract or not, is kept as it is.
     */
    protected function createContextBase(): void
    {
        $class = $this->contextBaseClass();
        $path = $this->getPath($class);

        if ($this->files->exists($path)) {
            return;
        }

        $this->makeDirectory($path);
        $this->files->put($path, str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [Str::beforeLast($class, '\\'), class_basename($class)],
            $this->files->get(WorkflowKit::stubPath('domain-exception-context-base.stub')),
        ));

        $this->components->info(sprintf('Context base [%s] created successfully.', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)));
    }

    /**
     * The kind decides the base and the folder, so it is asked for before the domain.
     */
    protected function resolveKind(): ?string
    {
        if ($this->kind !== null) {
            return $this->kind;
        }

        $kind = $this->option('kind');
        $kinds = [self::REFUSAL, self::VALUE, self::APPLICATION];

        if (filled($kind)) {
            return in_array($kind, $kinds, true) ? $this->kind = $kind : null;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        return $this->kind = (string) select(
            label: 'Which kind of exception is it?',
            options: [
                self::REFUSAL => 'Refusal: a rule of an aggregate says no, and the user can act on it',
                self::VALUE => 'Invalid value: a value object refuses a value, the caller\'s bug',
                self::APPLICATION => 'Application: a use case says no',
            ],
        );
    }

    /**
     * A refusal and a value live in an aggregate, so the picker offers {Context}/{Aggregate}.
     */
    protected function ownedByAggregate(): bool
    {
        return $this->kind !== self::APPLICATION;
    }

    protected function domainSubject(): string
    {
        return 'exception';
    }
}
