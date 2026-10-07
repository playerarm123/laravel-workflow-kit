<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Foundation\Console\EnumMakeCommand;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Laravel's `make:enum`, held to layers.md: an enum is part of an aggregate's vocabulary, so it is
 * written to `Domain/{Context}/{Aggregate}/Enums`, or to the shared kernel's `Domain/Shared/Enums`,
 * never to `app/Enums`. Its cases come from `--case`, which is how `kit:apply` builds an enum the
 * structure manifest lists (structure.md). `--transitions` makes it a status (states.md): it uses
 * `HasTransitions` and declares `transitions()` as a `match` over every case. Each `--transition`
 * names where one case may go (`Pending:Held,Settled`), and a case none names is final; with no
 * `--transition` at all, every case goes nowhere until a person lists where it goes.
 *
 * It writes no test: an enum is tested through the classes that use it (testing.md).
 */
#[AsCommand(name: 'make:enum')]
class MakeEnumCommand extends EnumMakeCommand
{
    use ResolvesDomain;

    protected $signature = 'make:enum
                    {name : The name of the enum}
                    {--domain= : Context/Aggregate that owns the enum, or Shared for the shared kernel}
                    {--s|string : Generate a string backed enum.}
                    {--i|int : Generate an integer backed enum.}
                    {--case=* : A case as Name, or Name=value for a backed enum, in order}
                    {--transitions : Make it a status that declares the cases each one may become (states.md)}
                    {--transition=* : Where a case of a status may go, as Case:Next,Next (implies --transitions)}
                    {--f|force : Create the enum even if the enum already exists}';

    protected $description = 'Create a domain enum with its cases';

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();

        $domain = $this->resolveDomain();

        if ($domain === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        $refusal = $this->domainRefusal($domain) ?? $this->casesRefusal()
            ?? ($this->isStatus() && $this->rawCases() === [] ? 'A status lists where each case goes: pass its cases with --case.' : null)
            ?? $this->transitionsRefusal();

        if ($refusal !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        return parent::handle() === false ? self::FAILURE : self::SUCCESS;
    }

    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Domain\\'.$this->resolveDomain().'\Enums';
    }

    #[Override]
    protected function getNameInput()
    {
        return (string) Str::of(parent::getNameInput())->replace('/', '\\')->afterLast('\\')->studly();
    }

    #[Override]
    protected function buildClass($name)
    {
        $class = parent::buildClass($name);
        $cases = $this->cases();

        if ($cases === []) {
            return $class;
        }

        $lines = array_map(fn (string $case, string|int|null $value): string => match (true) {
            $value === null => "    case {$case};",
            is_int($value) => "    case {$case} = {$value};",
            default => "    case {$case} = ".var_export($value, true).';',
        }, array_keys($cases), $cases);

        $class = str_replace("    //\n", implode("\n", $lines)."\n", $class);

        return $this->isStatus() ? $this->withTransitions($class, array_keys($cases)) : $class;
    }

    /**
     * The enum as a status: it uses HasTransitions, and `transitions()` names every case with the
     * cases `--transition` lets it become. With no `--transition`, each goes nowhere until a person
     * lists the cases it may become.
     *
     * @param  list<string>  $cases
     */
    private function withTransitions(string $class, array $cases): string
    {
        $transitions = $this->transitions();
        $arms = implode("\n", array_map(function (string $case) use ($transitions): string {
            $next = implode(', ', array_map(fn (string $target): string => "self::{$target}", $transitions[$case] ?? []));

            return "            self::{$case} => [{$next}],";
        }, $cases));
        $todo = $transitions === [] ? "\n     *\n     * @todo List the cases each one may become next, never itself; a final one stays [] (states.md)." : '';
        $method = <<<PHP

                /**
                 * @return list<self>{$todo}
                 */
                public function transitions(): array
                {
                    return match (\$this) {
            {$arms}
                    };
                }
            }
            PHP;

        $class = (string) preg_replace('/^(namespace [^;]+;\n)/m', '$1'."\nuse ".StructureReader::TRANSITIONS_TRAIT.";\n", $class, 1);
        $class = (string) preg_replace('/^(enum [^\n]+\n\{\n)/m', "\$1    use HasTransitions;\n\n", $class, 1);

