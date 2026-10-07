<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * A port where layers.md keeps it, and the adapter that implements it.
 *
 * `--domain` writes `Domain/{Context}/Ports/{Name}` (`--domain=Shared` the shared kernel's), and
 * `--application` writes `Application/{Context}/{Name}`. With `--adapter` and `--infra` it also
 * writes `Infra/{Infra}/{Adapter}{Name}`, one method per method of the port, each throwing until
 * it is written, and the adapter's test with a todo for each. A port that already exists is read,
 * never overwritten, so a run after its methods are filled in writes an adapter that matches.
 * The binding is printed, never edited in.
 */
#[Signature('make:port {name : The port name, an interface with no suffix} {--domain= : The domain context whose Ports folder holds the port, or Shared} {--application= : The application context the port sits at the root of} {--adapter= : The prefix of the adapter, the technology it is built on, such as Laravel} {--infra= : The folder under app/Infra the adapter goes in, such as Security} {--force : Overwrite the port if it already exists}')]
#[Description('Create a port where layers.md keeps it, and optionally its adapter and the adapter test')]
class MakePortCommand extends GeneratorCommand implements PromptsForMissingInput
{
    protected $type = 'Port';

    /**
     * @var list<string> the classes the adapter being rendered imports
     */
    protected array $imports = [];

    #[Override]
    public function handle(): int
    {
        $problem = $this->optionsProblem();

        if ($problem !== null) {
            $this->components->error($problem);

            return self::FAILURE;
        }

        $port = $this->qualifyClass($this->getNameInput());

        if (! $this->files->exists($this->getPath($port)) || $this->option('force')) {
            if (parent::handle() === false) {
                return self::FAILURE;
            }
        } else {
            $this->components->info(sprintf('Port [%s] already exists, read as it is.', $port));
        }

        if (! filled($this->option('adapter'))) {
            return self::SUCCESS;
        }

        $adapter = $this->adapterClass();

        $this->writeAdapter($port, $adapter);
        $this->writeAdapterTest($port, $adapter);

        $this->components->info('Bind it in a service provider under app/Infra:');
        $this->line(sprintf('    %s::class => %s::class,', class_basename($port), class_basename($adapter)));

        return self::SUCCESS;
    }

    #[Override]
    protected function getStub()
    {
        return WorkflowKit::stubPath('port.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        if (filled($this->option('application'))) {
            return $rootNamespace.'\Application\\'.$this->segments('application');
        }

        return $rootNamespace.'\Domain\\'.$this->segments('domain').'\Ports';
    }

    #[Override]
    protected function getNameInput()
    {
        return (string) Str::of($this->argument('name'))->trim()->replace('/', '\\')->afterLast('\\')->studly();
    }

    #[Override]
    protected function buildClass($name)
    {
        $summary = filled($this->option('application'))
            ? "What a use case needs from outside the core, in the application's own types. An adapter\n * in App\\Infra implements it and a service provider binds the two (layers.md)."
            : "What the domain needs from outside the core, in the domain's own types. An adapter in\n * App\\Infra implements it and a service provider binds the two (layers.md).";

        return str_replace('{{ summary }}', $summary, parent::buildClass($name));
    }

    /**
     * Why these options cannot place the port and its adapter, or null when they can.
     */
    protected function optionsProblem(): ?string
    {
        $domain = filled($this->option('domain'));
        $application = filled($this->option('application'));

        return match (true) {
            $domain === $application => 'Pass one of --domain={Context} (or --domain=Shared) and --application={Context}.',
            $application && $this->segments('application') === 'Shared' => 'The shared kernel is domain code: pass --domain=Shared.',
            filled($this->option('adapter')) !== filled($this->option('infra')) => 'An adapter needs both --adapter={Prefix} and --infra={Folder}.',
            default => null,
        };
    }

    /**
     * An option's path as StudlyCase namespace segments: `credit/wallet` → `Credit\Wallet`.
     */
    protected function segments(string $option): string
    {
        return implode('\\', array_map(
            fn (string $segment): string => Str::studly($segment),
            array_filter(preg_split('#[\\\\/]#', $this->stringOption($option)) ?: []),
        ));
    }

    /**
     * An option's text, trimmed, or an empty string when it was not given.
     */
    protected function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return class-string
     */
    protected function adapterClass(): string
    {
        /** @var class-string */
        return $this->rootNamespace().'Infra\\'.$this->segments('infra').'\\'.Str::studly($this->stringOption('adapter')).$this->getNameInput();
    }

    protected function writeAdapter(string $port, string $adapter): void
    {
        $path = $this->getPath($adapter);

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Adapter [%s] already exists.', $adapter));

            return;
        }

        $this->imports = [$port];

        $methods = array_map(fn (ReflectionMethod $method): string => $this->renderMethod($method, $adapter), $this->portMethods($port));

        if ($methods !== []) {
            $this->imports = [...$this->imports, 'LogicException', 'Override'];
        }

        $namespace = Str::beforeLast($adapter, '\\');
        $imports = array_values(array_unique(array_filter(
            $this->imports,
            fn (string $class): bool => Str::beforeLast($class, '\\') !== $namespace || ! str_contains($class, '\\'),
        )));

        $contents = str_replace(
            ['{{ namespace }}', '{{ useStatements }}', '{{ port }}', '{{ prefix }}', '{{ class }}', '{{ methods }}'],
            [
                $namespace,
                implode('', array_map(fn (string $class): string => "use {$class};\n", $imports)),
                class_basename($port),
                Str::studly($this->stringOption('adapter')),
                class_basename($adapter),
                $methods === [] ? '    //' : implode("\n\n", $methods),
            ],
            $this->files->get(WorkflowKit::stubPath('port-adapter.stub')),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $this->sortImports($contents));

        $this->components->info(sprintf('Adapter [%s] created successfully.', $this->relativePath($path)));
    }

    protected function writeAdapterTest(string $port, string $adapter): void
    {
        $path = base_path('tests/Feature/'.str_replace('\\', '/', Str::after($adapter, $this->rootNamespace())).'Test.php');

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Test [%s] already exists.', $this->relativePath($path)));

            return;
        }

        $cases = array_map(
            fn (ReflectionMethod $method): string => sprintf(
                "    describe('%s', function () {\n        it('does what %s::%s() promises')->todo();\n\n        it('fails the way the port says it fails')->todo();\n    });",
                $method->getName(),
                class_basename($port),
                $method->getName(),
            ),
            $this->portMethods($port),
        );

        $contents = str_replace(
            ['{{ namespacedAdapter }}', '{{ adapter }}', '{{ cases }}'],
            [
                $adapter,
                class_basename($adapter),
                $cases === [] ? sprintf("    it('implements every method of %s')->todo();", class_basename($port)) : implode("\n\n", $cases),
            ],
            $this->files->get(WorkflowKit::stubPath('port-adapter-test.stub')),
        );

        $this->makeDirectory($path);
        $this->files->put($path, $contents);

        $this->components->info(sprintf('Test [%s] created successfully.', $this->relativePath($path)));
    }

    /**
     * The methods the port declares, in its order. Empty for a port that cannot be loaded, which
     * leaves the adapter to be written by hand.
     *
     * @return list<ReflectionMethod>
     */
    protected function portMethods(string $port): array
    {
        if (! interface_exists($port)) {
            $this->components->warn(sprintf('[%s] is not an interface that loads, so the adapter has no methods yet.', $port));

            return [];
        }

        return (new ReflectionClass($port))->getMethods();
    }

    protected function renderMethod(ReflectionMethod $method, string $adapter): string
    {
        $parameters = implode(', ', array_map($this->renderParameter(...), $method->getParameters()));
        $return = $method->getReturnType() === null ? '' : ': '.$this->renderType($method->getReturnType());

        return sprintf(
            "    #[Override]\n    public function %s(%s)%s\n    {\n        throw new LogicException('%s::%s() is not implemented yet.');\n    }",
            $method->getName(),
            $parameters,
            $return,
            class_basename($adapter),
            $method->getName(),
        );
    }

    protected function renderParameter(ReflectionParameter $parameter): string
    {
        $text = $parameter->getType() === null ? '' : $this->renderType($parameter->getType()).' ';
        $text .= ($parameter->isPassedByReference() ? '&' : '').($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $text .= ' = '.$this->renderDefault($parameter);
        }

        return $text;
    }

    protected function renderType(ReflectionType $type): string
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return implode($type instanceof ReflectionUnionType ? '|' : '&', array_map($this->renderType(...), $type->getTypes()));
        }

        if (! $type instanceof ReflectionNamedType) {
            return (string) $type;
        }

        $name = $type->getName();

        if (! $type->isBuiltin() && ! in_array($name, ['self', 'static'], true)) {
            $this->imports[] = $name;
            $name = class_basename($name);
        }

        return $type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true) ? '?'.$name : $name;
    }

    protected function renderDefault(ReflectionParameter $parameter): string
    {
        if ($parameter->isDefaultValueConstant()) {
            $constant = (string) $parameter->getDefaultValueConstantName();

            /*
             * `self::X` names the port's constant, which the adapter inherits, so it reads the
             * same in the adapter.
             */
            if (str_contains($constant, '::') && ! in_array(Str::before($constant, '::'), ['self', 'static', 'parent'], true)) {
                [$class, $name] = explode('::', $constant, 2);
                $this->imports[] = $class;

                return class_basename($class).'::'.$name;
            }

            return $constant;
        }

        $value = $parameter->getDefaultValue();

        return match (true) {
            $value === null => 'null',
            $value === [] => '[]',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };
    }

    protected function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
