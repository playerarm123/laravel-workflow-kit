<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesManifestTypes;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * Writes a value object into an aggregate's `ValueObjects` folder, or the shared kernel's, with its
 * Unit test at the mirrored path (testing.md). Its fields come from `--field`, in constructor
 * order, which is how `kit:apply` builds a value object the structure manifest lists
 * (structure.md). A field's type is written the way the manifest writes it: a builtin, a class of
 * the same aggregate by its name, `Shared/Name`, or `Context/Aggregate/Name`.
 *
 * The class is the one shape every value object starts from: a private constructor, `from()`, a
 * getter per field and `equals()`. The rules it guards are written by hand.
 */
#[Signature('make:value-object {name} {--domain= : Context/Aggregate that owns the value object, or Shared for the shared kernel} {--field=* : A constructor field as name:Type, in order} {--force : Overwrite the value object if it already exists}')]
#[Description('Create a domain value object and its test')]
class MakeValueObjectCommand extends GeneratorCommand implements PromptsForMissingInput
{
    use ResolvesDomain, ResolvesManifestTypes;

    protected $type = 'Value object';

    /**
     * @var list<array{name: string, type: string, code: string, comparison: string}>
     */
    private array $fields = [];

    /**
     * @var array<string, string> imported class names by the name the code uses
     */
    private array $imports = [];

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();

        $domain = $this->resolveDomain();

        if ($domain === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        $refusal = $this->domainRefusal($domain) ?? $this->readFields();

        if ($refusal !== null) {
            $this->components->error($refusal);

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
        return WorkflowKit::stubPath('value-object.stub');
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Domain\\'.$this->resolveDomain().'\ValueObjects';
    }

    #[Override]
    protected function getNameInput()
    {
        return (string) Str::of(parent::getNameInput())->replace('/', '\\')->afterLast('\\')->studly();
    }

    #[Override]
    protected function buildClass($name)
    {
        ksort($this->imports);

        $imports = array_map(fn (string $class): string => "use {$class};", array_values($this->imports));

        return str_replace(
            ['{{ imports }}', '{{ parameters }}', '{{ arguments }}', '{{ names }}', '{{ getters }}', '{{ comparison }}'],
            [
                $imports === [] ? '' : "\n".implode("\n", $imports)."\n",
                $this->fields === [] ? '' : "\n".implode('', array_map(fn (array $field): string => "        private readonly {$field['code']} \${$field['name']},\n", $this->fields)).'    ',
                implode(', ', array_map(fn (array $field): string => "{$field['code']} \${$field['name']}", $this->fields)),
                $this->fields === [] ? '' : '('.implode(', ', array_map(fn (array $field): string => "\${$field['name']}", $this->fields)).')',
                implode('', array_map(fn (array $field): string => "\n    public function {$field['name']}(): {$field['code']}\n    {\n        return \$this->{$field['name']};\n    }\n", $this->fields)),
                $this->fields === [] ? 'true' : implode("\n            && ", array_column($this->fields, 'comparison')),
            ],
            parent::buildClass($name),
        );
    }

    /**
     * Create the unit test mirroring the value object under tests/Unit (testing.md).
     */
    protected function createTest(): void
    {
        $class = $this->qualifyClass($this->getNameInput());
        $path = base_path('tests/Unit/'.Str::chopEnd(Str::after($this->getPath($class), app_path().DIRECTORY_SEPARATOR), '.php').'Test.php');
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

        if ($this->files->exists($path)) {
            $this->components->warn(sprintf('Test [%s] already exists.', $relative));

            return;
        }

        $this->makeDirectory($path);
        $this->files->put($path, str_replace(
            ['{{ namespacedClass }}', '{{ class }}'],
            [$class, class_basename($class)],
            $this->files->get(WorkflowKit::stubPath('value-object-test.stub')),
        ));

        $this->components->info(sprintf('Test [%s] created successfully.', $relative));
    }

    protected function domainSubject(): string
    {
        return 'value object';
    }

    protected function ownedByAggregate(): bool
    {
        return true;
    }

    /**
     * A value object belongs to an aggregate or to the shared kernel, never to a bare context.
     */
    private function domainRefusal(string $domain): ?string
    {
        $segments = explode('\\', $domain);

        return match (true) {
            $segments === [self::SHARED_KERNEL] => null,
            count($segments) === 2 && $segments[0] !== self::SHARED_KERNEL => null,
            default => 'A value object belongs to an aggregate or the shared kernel: pass --domain={Context}/{Aggregate} or --domain=Shared.',
        };
    }

    /**
     * Reads each `--field` into the code it writes, or says what is wrong with one.
     */
    private function readFields(): ?string
    {
        $this->fields = [];
        $this->imports = [];

        $segments = explode('\\', (string) $this->resolveDomain());
        $context = $segments[0];
        $aggregate = $segments[1] ?? null;

        foreach ((array) $this->option('field') as $field) {
            [$name, $type] = array_map(trim(...), explode(':', (string) $field, 2) + [1 => '']);

            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1 || $type === '') {
                return "The field {$field} is not name:Type with a camelCase name.";
            }

            if (in_array($name, array_column($this->fields, 'name'), true)) {
                return "The field {$name} is given twice.";
            }

            $resolved = $this->manifestTypeCode($type, $context, $aggregate, $this->getDefaultNamespace(trim($this->rootNamespace(), '\\')), $this->imports, "the field {$name}");

            if (is_string($resolved)) {
                return $resolved;
            }

            $nullable = str_starts_with($type, '?');
            $code = $resolved['code'];
            $classes = $resolved['classes'];
            $parts = count(explode(str_contains($type, '&') ? '&' : '|', ltrim($type, '?')));

            $this->fields[] = ['name' => $name, 'type' => $type, 'code' => $code, 'comparison' => $this->comparison($name, $nullable, $parts, $classes)];
        }

        return null;
    }

    /**
     * How `equals()` compares one field: a builtin or an enum by identity, a value object through its
     * own `equals()`, and anything else by value.
     *
     * @param  list<string>  $classes
     */
    private function comparison(string $name, bool $nullable, int $parts, array $classes): string
    {
        $mine = "\$this->{$name}";
        $theirs = "\$other->{$name}";

        if ($classes === [] || ($parts === 1 && enum_exists($classes[0]))) {
            return "{$mine} === {$theirs}";
        }

        if ($parts === 1 && method_exists($classes[0], 'equals')) {
            return $nullable
                ? "({$mine} === null ? {$theirs} === null : {$theirs} !== null && {$mine}->equals({$theirs}))"
                : "{$mine}->equals({$theirs})";
        }

        return "{$mine} == {$theirs}";
    }
}
