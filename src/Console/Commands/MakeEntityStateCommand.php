<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\EditsEntityClass;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesManifestTypes;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;

/**
 * Adds state to an entity that exists, which is how `kit:apply` builds a property the structure
 * manifest lists under an entity's `state` (structure.md). Each `--field` becomes:
 * - a private property the constructor promotes, after the ones it has;
 * - a parameter of `reconstitute()`, passed on to `new self(…)` by name;
 * - a getter of the same name at the end of the Getter Section.
 *
 * `create()` is left alone, because what a new entity starts with is a rule a person decides: a
 * status that starts at its first case, a balance at zero. The command says so, and the entity's
 * Unit test gets a todo in its `reconstitute()` cases for each property.
 *
 * Types are written the way the manifest writes them: a builtin, a class of the same aggregate by
 * its name, `Shared/Name` or `Context/Aggregate/Name`.
 */
#[Signature('make:entity-state {entity : The entity name, without the Entity suffix} {--domain= : Context/Aggregate that owns the entity} {--field=* : A property as name:Type, in order}')]
#[Description('Add state to a domain entity: a property, its place in reconstitute(), and its getter')]
class MakeEntityStateCommand extends Command
{
    use EditsEntityClass, ResolvesDomain, ResolvesManifestTypes;

    private const string MARKER = '// define attribute here';

    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->forgetResolvedDomain();

        $domain = $this->resolveDomain();

        if ($domain === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        $segments = explode('\\', $domain);

        if (count($segments) !== 2 || $segments[0] === self::SHARED_KERNEL) {
            $this->components->error('An entity belongs to an aggregate: pass --domain={Context}/{Aggregate}.');

            return self::FAILURE;
        }

        [$context, $aggregate] = $segments;
        $entity = Str::of((string) $this->argument('entity'))->studly()->chopEnd('Entity')->toString();
        $namespace = "App\\Domain\\{$context}\\{$aggregate}".($entity === $aggregate ? '' : '\\Entities');
        $path = app_path(str_replace('\\', '/', Str::after($namespace, 'App\\'))."/{$entity}Entity.php");

        if (! $this->files->exists($path)) {
            $this->components->error("{$entity}Entity is not built yet — run `make:entity {$entity} --domain={$context}/{$aggregate}".($entity === $aggregate ? '' : ' --child').'` first.');

            return self::FAILURE;
        }

        $imports = [];
        $fields = $this->fields($context, $aggregate, $namespace, $imports);
        $code = is_array($fields) ? $this->withState($this->files->get($path), $fields, $entity) : $fields;

        if (is_string($fields) || ! is_array($code)) {
            $this->components->error(is_string($fields) ? $fields : (string) $code);

            return self::FAILURE;
        }

        $this->files->put($path, $this->withImports($code[0], $imports));

        $names = implode(', ', array_keys($fields));
        $this->components->info(sprintf('State [%s] added to %sEntity, with a getter each.', $names, $entity));
        $this->components->warn(sprintf(
            'create() still builds new self(…) without %s — pass what a new %s starts with there, and map each in the repository\'s toModel() and toEntity().',
            $names,
            $entity,
        ));

        $this->addTest($namespace, $entity, array_keys($fields));

        return self::SUCCESS;
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
     * Each `--field` by name with the code of its type, or what is wrong with one.
     *
     * @param  array<string, string>  $imports
     * @return array<string, string>|string
     */
    private function fields(string $context, string $aggregate, string $namespace, array &$imports): array|string
    {
        $fields = [];

        foreach ((array) $this->option('field') as $field) {
            [$name, $type] = array_map(trim(...), explode(':', (string) $field, 2) + [1 => '']);

            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1 || $type === '') {
                return "The field {$field} is not name:Type with a camelCase name.";
            }

            if ($name === StructureFiles::ENTITY_ID) {
                return 'Every entity already holds its id.';
            }

            if (isset($fields[$name])) {
                return "The field {$name} is given twice.";
            }

            $resolved = $this->manifestTypeCode($type, $context, $aggregate, $namespace, $imports, "the field {$name}");

            if (is_string($resolved)) {
                return $resolved;
            }

            $fields[$name] = $resolved['code'];
        }

        return $fields === [] ? 'Name the state to add with --field=name:Type.' : $fields;
    }

