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
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesDomainMethod;
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
    use EditsEntityClass, ResolvesDomain, ResolvesManifestTypes, WritesDomainMethod;

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

        $this->addTest(base_path('tests/Unit/'.str_replace('\\', '/', Str::after($namespace, 'App\\')))."/{$entity}EntityTest.php", $method, array_keys($exceptions));

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
     * @param  list<string>  $parameters
     * @param  list<string>  $exceptions
     */
    private function methodCode(string $method, array $parameters, array $exceptions): string
    {
        $docblock = $exceptions === [] ? '' : "    /**\n".implode('', array_map(fn (string $exception): string => "     * @throws {$exception}\n", $exceptions))."     */\n";

        return $docblock."    public function {$method}(".implode(', ', $parameters)."): void\n    {\n        //\n    }\n";
    }
}
