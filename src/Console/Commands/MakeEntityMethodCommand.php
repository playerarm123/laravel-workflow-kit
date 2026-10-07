<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesManifestTypes;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * Adds a behaviour or an assertion to an entity that exists, which is how `kit:apply` builds a
 * method the structure manifest lists (structure.md). A name that starts with `assert` is an
 * assertion and lands at the end of the entity's Asserting Section; any other is a behaviour and
 * lands at the end of its Behavior Section. The method returns void, its body is left empty for a
 * person to write, and every exception it is meant to throw is named in `@throws`. The entity's
 * Unit test gets a `describe()` for the method with a todo for the case that passes and one per
 * exception (testing.md).
 *
 * Parameters and exceptions are written the way the manifest writes them: a builtin, a class of
 * the same aggregate by its name, `Shared/Name`, or `Context/Aggregate/Name`. A variadic
 * parameter's type starts with `...`.
 */
#[Signature('make:entity-method {entity : The entity name, without the Entity suffix} {method : The camelCase method name, assert* for an assertion} {--domain= : Context/Aggregate that owns the entity} {--param=* : A parameter as name:Type, in order} {--throws=* : An exception the method throws, named as the manifest names it}')]
#[Description('Add a behaviour or an assertion to a domain entity, and its test todos')]
class MakeEntityMethodCommand extends Command
{
    use ResolvesDomain, ResolvesManifestTypes;

    private const string BEHAVIOURS = 'Behavior Section';

    private const string ASSERTIONS = 'Asserting Section';

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
        $method = (string) $this->argument('method');
        $namespace = "App\\Domain\\{$context}\\{$aggregate}".($entity === $aggregate ? '' : '\\Entities');
        $path = app_path(str_replace('\\', '/', Str::after($namespace, 'App\\'))."/{$entity}Entity.php");

        $refusal = match (true) {
            preg_match('/^[a-z][A-Za-z0-9]*$/', $method) !== 1 => "The method {$method} is not a camelCase name.",
            ! $this->files->exists($path) => "{$entity}Entity is not built yet — run `make:entity {$entity} --domain={$context}/{$aggregate}".($entity === $aggregate ? '' : ' --child').'` first.',
            preg_match('/function\s+'.$method.'\s*\(/', $this->files->get($path)) === 1 => "{$entity}Entity already has {$method}().",
            default => null,
        };

        $imports = [];
        $parameters = $refusal === null ? $this->parameters($context, $aggregate, $namespace, $imports) : [];
        $exceptions = $refusal === null && is_array($parameters) ? $this->exceptions($context, $aggregate, $namespace, $imports) : [];
        $refusal ??= is_string($parameters) ? $parameters : (is_string($exceptions) ? $exceptions : null);

        if ($refusal !== null || ! is_array($parameters) || ! is_array($exceptions)) {
            $this->components->error((string) $refusal);

            return self::FAILURE;
        }

        $assertion = StructureReader::isAssertion($method);
        $code = $this->files->get($path);
        $code = $this->withImports($code, $imports);
        $code = $this->withMethod($code, $assertion ? self::ASSERTIONS : self::BEHAVIOURS, $this->methodCode($method, $parameters, array_keys($exceptions)), $entity);
        $this->files->put($path, $code);

        $this->components->info(sprintf('%s [%s] added to %sEntity.', $assertion ? 'Assertion' : 'Behaviour', $method, $entity));

        $this->addTest($namespace, $entity, $method, array_keys($exceptions));

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
     * Each `--param` as the code it declares, or what is wrong with one.
     *
     * @param  array<string, string>  $imports
     * @return list<string>|string
     */
    private function parameters(string $context, string $aggregate, string $namespace, array &$imports): array|string
    {
        $parameters = [];
        $names = [];

        foreach ((array) $this->option('param') as $param) {
            [$name, $type] = array_map(trim(...), explode(':', (string) $param, 2) + [1 => '']);

            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1 || $type === '') {
                return "The parameter {$param} is not name:Type with a camelCase name.";
            }

            if (in_array($name, $names, true)) {
                return "The parameter {$name} is given twice.";
            }

            $variadic = str_starts_with($type, '...');
            $resolved = $this->manifestTypeCode($variadic ? substr($type, 3) : $type, $context, $aggregate, $namespace, $imports, "the parameter {$name}");

            if (is_string($resolved)) {
                return $resolved;
            }

            $names[] = $name;
            $parameters[] = $resolved['code'].' '.($variadic ? '...' : '').'$'.$name;
        }