        return (string) preg_replace('/\}\s*$/', $method."\n", $class, 1);
    }

    private function isStatus(): bool
    {
        return (bool) $this->option('transitions') || $this->option('transition') !== [];
    }

    /**
     * What is wrong with the transitions given, or null: each names a case once, and lets it become
     * only other cases of the enum (states.md).
     */
    private function transitionsRefusal(): ?string
    {
        $cases = array_keys($this->rawCases());
        $named = [];

        foreach ((array) $this->option('transition') as $transition) {
            if (preg_match('/^\s*(\w+)\s*:\s*(\w+(\s*,\s*\w+)*)?\s*$/', (string) $transition, $match) !== 1) {
                return "The transition {$transition} is not Case:Next,Next.";
            }

            $case = $match[1];
            $next = $this->targetsOf($match[2] ?? '');

            $refusal = match (true) {
                ! in_array($case, $cases, true) => "The transition {$transition} starts from {$case}, which is not one of the cases.",
                in_array($case, $named, true) => "The case {$case} is given two transitions; list everywhere it goes in one.",
                array_diff($next, $cases) !== [] => "The transition {$transition} goes to ".implode(', ', array_diff($next, $cases)).', which is not one of the cases.',
                in_array($case, $next, true) => "The transition {$transition} lets {$case} become itself; staying put is no change.",
                count($next) !== count(array_unique($next)) => "The transition {$transition} names a case twice.",
                default => null,
            };

            if ($refusal !== null) {
                return $refusal;
            }

            $named[] = $case;
        }

        return null;
    }

    /**
     * The cases each `--transition` lets a case become, by the case it starts from.
     *
     * @return array<string, list<string>>
     */
    private function transitions(): array
    {
        $transitions = [];

        foreach ((array) $this->option('transition') as $transition) {
            [$case, $next] = array_pad(explode(':', (string) $transition, 2), 2, '');
            $transitions[trim($case)] = $this->targetsOf($next);
        }

        return $transitions;
    }

    /**
     * @return list<string>
     */
    private function targetsOf(string $next): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $next)), fn (string $target): bool => $target !== ''));
    }

    protected function domainSubject(): string
    {
        return 'enum';
    }

    protected function ownedByAggregate(): bool
    {
        return true;
    }

    /**
     * An enum belongs to an aggregate or to the shared kernel, never to a bare context.
     */
    private function domainRefusal(string $domain): ?string
    {
        $segments = explode('\\', $domain);

        return match (true) {
            $segments === [self::SHARED_KERNEL] => null,
            count($segments) === 2 && $segments[0] !== self::SHARED_KERNEL => null,
            default => 'An enum belongs to an aggregate or the shared kernel: pass --domain={Context}/{Aggregate} or --domain=Shared.',
        };
    }

    /**
     * What is wrong with the cases given, or null. A string backed case with no value takes its
     * name in snake case; an int backed case needs its value, and a pure case takes none.
     */
    private function casesRefusal(): ?string
    {
        foreach ($this->rawCases() as $case => $value) {
            $refusal = match (true) {
                preg_match('/^[A-Z][A-Za-z0-9]*$/', $case) !== 1 => "The case {$case} is not TitleCase.",
                $this->backing() === null && $value !== null => "The case {$case} has a value, but the enum is not backed: pass --string or --int.",
                $this->backing() === 'string' && $value === '' => "The case {$case} has an empty value.",
                $this->backing() === 'int' && ($value === null || preg_match('/^-?\d+$/', $value) !== 1) => "The case {$case} needs an integer value, as {$case}=1.",
                default => null,
            };

            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|int|null>
     */
    private function cases(): array
    {
        $cases = [];

        foreach ($this->rawCases() as $case => $value) {
            $cases[$case] = match ($this->backing()) {
                'string' => $value ?? Str::snake($case),
                'int' => (int) $value,
                default => null,
            };
        }

        return $cases;
    }

    /**
     * Each `--case` as its name and the value written after `=`, or null.
     *
     * @return array<string, string|null>
     */
    private function rawCases(): array
    {
        $cases = [];

        foreach ((array) $this->option('case') as $case) {
            $parts = explode('=', (string) $case, 2);
            $cases[trim($parts[0])] = isset($parts[1]) ? $parts[1] : null;
        }

        return $cases;
    }

    private function backing(): ?string
    {
        return match (true) {
            (bool) $this->option('string') => 'string',
            (bool) $this->option('int') => 'int',
            default => null,
        };
    }
}
