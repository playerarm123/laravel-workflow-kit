<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

/**
 * Changes a context's manifest one piece at a time, for the kit's structure screen
 * (structure.md). Every change is checked against the manifest's shape and against the design
 * rules a manifest alone can show, then written in canonical form, or refused with what is wrong,
 * keyed by the form field that holds it.
 *
 * A piece the code already has is never changed or removed here: the manifest would then disagree
 * with the code at once. Changing what is built is a replacement, a step of its own.
 */
final class StructureEditor
{
    private const string NAME = '/^[A-Z][A-Za-z0-9]*$/';

    private const string METHOD = '/^[a-z][A-Za-z0-9]*$/';

    private const array COMMAND_RETURNS = ['void', 'string', 'int'];

    /**
     * The sections that hold an aggregate's vocabulary, the only ones the shared kernel has.
     */
    private const array VOCABULARY = ['enums', 'valueObjects'];

    public function __construct(
        private readonly StructureFiles $files,
        private readonly StructureReader $reader,
    ) {}

    /**
     * Writes the empty manifest of a new context.
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function createContext(string $name): array
    {
        $errors = match (true) {
            preg_match(self::NAME, $name) !== 1 => ['A context name is StudlyCase.'],
            in_array($name, StructureReader::KIT_CONTEXTS, true) => ["{$name} is one of the kit's own contexts."],
            $this->files->exists($name) => ["{$name} already has a manifest."],
            default => [],
        };

        if ($errors !== []) {
            return ['name' => $errors];
        }

        $this->files->write(['context' => $name, ...array_fill_keys(array_keys(StructureFiles::SECTIONS), [])]);

        return [];
    }

    /**
     * Adds a piece, or changes or renames the one named `$previous`.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function savePiece(string $context, string $version, string $section, ?string $previous, string $name, array $entry): array
    {
        $manifest = $this->manifest($context);
        $refused = $this->refusal($context, $manifest, $version, $section) ?? ($previous === null ? null : $this->lockedRefusal($context, $manifest, $section, $previous));

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        $errors = [];
        $built = $this->builtNames($context, $section);

        if (preg_match(self::NAME, $name) !== 1) {
            return ['name' => ['A name is StudlyCase.']];
        }

        if ($name !== $previous && array_key_exists($name, $manifest[$section])) {
            $errors['name'][] = "The manifest already has {$name}.";
        } elseif ($name !== $previous && in_array($name, $built, true)) {
            $errors['name'][] = "The code already has {$name}. Run `php artisan kit:import --context={$context} --force` to read it into the manifest.";
        }

        $entry = $this->normalised($section, $entry);

        if (array_key_exists('replaces', $entry)) {
            $entry['replaces'] = $previous === null ? null : ($manifest[$section][$previous]['replaces'] ?? null);
        }

        $errors = $this->merge($errors, $this->shapeErrors($context, $section, $name, $entry));

        if (! isset($errors['name']) && $previous !== null && $previous !== $name && $this->usersOf($context, $section, $previous) !== []) {
            $errors['name'][] = "{$previous} is used by ".implode(', ', $this->usersOf($context, $section, $previous)).', so it keeps its name.';
        }

        if ($errors === []) {
            $errors = $this->designErrors($context, $manifest, $section, $previous ?? $name, $entry);
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($previous !== null) {
            unset($manifest[$section][$previous]);
        }

        $manifest[$section][$name] = $entry;
        $this->files->write($manifest);

        return [];
    }

    /**
     * Removes a piece the code does not have yet.
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was removed
     */
    public function removePiece(string $context, string $version, string $section, string $name): array
    {
        $manifest = $this->manifest($context);
        $refused = $this->refusal($context, $manifest, $version, $section) ?? $this->lockedRefusal($context, $manifest, $section, $name);

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        if ($this->usersOf($context, $section, $name) !== []) {
            return ['name' => ["{$name} is used by ".implode(', ', $this->usersOf($context, $section, $name)).'.']];
        }

        $replaces = $section === 'useCases' ? ($manifest[$section][$name]['replaces'] ?? null) : null;

        unset($manifest[$section][$name]);
        $this->files->write($manifest);

        if (is_string($replaces)) {
            $this->repointResources("{$context}/{$name}", "{$context}/{$replaces}");
        }

        return [];
    }