    /**
     * The class with each property promoted by the constructor, taken by `reconstitute()` and read
     * by a getter, or why the class is not laid out the way `make:entity` writes it.
     *
     * @param  array<string, string>  $fields
     * @return array{0: string}|string
     */
    private function withState(string $code, array $fields, string $entity): array|string
    {
        $lines = explode("\n", $code);
        $constructor = $this->lineOf($lines, '/function __construct\($/');
        $constructorEnd = $constructor === null ? null : $this->lineOf($lines, '/^\s*\)( \{\}| \{)$/', $constructor);
        $reconstitute = $this->lineOf($lines, '/function reconstitute\($/');
        $signatureEnd = $reconstitute === null ? null : $this->lineOf($lines, '/^\s*\): \??(self|static|\w+Entity) \{$/', $reconstitute);
        $build = $signatureEnd === null ? null : $this->lineOf($lines, '/new (self|static)\($/', $signatureEnd);
        $buildEnd = $build === null ? null : $this->lineOf($lines, '/^\s*\);$/', $build);

        if ($constructor === null || $constructorEnd === null) {
            return "{$entity}Entity has no constructor laid out one parameter per line, the way make:entity writes it — add the state by hand.";
        }

        if ($reconstitute === null || $signatureEnd === null || $build === null || $buildEnd === null) {
            return "{$entity}Entity has no reconstitute() laid out the way make:entity writes it — add the state by hand.";
        }

        $promoted = implode("\n", array_slice($lines, $constructor, $constructorEnd - $constructor));

        foreach (array_keys($fields) as $name) {
            if (preg_match('/\$'.$name.'\b/', $promoted) === 1) {
                return "{$entity}Entity already holds {$name}.";
            }
        }

        $marker = $this->lineOf(array_slice($lines, 0, $constructorEnd, preserve_keys: true), '/^\s*'.preg_quote(self::MARKER, '/').'$/', $constructor);
        $indent = fn (int $line): string => str_repeat(' ', strlen($lines[$line]) - strlen(ltrim($lines[$line])) + 4);
        $insertions = [
            $marker ?? $constructorEnd => array_map(fn (string $name, string $type): string => $indent($constructorEnd)."private {$type} \${$name},", array_keys($fields), $fields),
            $signatureEnd => array_map(fn (string $name, string $type): string => $indent($signatureEnd)."{$type} \${$name},", array_keys($fields), $fields),
            $buildEnd => array_map(fn (string $name): string => $indent($buildEnd)."{$name}: \${$name},", array_keys($fields)),
        ];
        krsort($insertions);

        foreach ($insertions as $at => $inserted) {
            $this->closeLastArgument($lines, $at);
            array_splice($lines, $at, 0, $inserted);
        }

        $code = implode("\n", $lines);

        foreach ($fields as $name => $type) {
            $code = $this->withMethod($code, self::GETTERS, "    public function {$name}(): {$type}\n    {\n        return \$this->{$name};\n    }\n", $entity);
        }

        return [$code];
    }

    /**
     * Gives the argument before a closing line its trailing comma, so a list written without one
     * takes the next argument.
     *
     * @param  list<string>  $lines
     */
    private function closeLastArgument(array &$lines, int $at): void
    {
        $before = $at - 1;

        while ($before > 0 && preg_match('/^\s*\/\//', $lines[$before]) === 1) {
            $before--;
        }

        $line = rtrim($lines[$before]);

        if ($line !== '' && ! str_ends_with($line, ',') && ! str_ends_with($line, '(')) {
            $lines[$before] = $line.',';
        }
    }

    /**
     * The first line from `$from` that matches, or null.
     *
     * @param  array<int, string>  $lines
     */
    private function lineOf(array $lines, string $pattern, int $from = 0): ?int
    {
        foreach ($lines as $index => $line) {
            if ($index >= $from && preg_match($pattern, $line) === 1) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Adds a todo per property to the `reconstitute()` cases of the entity's Unit test.
     *
     * @param  list<string>  $names
     */
    private function addTest(string $namespace, string $entity, array $names): void
    {
        $path = base_path('tests/Unit/'.str_replace('\\', '/', Str::after($namespace, 'App\\'))."/{$entity}EntityTest.php");
        $relative = Str::after($path, base_path().DIRECTORY_SEPARATOR);
        $lines = $this->files->exists($path) ? explode("\n", $this->files->get($path)) : [];
        $describe = $this->lineOf($lines, "/^(\s*)describe\('reconstitute\(\)'/");
        $close = $describe === null ? null : $this->lineOf($lines, '/^'.preg_quote(substr($lines[$describe], 0, strlen($lines[$describe]) - strlen(ltrim($lines[$describe]))), '/').'\}\);$/', $describe + 1);

        if ($close === null) {
            $this->components->warn("Test [{$relative}] has no describe('reconstitute()') — write the cases of the new state by hand.");

            return;
        }

        $cases = [];

        foreach ($names as $name) {
            $cases = [...$cases, '', "        it('carries {$name}')->todo();"];
        }

        array_splice($lines, $close, 0, $close === $describe + 1 ? array_slice($cases, 1) : $cases);
        $this->files->put($path, implode("\n", $lines));

        $this->components->info("Test [{$relative}] gained a todo for each new property.");
    }
}