        return $parameters;
    }

    /**
     * Each `--throws` by the name the code uses, or what is wrong with one.
     *
     * @param  array<string, string>  $imports
     * @return array<string, string>|string
     */
    private function exceptions(string $context, string $aggregate, string $namespace, array &$imports): array|string
    {
        $reader = new StructureReader(base_path());
        $exceptions = [];

        foreach ((array) $this->option('throws') as $throws) {
            $class = $reader->exceptionClass((string) $throws, $context, $aggregate);

            if ($class === null) {
                return "The exception {$throws} is not built yet — build it first.";
            }

            $alias = class_basename($class);

            if (isset($imports[$alias]) && $imports[$alias] !== $class) {
                return "The method names {$alias} from two places — write one of them by hand.";
            }

            if (Str::beforeLast($class, '\\') !== $namespace) {
                $imports[$alias] = $class;
            }

            $exceptions[$alias] = $class;
        }

        ksort($exceptions);

        return $exceptions;
    }

    /**
     * @param  list<string>  $parameters
     * @param  list<string>  $exceptions
     */
    private function methodCode(string $method, array $parameters, array $exceptions): string
    {
        $docblock = $exceptions === [] ? '' : "    /**\n".implode('', array_map(fn (string $exception): string => "     * @throws {$exception}\n", $exceptions))."     */\n";

        return $docblock."    public function {$method}(".implode(', ', $parameters)."): void\n    {\n        //\n    }\n";
    }

    /**
     * The file with each missing import added to its `use` lines, sorted as pint sorts them.
     *
     * @param  array<string, string>  $imports
     */
    private function withImports(string $code, array $imports): string
    {
        preg_match_all('/^use [^;]+;$/m', $code, $matches, PREG_OFFSET_CAPTURE);
        $existing = array_column($matches[0], 0);
        $lines = array_values(array_unique([...$existing, ...array_map(fn (string $class): string => "use {$class};", array_values($imports))]));

        if ($lines === $existing) {
            return $code;
        }

        usort($lines, fn (string $a, string $b): int => strcasecmp(rtrim($a, ';'), rtrim($b, ';')));

        if ($existing === []) {
            return (string) preg_replace('/^(namespace [^;]+;\n)/m', "$1\n".implode("\n", $lines)."\n", $code, 1);
        }

        $first = $matches[0][0][1];
        $last = $matches[0][count($matches[0]) - 1];
        $end = $last[1] + strlen($last[0]);

        return substr($code, 0, $first).implode("\n", $lines).substr($code, $end);
    }

    /**
     * The file with the method added at the end of a section: before the next section's header,
     * or before the class closes when the section is the last one. A method with no docblock sits
     * right under the section's header, as pint wants it. A file with no such section
     * gets the method before the class closes, and a warning.
     */
    private function withMethod(string $code, string $section, string $method, string $entity): string
    {
        $lines = explode("\n", rtrim($code, "\n"));
        $close = (int) array_key_last(array_filter($lines, fn (string $line): bool => $line === '}'));
        $header = null;

        foreach ($lines as $index => $line) {
            if (trim($line) === '* '.$section) {
                $header = $index;

                break;
            }
        }

        if ($header === null) {
            $this->components->warn("{$entity}Entity has no {$section} — the method goes before the class closes.");
        }

        $at = $close;

        foreach ($header === null ? [] : array_slice($lines, $header + 1, $close - $header - 1, preserve_keys: true) as $index => $line) {
            if (preg_match('/^\s+\* \w+ Section$/', $line) === 1) {
                $at = $index;

                while ($at > 0 && trim($lines[$at]) !== '/**') {
                    $at--;
                }

                break;
            }
        }

        $before = array_slice($lines, 0, $at);

        while ($before !== [] && trim((string) end($before)) === '') {
            array_pop($before);
        }

        $after = array_slice($lines, $at);
        $block = explode("\n", rtrim($method, "\n"));
        $afterHeader = count($before) >= 2 && trim((string) end($before)) === '*/' && str_contains($before[count($before) - 2], '* ====');
        $gap = $afterHeader && trim($block[0]) !== '/**' ? [] : [''];

        return implode("\n", [...$before, ...$gap, ...$block, ...($after[0] === '}' ? [] : ['']), ...$after])."\n";
    }

    /**
     * Adds the method's `describe()` to the entity's Unit test, at the end of its outer block.
     *
     * @param  list<string>  $exceptions
     */
    private function addTest(string $namespace, string $entity, string $method, array $exceptions): void
    {
        $path = base_path('tests/Unit/'.str_replace('\\', '/', Str::after($namespace, 'App\\'))."/{$entity}EntityTest.php");
        $relative = Str::after($path, base_path().DIRECTORY_SEPARATOR);

        if (! $this->files->exists($path)) {
            $this->components->warn("Test [{$relative}] does not exist — write the cases of {$method}() by hand.");

            return;
        }

        $code = rtrim($this->files->get($path), "\n");
        $close = strrpos($code, "\n});");

        if ($close === false) {
            $this->components->warn("Test [{$relative}] has no outer describe() to add {$method}() to — write its cases by hand.");

            return;
        }

        $cases = [
            "        it('succeeds when its rules hold')->todo();",
            ...array_map(fn (string $exception): string => "        it('throws {$exception}')->todo();", $exceptions),
        ];
        $describe = "\n\n    describe('{$method}()', function () {\n".implode("\n\n", $cases)."\n    });";

        $this->files->put($path, substr($code, 0, $close).$describe.substr($code, $close)."\n");

        $this->components->info("Test [{$relative}] gained the cases of {$method}().");
    }
}