    /**
     * Adds a method to an entity, or changes or renames the one named `$previous`. Its group follows
     * from its name: an `assert…` method is an assertion, any other a behaviour. A method the code
     * already has stays as it is, though its entity is built.
     *
     * @param  array<array-key, mixed>  $params  each parameter's name to its type, in order
     * @param  array<array-key, mixed>  $throws  the exceptions it throws
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function saveMethod(string $context, string $version, string $entity, ?string $previous, string $name, array $params, array $throws): array
    {
        $manifest = $this->manifest($context);
        $refused = $this->refusal($context, $manifest, $version, 'entities', byMethod: true)
            ?? $this->entityRefusal($context, $manifest, $entity)
            ?? ($previous === null ? null : $this->methodLockedRefusal($context, $manifest, $entity, $previous));

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        if (preg_match(self::METHOD, $name) !== 1) {
            return ['name' => ['A method name is camelCase.']];
        }

        $aggregate = (string) $this->holderOf($manifest, $entity);
        /** @var array{aggregate: string, behaviours: array<string, mixed>, assertions: array<string, mixed>} $entry */
        $entry = $manifest['entities'][$entity] ?? ['aggregate' => $aggregate, 'behaviours' => [], 'assertions' => []];
        $errors = [];

        if ($name !== $previous && (isset($entry['behaviours'][$name]) || isset($entry['assertions'][$name]))) {
            $errors['name'][] = "The manifest already has {$entity}::{$name}.";
        } elseif ($name !== $previous && in_array($name, $this->builtMethods($context, $entity), true)) {
            $errors['name'][] = "The code already has {$entity}::{$name}. Run `php artisan kit:import --context={$context} --force` to read it into the manifest.";
        }

        $errors = $this->merge($errors, $this->paramErrors($context, $aggregate, $params));
        $errors = $this->merge($errors, $this->throwErrors($context, $aggregate, $throws));

        if ($errors !== []) {
            return $errors;
        }

        /** @var list<string> $throws */
        $throws = array_values(array_unique($throws));
        sort($throws);

        if ($previous !== null) {
            unset($entry['behaviours'][$previous], $entry['assertions'][$previous]);
        }

        $entry[StructureReader::isAssertion($name) ? 'assertions' : 'behaviours'][$name] = ['params' => $params, 'throws' => $throws];
        $manifest['entities'][$entity] = $entry;

