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
 * Adds a behaviour or an assertion to a value object that exists, which is how `kit:apply` builds a
 * method the structure manifest lists (structure.md). A value object never changes, so a behaviour
 * returns a new one: it starts as a copy built from the constructor's fields, for a person to
 * change. A name that starts with `assert` is an assertion, which returns void with its body left
 * empty. Both go before the class closes, and every exception the method is meant to throw is
 * named in `@throws`. The value object's Unit test gets a `describe()` for the method with a todo
 * for the case that passes and one per exception (testing.md).
 *
 * Parameters and exceptions are written the way the manifest writes them: a builtin, a class of
 * the same aggregate by its name, `Shared/Name`, or `Context/Aggregate/Name`. A variadic
 * parameter's type starts with `...`.
 */
#[Signature('make:value-object-method {valueObject : The value object name} {method : The camelCase method name, assert* for an assertion} {--domain= : Context/Aggregate that owns the value object, or Shared for the shared kernel} {--param=* : A parameter as name:Type, in order} {--throws=* : An exception the method throws, named as the manifest names it}')]
#[Description('Add a behaviour or an assertion to a domain value object, and its test todos')]
class MakeValueObjectMethodCommand extends Command
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

        if (! ($segments === [self::SHARED_KERNEL] || (count($segments) === 2 && $segments[0] !== self::SHARED_KERNEL))) {
            $this->components->error('A value object belongs to an aggregate or the shared kernel: pass --domain={Context}/{Aggregate} or --domain=Shared.');

            return self::FAILURE;
        }

        $context = $segments[0];
        $aggregate = $segments[1] ?? null;
        $valueObject = Str::studly((string) $this->argument('valueObject'));
        $method = (string) $this->argument('method');
        $namespace = "App\\Domain\\{$domain}\\ValueObjects";
        $path = app_path(str_replace('\\', '/', Str::after($namespace, 'App\\'))."/{$valueObject}.php");

        $refusal = match (true) {
            preg_match('/^[a-z][A-Za-z0-9]*$/', $method) !== 1 => "The method {$method} is not a camelCase name.",
            ! $this->files->exists($path) => "{$valueObject} is not built yet — run `make:value-object {$valueObject} --domain=".str_replace('\\', '/', $domain).'` first.',
            preg_match('/function\s+'.$method.'\s*\(/', $this->files->get($path)) === 1 => "{$valueObject} already has {$method}().",
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
        $code = $this->withImports($this->files->get($path), $imports);
        $code = $this->beforeClassCloses($code, $this->methodCode($method, $parameters, array_keys($exceptions), $assertion ? null : $this->copyOf($code)));
        $this->files->put($path, $code);

        $this->components->info(sprintf('%s [%s] added to %s.', $assertion ? 'Assertion' : 'Behaviour', $method, $valueObject));

        $this->addTest(base_path('tests/Unit/'.str_replace('\\', '/', Str::after($namespace, 'App\\')))."/{$valueObject}Test.php", $method, array_keys($exceptions));

        return self::SUCCESS;
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
     * The expression a new behaviour returns until a person writes it: a copy built from the
     * constructor's promoted fields, or the value object itself when the constructor does not
     * promote them all.
     */
    private function copyOf(string $code): string
    {
        if (preg_match('/function __construct\s*\((.*?)\)\s*(\{|$)/sm', $code, $match) !== 1) {
            return '$this';
        }

        $parameters = array_filter(array_map(trim(...), explode(',', $match[1])), fn (string $parameter): bool => $parameter !== '');
        $names = [];

        foreach ($parameters as $parameter) {
            if (preg_match('/^(?:public|protected|private)\b.*\$(\w+)/', $parameter, $name) !== 1) {
                return '$this';
            }

            $names[] = '$this->'.$name[1];
        }

        return 'new self('.implode(', ', $names).')';
    }

    /**
     * @param  list<string>  $parameters
     * @param  list<string>  $exceptions
     * @param  string|null  $returns  what a behaviour returns, null for an assertion
     */
    private function methodCode(string $method, array $parameters, array $exceptions, ?string $returns): string
    {
        $docblock = $exceptions === [] ? '' : "    /**\n".implode('', array_map(fn (string $exception): string => "     * @throws {$exception}\n", $exceptions))."     */\n";
        $signature = "    public function {$method}(".implode(', ', $parameters).'): '.($returns === null ? 'void' : 'self');

        return $docblock.$signature."\n    {\n        ".($returns === null ? '//' : "return {$returns};")."\n    }\n";
    }

    /**
     * The file with a member added as the last one of the class.
     */
    private function beforeClassCloses(string $code, string $member): string
    {
        $lines = explode("\n", rtrim($code, "\n"));
        $close = (int) array_key_last(array_filter($lines, fn (string $line): bool => $line === '}'));
        $previous = $lines[$close - 1] ?? '';
        $added = explode("\n", rtrim($member, "\n"));

        array_splice($lines, $close, 0, trim($previous) === '{' ? $added : ['', ...$added]);

        return implode("\n", $lines)."\n";
    }
}
