<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Resolve the domain a generated class belongs to, asking for it when --domain is not given.
 */
trait ResolvesDomain
{
    /**
     * The sentinel answer standing for a domain that does not exist yet.
     */
    protected const string ANOTHER_DOMAIN = '__another__';

    /**
     * The shared kernel under app/Domain. It holds the base classes every domain builds on,
     * so it is never a domain a generated class can belong to.
     */
    protected const string SHARED_KERNEL = 'Shared';

    protected ?string $resolvedDomain = null;

    /**
     * What the generated class belongs to, worked into the prompt and the error.
     */
    abstract protected function domainSubject(): string;

    /**
     * A command instance is reused within a process, so the answer must not leak between runs.
     */
    protected function forgetResolvedDomain(): void
    {
        $this->resolvedDomain = null;
    }

    /**
     * The domain that owns the generated class, or null when it cannot be asked for.
     */
    protected function resolveDomain(): ?string
    {
        if ($this->resolvedDomain !== null) {
            return $this->resolvedDomain;
        }

        $domain = $this->option('domain');

        if (is_string($domain) && filled($domain)) {
            return $this->resolvedDomain = $this->normalizeDomain($domain);
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        return $this->resolvedDomain = $this->normalizeDomain($this->askForDomain());
    }

    protected function reportMissingDomain(): void
    {
        $this->components->error(sprintf(
            'Could not resolve a domain. Pass --domain to name the domain that owns the %s.',
            $this->domainSubject(),
        ));
    }

    /**
     * Offer the domains that already exist, leaving room for one that does not.
     */
    protected function askForDomain(): string
    {
        $label = sprintf('Which domain owns this %s?', $this->domainSubject());

        $available = $this->availableDomains();

        $answer = $available === [] ? self::ANOTHER_DOMAIN : select(
            label: $label,
            options: [
                ...array_combine($available, $available),
                self::ANOTHER_DOMAIN => 'Another domain',
            ],
        );

        return $answer === self::ANOTHER_DOMAIN
            ? text(
                label: $label,
                placeholder: $this->ownedByAggregate() ? 'LotteryDefinition/LotteryType' : 'LotteryDefinition',
                required: true,
            )
            : (string) $answer;
    }

    protected function normalizeDomain(string $domain): string
    {
        $segments = array_map(
            fn (string $segment) => Str::studly($segment),
            preg_split('#[/\\\\]#', trim($domain), flags: PREG_SPLIT_NO_EMPTY) ?: [],
        );

        return implode('\\', $segments);
    }

    /**
     * Whether the generated class lives inside an aggregate rather than at the context level.
     *
     * app/Domain is laid out as {Context}/{Aggregate}/, so an entity or a repository needs
     * the deeper answer. app/Application is not split by aggregate, so a use case does not.
     */
    protected function ownedByAggregate(): bool
    {
        return false;
    }

    /**
     * The domains already present under app/Application and app/Domain, minus the shared kernel.
     *
     * Reported as {Context}/{Aggregate} when the generated class belongs to an aggregate,
     * and as a bare {Context} otherwise.
     *
     * @return list<string>
     */
    protected function availableDomains(): array
    {
        $contexts = array_values(array_diff(array_unique(array_map(
            fn (string $directory) => basename($directory),
            [...$this->directoriesIn(app_path('Application')), ...$this->directoriesIn(app_path('Domain'))],
        )), [self::SHARED_KERNEL]));

        $names = $this->ownedByAggregate()
            ? $this->aggregatesIn($contexts)
            : $contexts;

        sort($names);

        return $names;
    }

    /**
     * The {Context}/{Aggregate} folders that already hold a root entity.
     *
     * A context with none yet is left out rather than offered at the wrong depth; the
     * "Another domain" escape covers naming an aggregate that does not exist.
     *
     * @param  list<string>  $contexts
     * @return list<string>
     */
    protected function aggregatesIn(array $contexts): array
    {
        $aggregates = [];

        foreach ($contexts as $context) {
            foreach ($this->directoriesIn(app_path('Domain/'.$context)) as $directory) {
                if ($this->files->glob($directory.'/*Entity.php') === []) {
                    continue;
                }

                $aggregates[] = $context.'/'.basename($directory);
            }
        }

        return $aggregates;
    }

    /**
     * @return list<string>
     */
    protected function directoriesIn(string $path): array
    {
        return $this->files->isDirectory($path) ? array_values($this->files->directories($path)) : [];
    }
}