        return $this->writeChecked($context, $manifest);
    }

    /**
     * Removes a method the code does not have yet, and the entity's entry with its last method.
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was removed
     */
    public function removeMethod(string $context, string $version, string $entity, string $name): array
    {
        $manifest = $this->manifest($context);
        $refused = $this->refusal($context, $manifest, $version, 'entities', byMethod: true)
            ?? $this->entityRefusal($context, $manifest, $entity)
            ?? $this->methodLockedRefusal($context, $manifest, $entity, $name);

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        /** @var array{aggregate: string, behaviours: array<string, mixed>, assertions: array<string, mixed>} $entry */
        $entry = $manifest['entities'][$entity];
        unset($entry['behaviours'][$name], $entry['assertions'][$name]);

        if ($entry['behaviours'] === [] && $entry['assertions'] === []) {
            unset($manifest['entities'][$entity]);
        } else {
            $manifest['entities'][$entity] = $entry;
        }

        return $this->writeChecked($context, $manifest);
    }

    /**
     * Starts replacing a built piece: a port's adapter with a new one, or a use case or a domain
     * service with a new one beside it, which every HTTP resource or caller then uses instead.
     * kit:apply builds the new piece and swaps it in; kit:retire removes the old one once the
     * tests pass.
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function replace(string $context, string $version, string $section, string $name, string $replacement): array
    {
        $manifest = $this->manifest($context);
        $refused = $this->refusal($context, $manifest, $version, $section);

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        $entry = $manifest[$section][$name] ?? null;

        $why = match (true) {
            ! in_array($section, ['ports', 'useCases', 'services'], true) => ['section' => ['Only an adapter, a use case or a domain service is replaced this way so far.']],
            ! is_array($entry) => ['name' => ["The manifest has no {$name}."]],
            ! in_array($name, $this->builtNames($context, $section), true) => ['name' => ["The code does not have {$name} yet, so change it instead of replacing it."]],
            is_string($entry['replaces'] ?? null) => ['name' => ["{$name} is in the middle of a replacement already."]],
            $this->replacerOf($manifest, $section, $name) !== null => ['name' => ["{$name} is being replaced by ".$this->replacerOf($manifest, $section, $name).' already.']],
            default => null,
        };

        if ($why !== null) {
            return $why;
        }

        return match ($section) {
            'ports' => $this->replaceAdapter($context, $manifest, $name, $entry, $replacement),
            'services' => $this->replaceService($context, $manifest, $name, $entry, $replacement),
            default => $this->replaceUseCase($context, $manifest, $name, $entry, $replacement),
        };
    }

    /**
     * Stops a replacement that has not been retired: a port goes back to its old adapter, and a new
     * use case or domain service the code does not have yet goes, with every resource calling the
     * old use case again.
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function cancelReplacement(string $context, string $version, string $section, string $name): array
    {
        $manifest = $this->manifest($context);
        $refused = $this->refusal($context, $manifest, $version, $section);

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        $replaces = $manifest[$section][$name]['replaces'] ?? null;

        if (! is_string($replaces)) {
            return ['name' => ["{$name} replaces nothing."]];
        }

        if ($section !== 'ports') {
            return $this->removePiece($context, $version, $section, $name);
        }

        $manifest[$section][$name]['adapter'] = $replaces;
        $manifest[$section][$name]['replaces'] = null;
        $this->files->write($manifest);

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function replaceAdapter(string $context, array $manifest, string $port, array $entry, string $adapter): array
    {
        if (! is_string($entry['adapter'])) {
            return ['name' => ["{$port} has no adapter to replace."]];
        }

        if ($adapter === $entry['adapter']) {
            return ['replacement' => ['The new adapter needs a name of its own.']];
        }

        $errors = $this->designErrors($context, $manifest, 'ports', $port, [...$entry, 'adapter' => $adapter]);

        if ($errors !== []) {
            return ['replacement' => $errors['adapter'] ?? ['The adapter does not fit.']];
        }

        $manifest['ports'][$port] = [...$entry, 'adapter' => $adapter, 'replaces' => $entry['adapter']];
        $this->files->write($manifest);

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function replaceUseCase(string $context, array $manifest, string $old, array $entry, string $new): array
    {
        $list = $entry['query'] === true;
        $errors = match (true) {
            preg_match(self::NAME, $new) !== 1 => ['A name is StudlyCase.'],
            $list && (! str_starts_with($new, 'List') || strlen($new) <= 4) => ['A list use case is named List{Name} (list-queries.md).'],
            $list && StructurePlanner::listNames($new)['item'] === StructurePlanner::listNames($old)['item'] => [sprintf(
                '%s lists %s as %s does, so its Row, Sort and TypeScript types would take the old names. Name what it lists another way.',
                $new,
                StructurePlanner::listNames($old)['item'],
                $old,
            )],
            array_key_exists($new, $manifest['useCases']) || in_array($new, $this->builtNames($context, 'useCases'), true) => ["{$context} already has {$new}."],
            default => [],
        };

        if ($errors !== []) {
            return ['replacement' => $errors];
        }

        $manifest['useCases'][$new] = [...$entry, 'replaces' => $old];
        $this->files->write($manifest);
        $this->repointResources("{$context}/{$old}", "{$context}/{$new}");

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function replaceService(string $context, array $manifest, string $old, array $entry, string $new): array
    {
        $errors = match (true) {
            preg_match(self::NAME, $new) !== 1 => ['A name is StudlyCase.'],
            array_key_exists($new, $manifest['services']) || in_array($new, $this->builtNames($context, 'services'), true) => ["{$context} already has {$new}."],
            default => [],
        };

        if ($errors !== []) {
            return ['replacement' => $errors];
        }

        $manifest['services'][$new] = [...$entry, 'replaces' => $old];
        $this->files->write($manifest);

        return [];
    }

    /**
     * The piece of a section that is replacing the named one, if any.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function replacerOf(array $manifest, string $section, string $name): ?string
    {
        /** @var array<string, array<string, mixed>> $entries */
        $entries = $manifest[$section];

        foreach ($entries as $other => $entry) {
            $replaces = $entry['replaces'] ?? null;

            if ($replaces === $name || ($section === 'ports' && $other === $name && is_string($replaces))) {
                return $other;
            }
        }

        return null;
    }

    /**
     * Points every HTTP resource that calls one use case at another.
     */
    private function repointResources(string $from, string $to): void
    {
        foreach ($this->files->resources() as $resource) {
            $manifest = $this->files->readResource($resource);

            if (! is_array($manifest) || $this->files->resourceProblems($resource, $manifest) !== []) {
                continue;
            }

            $swap = fn (array $useCases): array => array_map(fn (string $useCase): string => $useCase === $from ? $to : $useCase, $useCases);
            /** @var array<string, list<string>> $controller */
            $controller = $manifest['controller'];
            /** @var array<string, array{row: bool, bulk: bool, useCases: list<string>}> $actions */
            $actions = $manifest['actions'];
            $repointed = [
                ...$manifest,
                'controller' => array_map($swap, $controller),
                'actions' => array_map(fn (array $action): array => [...$action, 'useCases' => $swap($action['useCases'])], $actions),
            ];

            if ($repointed !== $manifest) {
                $this->files->writeResource($repointed);
            }
        }
    }

    /**
     * The manifest of a context when it can be read and has the right shape, or null.
     *
     * @return array<string, mixed>|null
     */
    private function manifest(string $context): ?array
    {
        if (! $this->files->exists($context)) {
            return null;
        }

        $manifest = $this->files->read($context);

        return is_array($manifest) && $this->files->problems($context, $manifest) === [] ? $manifest : null;
    }

    /**
     * Why no change to this manifest can be made at all, or null. An entity's methods change one
     * at a time (`$byMethod`), never as a whole entry.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, list<string>>|null
     */
    private function refusal(string $context, ?array $manifest, string $version, string $section, bool $byMethod = false): ?array
    {
        return match (true) {
            $manifest === null => ['context' => ["{$context} has no manifest the screen can change. Fix it by hand first."]],
            $version !== $this->files->version($context) => ['version' => ['The manifest changed since this page loaded. Reload it, then make the change again.']],
            ! array_key_exists($section, StructureFiles::SECTIONS) => ['section' => ["{$section} is not a section of a manifest."]],
            $section === 'entities' && ! $byMethod => ['section' => ["An entity's methods change one at a time."]],
            default => null,
        };
    }

    /**
     * Why the named piece may not change: it is not in the manifest, or the code already has it.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, list<string>>|null
     */
    private function lockedRefusal(string $context, ?array $manifest, string $section, string $name): ?array
    {
        if ($manifest === null || ! is_array($manifest[$section]) || ! array_key_exists($name, $manifest[$section])) {
            return ['name' => ["The manifest has no {$name}."]];
        }

        if (in_array($name, $this->builtNames($context, $section), true)) {
            return ['name' => ["The code already has {$name}, so the screen leaves it alone. Change what is built by replacing it."]];
        }

        return null;
    }

    /**
     * Why an entity's methods cannot change here: it is neither a root nor a child of an aggregate
     * the manifest lists.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, list<string>>|null
     */
    private function entityRefusal(string $context, ?array $manifest, string $entity): ?array
    {
        return match (true) {
            $context === StructureFiles::SHARED => ['entity' => ['The shared kernel has no entities.']],
            $manifest === null || $this->holderOf($manifest, $entity) === null => ['entity' => ["{$context} has no aggregate whose root or child is {$entity}."]],
            default => null,
        };
    }

    /**
     * Why the named method may not change: the manifest does not list it, or the code already has it.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, list<string>>|null
     */
    private function methodLockedRefusal(string $context, ?array $manifest, string $entity, string $method): ?array
    {
        $entry = $manifest['entities'][$entity] ?? null;

        if (! is_array($entry) || ! (isset($entry['behaviours'][$method]) || isset($entry['assertions'][$method]))) {
            return ['name' => ["The manifest has no {$entity}::{$method}."]];
        }

        if (in_array($method, $this->builtMethods($context, $entity), true)) {
            return ['name' => ["The code already has {$entity}::{$method}, so the screen leaves it alone."]];
        }

        return null;
    }

    /**
     * The aggregate whose root or child the entity is, or null.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function holderOf(array $manifest, string $entity): ?string
    {
        /** @var array<string, array{children: list<string>, repository: bool}> $aggregates */
        $aggregates = $manifest['aggregates'];

        foreach ($aggregates as $aggregate => $entry) {
            if ($aggregate === $entity || in_array($entity, $entry['children'], true)) {
                return $aggregate;
            }
        }

        return null;
    }

    /**
     * The methods of an entity the code already has.
     *
     * @return list<string>
     */
    private function builtMethods(string $context, string $entity): array
    {
        $entry = $this->reader->read($context)['entities'][$entity] ?? null;

        return $entry === null ? [] : array_map(strval(...), array_keys([...$entry['behaviours'], ...$entry['assertions']]));
    }

    /**
     * A method's parameters: camelCase names, each with a type a field could hold, and a variadic
     * one (`...Type`) only last.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<string, list<string>>
     */
    private function paramErrors(string $context, string $aggregate, array $params): array
    {
        $errors = [];
        $last = array_key_last($params);

        foreach ($params as $param => $type) {
            if (preg_match(self::METHOD, (string) $param) !== 1) {
                $errors['params'][] = "A parameter is camelCase: {$param} is not.";
            }

            if (! is_string($type) || trim($type) === '') {
                $errors['params'][] = "{$param} has no type.";

                continue;
            }

            if (str_starts_with($type, '...') && $param !== $last) {
                $errors['params'][] = "Only the last parameter is variadic, so {$param} cannot be.";
            }

            foreach (preg_split('/[|&]/', ltrim((string) preg_replace('/^\.\.\./', '', $type), '?')) ?: [] as $part) {
                if (! $this->isKnownType($context, $aggregate, $part)) {
                    $errors['params'][] = "The type {$part} of {$param} is no builtin, and no enum, value object or entity a manifest lists.";
                }
            }
        }

        return $errors;
    }

    /**
     * A method's exceptions. One of the entity's own aggregate is named bare, and kit:apply builds it
     * when it is missing. One of the shared kernel or of another aggregate is named `Shared/Name` or
     * `Context/Aggregate/Name`, and must exist already, because nothing builds it.
     *
     * @param  array<array-key, mixed>  $throws
     * @return array<string, list<string>>
     */
    private function throwErrors(string $context, string $aggregate, array $throws): array
    {
        $errors = [];

        if (! array_is_list($throws)) {
            return ['throws' => ['The exceptions are a list.']];
        }

        foreach ($throws as $exception) {
            if (! is_string($exception) || preg_match('#^([A-Z][A-Za-z0-9]*/){0,2}[A-Z][A-Za-z0-9]*Exception$#', $exception) !== 1) {
                $errors['throws'][] = 'An exception is a StudlyCase name ending with Exception, bare or as Shared/Name or Context/Aggregate/Name: '.(is_string($exception) ? $exception : json_encode($exception)).' is not.';
            } elseif (str_contains($exception, '/') && $this->reader->exceptionClass($exception, $context, $aggregate) === null && ! $this->designsException($exception)) {
                $errors['throws'][] = "No manifest designs {$exception} and the code has none. Add it as an exception of its own context first.";
            }
        }

        return $errors;
    }

    /**
     * Whether a manifest designs the exception a method names from elsewhere: `Shared/Name` an invalid
     * value of the shared kernel, `Context/Aggregate/Name` a refusal or invalid value of that aggregate.
     */
    private function designsException(string $exception): bool
    {
        $segments = explode('/', $exception);
        $name = (string) array_pop($segments);
        $owner = $segments[0];
        $entry = $this->manifest($owner)['exceptions'][$name] ?? null;

        return is_array($entry) && match (count($segments)) {
            1 => $owner === StructureFiles::SHARED,
            2 => $entry['kind'] !== 'application' && $entry['aggregate'] === $segments[1],
            default => false,
        };
    }

    /**
     * Writes the manifest when it keeps its canonical shape, or names what would break it.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, list<string>>
     */
    private function writeChecked(string $context, array $manifest): array
    {
        $problems = $this->files->problems($context, $manifest);

        if ($problems !== []) {
            return ['name' => array_map(fn (string $problem): string => ucfirst((string) preg_replace('/^[^:]+: /', '', $problem)).'.', $problems)];
        }

        $this->files->write($manifest);

        return [];
    }

    /**
     * The names of a section's pieces the code already has.
     *
     * @return list<string>
     */
    private function builtNames(string $context, string $section): array
    {
        $entries = $this->reader->read($context)[$section] ?? [];

        return is_array($entries) ? array_map(strval(...), array_keys($entries)) : [];
    }

    /**
     * The entry with every key of its section, each missing one at its empty value.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalised(string $section, array $entry): array
    {
        $normalised = [];

        foreach (StructureFiles::SECTIONS[$section] as $key => $kind) {
            $value = array_key_exists($key, $entry) ? $entry[$key] : match (true) {
                $kind === 'list', StructureFiles::isMap($kind) => [],
                $kind === 'bool' => false,
                default => null,
            };

            if ($kind === 'string|null' && $value === '') {
                $value = null;
            }

            if ($kind === 'list' && is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_string($item)) === []) {
                $value = array_values(array_unique($value));
                sort($value);
            }

            $normalised[$key] = $value;
        }

        $cases = $normalised['cases'] ?? null;
        $backing = $normalised['backing'] ?? null;

        if ($section === 'enums' && is_array($cases)) {
            $normalised['cases'] = array_map(fn (mixed $value): mixed => match (true) {
                $backing === null => null,
                $backing === 'int' && is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
                default => $value,
            }, $cases);
        }

        $transitions = $normalised['transitions'] ?? null;

        if ($section === 'enums' && is_array($cases) && is_array($transitions) && ! array_is_list($transitions)) {
            $normalised['transitions'] = StructureFiles::orderedTransitions([...array_fill_keys(array_map(strval(...), array_keys($cases)), []), ...$transitions], $cases);
        }

        return $normalised;
    }

    /**
     * The manifest's own shape checks, run on this one entry, each put under the field it names.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function shapeErrors(string $context, string $section, string $name, array $entry): array
    {
        $document = ['context' => $context, ...array_fill_keys(array_keys(StructureFiles::SECTIONS), [])];
        $document[$section] = [$name => $entry];
        $errors = [];

        foreach ($this->files->problems($context, $document) as $problem) {
            $field = preg_match('/"(\w+)"/', $problem, $match) === 1 ? $match[1] : 'name';
            $errors[$field][] = ucfirst((string) preg_replace('/^[^:]+: /', '', $problem)).'.';
        }

        return $errors;
    }

    /**
     * The design rules a manifest alone can show (layers.md, handlers.md, list-queries.md). Each one
     * holds for every piece the generators build, so the screen never refuses what the rules allow.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function designErrors(string $context, array $manifest, string $section, string $name, array $entry): array
    {
        /** @var array<string, array{children: list<string>, repository: bool}> $aggregates */
        $aggregates = $manifest['aggregates'];
        $errors = [];

        if ($section === 'aggregates') {
            /** @var list<string> $children */
            $children = $entry['children'];

            foreach ($children as $child) {
                if (preg_match(self::NAME, $child) !== 1 || $child === $name) {
                    $errors['children'][] = "A child is a StudlyCase name other than the root's: {$child} is not.";
                }
            }

            /** @var array<string, array{aggregate: string}> $entities */
            $entities = $manifest['entities'];

            foreach ($entities as $entity => $methods) {
                if ($methods['aggregate'] === $name && $entity !== $name && ! in_array($entity, $children, true)) {
                    $errors['children'][] = "{$entity} lists its methods under entities, so it stays a child.";
                }
            }

            if ($entry['repository'] === false && $this->referencesTo($context, $name, repositoriesOnly: true) !== []) {
                $errors['repository'][] = "{$name}Repository is injected by ".implode(', ', $this->referencesTo($context, $name, repositoriesOnly: true)).'.';
            }
        }

        if ($section === 'services') {
            $creates = $entry['creates'];

            if ($entry['shape'] === 'creates' && ! (is_string($creates) && isset($aggregates[$creates]))) {
                $errors['creates'][] = 'A creates-shaped service builds an aggregate of its own context.';
            } elseif ($entry['shape'] !== 'creates' && $creates !== null) {
                $errors['creates'][] = 'Only a creates-shaped service names the aggregate it builds.';
            }

            /** @var list<string> $repositories */
            $repositories = $entry['repositories'];

            foreach ($repositories as $repository) {
                if (str_contains($repository, '/')) {
                    $errors['repositories'][] = "A domain service touches no other context (layers.md), so it cannot inject {$repository}.";
                } elseif (! ($aggregates[$repository]['repository'] ?? false)) {
                    $errors['repositories'][] = "{$context} has no {$repository}Repository.";
                }
            }
        }

        if ($section === 'ports' && is_string($entry['adapter']) && preg_match('#^Infra(/[A-Z][A-Za-z0-9]*)+/[A-Z][A-Za-z0-9]*'.preg_quote($name, '#').'$#', $entry['adapter']) !== 1) {
            $errors['adapter'][] = "An adapter is Infra/{Folder}/{Prefix}{$name}, the class make:port --adapter writes.";
        }

        if ($section === 'useCases') {
            $errors = $this->merge($errors, $this->useCaseErrors($context, $name, $entry));
        }

        if ($context === StructureFiles::SHARED && ! in_array($section, [...self::VOCABULARY, 'exceptions'], true)) {
            $errors['section'][] = 'The shared kernel lists only enums, value objects and invalid values.';
        }

        if ($section === 'exceptions' && $context !== StructureFiles::SHARED) {
            if ($entry['kind'] !== 'application' && ! (is_string($entry['aggregate']) && isset($aggregates[$entry['aggregate']]))) {
                $errors['aggregate'][] = "{$context} has no aggregate ".(is_string($entry['aggregate']) ? $entry['aggregate'] : '').' to hold it.';
            }

            if (is_string($entry['useCase']) && ! isset($manifest['useCases'][$entry['useCase']])) {
                $errors['useCase'][] = "{$context} has no use case {$entry['useCase']}.";
            }
        }

        if (in_array($section, self::VOCABULARY, true)) {
            $errors = $this->merge($errors, $this->vocabularyErrors($context, $manifest, $section, $name, $entry));
        }

        return $errors;
    }

    /**
     * An enum's cases and, for a status, its name and its moves, and a value object's fields, checked against the manifests rather than the
     * code, so a value object can name an enum that is only designed so far.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function vocabularyErrors(string $context, array $manifest, string $section, string $name, array $entry): array
    {
        $errors = [];
        $aggregate = $entry['aggregate'];

        if (is_string($aggregate) && ! isset($manifest['aggregates'][$aggregate])) {
            $errors['aggregate'][] = "{$context} has no aggregate {$aggregate}.";
        }

        if ($section === 'enums') {
            /** @var array<string, string|int|null> $cases */
            $cases = $entry['cases'];

            foreach (array_keys($cases) as $case) {
                if (preg_match(self::NAME, (string) $case) !== 1) {
                    $errors['cases'][] = "A case is TitleCase: {$case} is not.";
                }
            }

            $values = array_filter($cases, fn (mixed $value): bool => $value !== null);

            if (count($values) !== count(array_unique($values))) {
                $errors['cases'][] = 'Two cases share a value.';
            }

            $transitions = $entry['transitions'];

            if (is_array($transitions) && ! str_ends_with($name, 'Status')) {
                $errors['transitions'][] = 'An enum that declares where each case may go is a status, named *Status (states.md).';
            }

            if (is_array($transitions) && array_filter($transitions) === []) {
                $errors['transitions'][] = 'A status lets at least one case become another; an enum whose cases never change declares no transitions.';
            }

            return $errors;
        }

        /** @var array<string, string> $fields */
        $fields = $entry['fields'];

        foreach ($fields as $field => $type) {
            if (preg_match('/^[a-z][A-Za-z0-9]*$/', (string) $field) !== 1) {
                $errors['fields'][] = "A field is camelCase: {$field} is not.";
            }

            foreach (preg_split('/[|&]/', ltrim($type, '?')) ?: [] as $part) {
                if (! $this->isKnownType($context, is_string($aggregate) ? $aggregate : null, $part)) {
                    $errors['fields'][] = "The type {$part} of {$field} is no builtin, and no enum, value object or entity a manifest lists.";
                }
            }
        }

        return $errors;
    }

    /**
     * Whether a field's type names a builtin, a class the manifests design, or a class the code
     * already has (the kit's `Shared/Money`, `DateTimeImmutable`): a bare name in the same aggregate,
     * `Shared/Name`, or `Context/Aggregate/Name`.
     */
    private function isKnownType(string $context, ?string $aggregate, string $type): bool
    {
        if (in_array($type, StructureReader::BUILTIN_TYPES, true)) {
            return true;
        }

        $segments = explode('/', $type);
        $name = (string) array_pop($segments);

        [$owner, $holder] = match (count($segments)) {
            0 => [$context, $aggregate],
            1 => [$segments[0], null],
            2 => [$segments[0], $segments[1]],
            default => [null, null],
        };

        if ($owner === null || (count($segments) === 1 && $owner !== StructureFiles::SHARED)) {
            return false;
        }

        $manifest = $this->manifest($owner);

        if ($manifest !== null) {
            foreach (self::VOCABULARY as $section) {
                $entry = $manifest[$section][$name] ?? null;

                if (is_array($entry) && $entry['aggregate'] === $holder) {
                    return true;
                }
            }

            $entity = str_ends_with($name, 'Entity') ? substr($name, 0, -strlen('Entity')) : null;
            $root = $manifest['aggregates'][$holder ?? ''] ?? null;

            if (is_array($root) && ($entity === $holder || in_array($entity, $root['children'], true))) {
                return true;
            }
        }

        return $this->reader->vocabularyClass($type, $context, $aggregate) !== null;
    }

    /**
     * Who still names a piece, so it keeps its name and stays: the services and use cases that
     * inject an aggregate, the enums, value objects and entity methods it holds, and the value objects whose fields
     * name an enum or a value object.
     *
     * @return list<string>
     */
    private function usersOf(string $context, string $section, string $name): array
    {
        return match ($section) {
            'aggregates' => $this->referencesTo($context, $name),
            'enums', 'valueObjects' => $this->fieldUsersOf($context, $name),
            'exceptions' => $this->throwersOf($context, $name),
            'useCases' => $this->refusalsOf($context, $name),
            default => [],
        };
    }

    /**
     * The entity methods, in any context, whose `throws` name this exception: bare inside its own
     * aggregate, `Shared/Name` from the shared kernel, `Context/Aggregate/Name` anywhere else.
     *
     * @return list<string>
     */
    private function throwersOf(string $context, string $name): array
    {
        $holder = $this->manifest($context)['exceptions'][$name]['aggregate'] ?? null;
        $names = $context === StructureFiles::SHARED ? [StructureFiles::SHARED."/{$name}"] : ["{$context}/{$holder}/{$name}"];
        $users = [];

        foreach ($this->files->contexts() as $owner) {
            /** @var array<string, array{aggregate: string, behaviours: array<string, array{throws: list<string>}>, assertions: array<string, array{throws: list<string>}>}> $entities */
            $entities = $this->manifest($owner)['entities'] ?? [];

            foreach ($entities as $entity => $entry) {
                $bare = $owner === $context && $entry['aggregate'] === $holder ? [$name] : [];

                foreach ([...$entry['behaviours'], ...$entry['assertions']] as $method => $definition) {
                    if (array_intersect($definition['throws'], [...$names, ...$bare]) !== []) {
                        $users[] = "{$owner}/{$entity}::{$method}";
                    }
                }
            }
        }

        return array_values(array_unique($users));
    }

    /**
     * The use case refusals this context designs in a use case's folder.
     *
     * @return list<string>
     */
    private function refusalsOf(string $context, string $useCase): array
    {
        /** @var array<string, array{useCase: string|null}> $exceptions */
        $exceptions = $this->manifest($context)['exceptions'] ?? [];

        return array_map(
            fn (string $exception): string => "{$context}/{$exception}",
            array_keys(array_filter($exceptions, fn (array $entry): bool => $entry['useCase'] === $useCase)),
        );
    }

    /**
     * The value objects, in any context, whose fields name this enum or value object.
     *
     * @return list<string>
     */
    private function fieldUsersOf(string $context, string $name): array
    {
        $own = $this->manifest($context);
        $holder = null;

        foreach (self::VOCABULARY as $section) {
            $holder ??= $own[$section][$name]['aggregate'] ?? null;
        }

        $names = $context === StructureFiles::SHARED ? [StructureFiles::SHARED."/{$name}"] : ["{$context}/{$holder}/{$name}"];
        $users = [];

        foreach ($this->files->contexts() as $owner) {
            $manifest = $this->manifest($owner);

            if ($manifest === null) {
                continue;
            }

            /** @var array<string, array{aggregate: string|null, fields: array<string, string>}> $valueObjects */
            $valueObjects = $manifest['valueObjects'];

            foreach ($valueObjects as $valueObject => $entry) {
                $bare = $owner === $context && $entry['aggregate'] === $holder ? [$name] : [];

                foreach ($entry['fields'] as $type) {
                    if (array_intersect(preg_split('/[|&]/', ltrim($type, '?')) ?: [], [...$names, ...$bare]) !== []) {
                        $users[] = "{$owner}/{$valueObject}";
                    }
                }
            }
        }

        return array_values(array_unique($users));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function useCaseErrors(string $context, string $name, array $entry): array
    {
        $errors = [];
        $returns = $entry['returns'];

        if ($entry['shape'] === 'command-result' && $returns !== 'result') {
            $errors['returns'][] = 'A use case that takes a Command and hands back a Result returns result.';
        } elseif ($entry['shape'] === 'command' && ! in_array($returns, self::COMMAND_RETURNS, true)) {
            $errors['returns'][] = 'A use case that takes a Command returns void, string or int (handlers.md).';
        }

        if ($entry['query'] === true && ($entry['shape'] !== 'command-result' || ! str_starts_with($name, 'List'))) {
            $errors['query'][] = 'A list use case is named List{Name} and takes a Command and returns a Result (list-queries.md).';
        }

        /** @var list<string> $repositories */
        $repositories = $entry['repositories'];

        foreach ($repositories as $repository) {
            [$owner, $aggregate] = str_contains($repository, '/') ? explode('/', $repository, 2) : [$context, $repository];
            $manifest = $owner === $context ? $this->manifest($context) : $this->manifest($owner);

            if (! ($manifest['aggregates'][$aggregate]['repository'] ?? false)) {
                $errors['repositories'][] = "{$owner} has no {$aggregate}Repository.";
            }
        }

        return $errors;
    }

    /**
     * The services and use cases, in any context, that build or inject an aggregate of this one.
     *
     * @return list<string>
     */
    private function referencesTo(string $context, string $aggregate, bool $repositoriesOnly = false): array
    {
        $users = [];

        foreach ($repositoriesOnly ? [] : [...self::VOCABULARY, 'exceptions', 'entities'] as $section) {
            /** @var array<string, array{aggregate: string|null}> $entries */
            $entries = $this->manifest($context)[$section] ?? [];

            foreach ($entries as $name => $entry) {
                if ($entry['aggregate'] === $aggregate) {
                    $users[] = "{$context}/{$name}";
                }
            }
        }

        foreach ($this->files->contexts() as $owner) {
            $manifest = $this->manifest($owner);

            if ($manifest === null) {
                continue;
            }

            $names = $owner === $context ? [$aggregate, "{$context}/{$aggregate}"] : ["{$context}/{$aggregate}"];

            foreach (['services', 'useCases'] as $section) {
                /** @var array<string, array{repositories: list<string>, creates?: string|bool|null}> $entries */
                $entries = $manifest[$section];

                foreach ($entries as $name => $entry) {
                    $creates = ! $repositoriesOnly && $section === 'services' && in_array($entry['creates'] ?? null, $names, true);

                    if ($creates || array_intersect($entry['repositories'], $names) !== []) {
                        $users[] = "{$owner}/{$name}";
                    }
                }
            }
        }

        return $users;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, list<string>>  $more
     * @return array<string, list<string>>
     */
    private function merge(array $errors, array $more): array
    {
        foreach ($more as $field => $messages) {
            $errors[$field] = [...$errors[$field] ?? [], ...$messages];
        }

        return $errors;
    }
}
