<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Illuminate\Support\Str;

/**
 * Changes an HTTP resource's manifest one piece at a time, for the kit's structure screen
 * (structure.md): its model and policy, a controller method, an action or a page. Each change is
 * checked against the manifest's shape and the design rules a manifest alone can show, then
 * written in canonical form, or refused with what is wrong, keyed by the form field that holds it.
 *
 * A piece the code already has is never changed or removed here, as in StructureEditor.
 */
final class StructureResourceEditor
{
    private const string STUDLY = '/^[A-Z][A-Za-z0-9]*$/';

    private const string CAMEL = '/^[a-z][A-Za-z0-9]*$/';

    private const string USE_CASE = '#^[A-Z][A-Za-z0-9]*/[A-Z][A-Za-z0-9]*$#';

    private const string PAGE = '#^[a-z0-9-]+(/[a-z0-9-]+)+$#';

    private const array SECTIONS = ['controller', 'actions', 'pages'];

    private const array TAKES_COMMAND = ['command', 'command-result'];

    public function __construct(
        private readonly StructureFiles $files,
        private readonly StructureReader $reader,
    ) {}

    /**
     * Writes the empty manifest of a new resource.
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function createResource(string $name, ?string $model): array
    {
        $errors = [];

        if (preg_match(self::STUDLY, $name) !== 1) {
            $errors['name'][] = 'A resource is named after its controller, in StudlyCase.';
        } elseif (in_array($name, StructureReader::KIT_RESOURCES, true)) {
            $errors['name'][] = "{$name} is the kit's own page.";
        } elseif ($this->files->resourceExists($name)) {
            $errors['name'][] = "{$name} already has a manifest.";
        }

        if ($model !== null && preg_match(self::STUDLY, $model) !== 1) {
            $errors['model'][] = 'A model is named in StudlyCase.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $this->files->writeResource(['resource' => $name, 'model' => $model, 'controller' => [], 'actions' => [], 'policy' => null, 'pages' => []]);

        return [];
    }

    /**
     * Sets the model a resource stands for and the abilities of its policy, or no policy.
     *
     * @param  list<string>|null  $policy
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function saveResource(string $resource, string $version, ?string $model, ?array $policy): array
    {
        $manifest = $this->manifest($resource);
        $refused = $this->refusal($resource, $manifest, $version);

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        $built = $this->builtEntries($resource);
        $errors = [];

        if ($model !== null && preg_match(self::STUDLY, $model) !== 1) {
            $errors['model'][] = 'A model is named in StudlyCase.';
        } elseif (in_array('model', $built, true) && $model !== $manifest['model']) {
            $errors['model'][] = 'The code already has this model, so the screen leaves it alone.';
        }

        if ($policy !== null) {
            $policy = array_values(array_unique($policy));
            sort($policy);

            foreach ($policy as $ability) {
                if (preg_match(self::CAMEL, $ability) !== 1) {
                    $errors['policy'][] = "An ability is camelCase: {$ability} is not.";
                }
            }

            if ($model === null) {
                $errors['policy'][] = 'A policy is attached to its model, so a resource with no model has none.';
            }
        }

        $current = is_array($manifest['policy']) ? $manifest['policy'] : null;

        if ($current !== null) {
            sort($current);
        }

        if (in_array('policy', $built, true) && $policy !== $current) {
            $errors['policy'][] = 'The code already has this policy, so the screen leaves it alone.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $manifest['model'] = $model;
        $manifest['policy'] = $policy;

        return $this->write($resource, $manifest);
    }

    /**
     * Adds a controller method, an action or a page, or changes or renames the one named
     * `$previous`.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function savePiece(string $resource, string $version, string $section, ?string $previous, string $name, array $entry): array
    {
        $manifest = $this->manifest($resource);
        $refused = $this->refusal($resource, $manifest, $version, $section) ?? ($previous === null ? null : $this->lockedRefusal($resource, $manifest, $section, $previous));

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        /** @var array<string, mixed> $pieces */
        $pieces = $manifest[$section];
        $errors = [];

        if ($name !== $previous && array_key_exists($name, $pieces)) {
            $errors['name'][] = "The manifest already has {$name}.";
        } elseif ($name !== $previous && in_array("{$section}.{$name}", $this->builtEntries($resource), true)) {
            $errors['name'][] = "The code already has {$name}. Run `php artisan kit:import --resource={$resource} --force` to read it into the manifest.";
        }

        if ($previous !== null && $previous !== $name) {
            $errors = $this->merge($errors, $this->dependants($manifest, $section, $previous));
        }

        [$value, $pieceErrors] = match ($section) {
            'controller' => $this->method($name, $entry),
            'actions' => $this->action($name, $entry),
            default => $this->page($manifest, $name, $entry),
        };
        $errors = $this->merge($errors, $pieceErrors);

        if ($errors !== []) {
            return $errors;
        }

        if ($previous !== null) {
            unset($pieces[$previous]);
        }

        $pieces[$name] = $value;
        $manifest[$section] = $pieces;

        return $this->write($resource, $manifest);
    }

    /**
     * Removes a controller method, an action or a page the code does not have yet.
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was removed
     */
    public function removePiece(string $resource, string $version, string $section, string $name): array
    {
        $manifest = $this->manifest($resource);
        $refused = $this->refusal($resource, $manifest, $version, $section) ?? $this->lockedRefusal($resource, $manifest, $section, $name);

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        $errors = $this->dependants($manifest, $section, $name);

        if ($errors !== []) {
            return $errors;
        }

        /** @var array<string, mixed> $pieces */
        $pieces = $manifest[$section];
        unset($pieces[$name]);
        $manifest[$section] = $pieces;

        return $this->write($resource, $manifest);
    }

    /**
     * Takes one built entry back from the code once the code has changed: a controller method,
     * an action, a page, the model or the policy (`$section` names which).
     *
     * @return array<string, list<string>> what is wrong, by field; empty when it was written
     */
    public function syncPiece(string $resource, string $version, string $section, string $name): array
    {
        $manifest = $this->manifest($resource);
        $refused = $this->refusal($resource, $manifest, $version, in_array($section, ['model', 'policy'], true) ? null : $section);

        if ($refused !== null || $manifest === null) {
            return $refused ?? [];
        }

        $synced = (new StructureSync($this->files, $this->reader))->syncResourcePiece($resource, $section, $name);

        if (isset($synced['error'])) {
            return ['name' => [$synced['error']]];
        }

        return $this->write($resource, $synced['manifest']);
    }

    /**
     * A controller method and the use cases it calls (form-pages.md, list-queries.md).
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private function method(string $name, array $entry): array
    {
        $errors = [];
        $useCases = $this->useCaseList($entry['useCases'] ?? []);

        if (preg_match(self::CAMEL, $name) !== 1) {
            $errors['name'][] = 'A method name is camelCase. An invokable controller is an action.';
        }

        $errors = $this->merge($errors, $this->useCaseErrors($useCases));
        $shapes = ['index' => ['command-result'], 'store' => self::TAKES_COMMAND, 'update' => self::TAKES_COMMAND, 'destroy' => ['plain']][$name] ?? null;

        if ($shapes !== null && ! isset($errors['useCases'])) {
            $entries = array_map($this->useCase(...), $useCases);

            if (count($useCases) !== 1 || ! in_array($entries[0]['shape'] ?? null, $shapes, true)) {
                $errors['useCases'][] = match ($name) {
                    'index' => 'index reads its page through one use case that takes a Command and returns a Result (list-queries.md).',
                    'destroy' => 'destroy deletes through one plain use case, which takes the id (write-path.md).',
                    default => "{$name} writes through one use case that takes a Command (form-pages.md).",
                };
            }
        }

        return [$useCases, $errors];
    }

    /**
     * An action, its row and bulk controllers and the one use case both call (actions.md).
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: array{row: bool, bulk: bool, useCases: list<string>}, 1: array<string, list<string>>}
     */
    private function action(string $name, array $entry): array
    {
        $errors = [];
        $row = ($entry['row'] ?? false) === true;
        $bulk = ($entry['bulk'] ?? false) === true;
        $useCases = $this->useCaseList($entry['useCases'] ?? []);

        if (preg_match(self::STUDLY, $name) !== 1) {
            $errors['name'][] = 'An action is named with a verb in StudlyCase: Cancel, ChangeStatus.';
        }

        if (! $row && ! $bulk) {
            $errors['row'][] = 'An action acts on a row, on a selection, or on both.';
        }

        $errors = $this->merge($errors, $this->useCaseErrors($useCases));

        if (! isset($errors['useCases'])) {
            $useCase = count($useCases) === 1 ? $this->useCase($useCases[0]) : null;

            if ($useCase === null || ! in_array($useCase['shape'], self::TAKES_COMMAND, true)) {
                $errors['useCases'][] = 'An action calls one use case that takes a Command, the one its row and bulk controllers share.';
            } elseif ($bulk && ($useCase['shape'] !== 'command' || $useCase['returns'] !== 'int')) {
                $errors['useCases'][] = 'A bulk action calls a use case that takes ids in its Command and returns how many rows it changed, as int.';
            }
        }

        return [['row' => $row, 'bulk' => $bulk, 'useCases' => $useCases], $errors];
    }

    /**
     * A page, at the path its generator writes it to, beside the controller method it needs.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: array<string, list<string>>}
     */
    private function page(array $manifest, string $name, array $entry): array
    {
        $errors = [];
        $kind = is_string($entry['kind'] ?? null) ? $entry['kind'] : '';
        /** @var array<string, list<string>> $controller */
        $controller = $manifest['controller'];

        if (! in_array($kind, StructureFiles::PAGE_KINDS, true)) {
            $errors['kind'][] = 'A page is one of '.implode(', ', StructureFiles::PAGE_KINDS).'.';
        }

        if (preg_match(self::PAGE, $name) !== 1) {
            $errors['name'][] = 'A page is its path under resources/js/pages, in kebab case, with a folder: agents/index.';
        } elseif (is_string($manifest['model']) && $kind !== 'page') {
            $paths = $this->generatedPaths($manifest, $kind);

            if ($paths !== [] && ! in_array($name, $paths, true)) {
                $errors['name'][] = 'A '.$kind.' page sits at '.implode(' or ', $paths).', where its generator writes it.';
            }
        }

        if (in_array($kind, ['table', 'grid'], true) && ! array_key_exists('index', $controller)) {
            $errors['kind'][] = 'A list page is rendered by index, so add the index method first.';
        }

        if ($kind === 'form' && ! array_key_exists('store', $controller) && ! array_key_exists('update', $controller)) {
            $errors['kind'][] = 'A form page posts to store or update, so add one of them first.';
        }

        return [$kind, $errors];
    }

    /**
     * Where kit:apply's generator writes a page of this kind: make:list-page names the folder after
     * the index's List{Names} use case, make:form-page after the model. Empty when the index has no
     * use case to name it after yet.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function generatedPaths(array $manifest, string $kind): array
    {
        if ($kind === 'form') {
            $folder = Str::kebab(Str::pluralStudly((string) $manifest['model']));

            return ["{$folder}/create", "{$folder}/edit"];
        }

        $index = $manifest['controller']['index'][0] ?? null;

        if (! is_string($index) || ! str_contains($index, '/List')) {
            return [];
        }

        return [Str::kebab(substr($index, (int) strpos($index, '/List') + 5)).'/index'];
    }

    /**
     * Why a controller method may not go: a page still needs it.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, list<string>>
     */
    private function dependants(array $manifest, string $section, string $name): array
    {
        if ($section !== 'controller') {
            return [];
        }

        /** @var array<string, string> $pages */
        $pages = $manifest['pages'];
        /** @var array<string, list<string>> $controller */
        $controller = $manifest['controller'];
        $kinds = array_values($pages);

        if ($name === 'index' && array_intersect($kinds, ['table', 'grid']) !== []) {
            return ['name' => ['index renders the list page, so it stays while the page does.']];
        }

        $writers = array_diff(array_intersect(array_keys($controller), ['store', 'update']), [$name]);

        if (in_array($name, ['store', 'update'], true) && $writers === [] && in_array('form', $kinds, true)) {
            return ['name' => ["{$name} is where the form pages post, so it stays while they do."]];
        }

        return [];
    }

    /**
     * @param  list<string>  $useCases
     * @return array<string, list<string>>
     */
    private function useCaseErrors(array $useCases): array
    {
        $errors = [];

        foreach ($useCases as $useCase) {
            if (preg_match(self::USE_CASE, $useCase) !== 1) {
                $errors['useCases'][] = "A use case is named Context/UseCase: {$useCase} is not.";
            } elseif ($this->useCase($useCase) === null) {
                $errors['useCases'][] = "No context manifest lists {$useCase}.";
            }
        }

        return $errors;
    }

    /**
     * The manifest entry of a `Context/UseCase` reference, or null.
     *
     * @return array{shape: string, returns: string}|null
     */
    private function useCase(string $reference): ?array
    {
        [$context, $name] = explode('/', $reference, 2) + [1 => ''];

        if (! $this->files->exists($context)) {
            return null;
        }

        $manifest = $this->files->read($context);
        $entry = is_array($manifest) ? ($manifest['useCases'][$name] ?? null) : null;

        return is_array($entry) && is_string($entry['shape'] ?? null) && is_string($entry['returns'] ?? null)
            ? ['shape' => $entry['shape'], 'returns' => $entry['returns']]
            : null;
    }

    /**
     * @return list<string>
     */
    private function useCaseList(mixed $useCases): array
    {
        $list = is_array($useCases) ? array_unique(array_filter($useCases, is_string(...))) : [];
        sort($list);

        return $list;
    }

    /**
     * The entries of this resource the code already has, as resourceEntries() names them.
     *
     * @return list<string>
     */
    private function builtEntries(string $resource): array
    {
        $entries = StructureComparer::resourceEntries($this->reader->readResource($resource));

        return array_map(strval(...), array_keys(array_filter($entries, fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifest(string $resource): ?array
    {
        if (! $this->files->resourceExists($resource)) {
            return null;
        }

        $manifest = $this->files->readResource($resource);

        return is_array($manifest) && $this->files->resourceProblems($resource, $manifest) === [] ? $manifest : null;
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, list<string>>|null
     */
    private function refusal(string $resource, ?array $manifest, string $version, ?string $section = null): ?array
    {
        return match (true) {
            $manifest === null => ['resource' => ["{$resource} has no manifest the screen can change. Fix it by hand first."]],
            $version !== $this->files->resourceVersion($resource) => ['version' => ['The manifest changed since this page loaded. Reload it, then make the change again.']],
            $section !== null && ! in_array($section, self::SECTIONS, true) => ['section' => ["{$section} is not a part of a resource the screen changes."]],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, list<string>>|null
     */
    private function lockedRefusal(string $resource, ?array $manifest, string $section, string $name): ?array
    {
        if ($manifest === null || ! is_array($manifest[$section]) || ! array_key_exists($name, $manifest[$section])) {
            return ['name' => ["The manifest has no {$name}."]];
        }

        if (in_array("{$section}.{$name}", $this->builtEntries($resource), true)) {
            return ['name' => ["The code already has {$name}, so the screen leaves it alone. Change what is built by replacing it."]];
        }

        return null;
    }

    /**
     * Writes the manifest once its whole shape still holds.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, list<string>>
     */
    private function write(string $resource, array $manifest): array
    {
        $problems = $this->files->resourceProblems($resource, json_decode($this->files->encodeResource($manifest), true));

        if ($problems !== []) {
            return ['name' => array_map(fn (string $problem): string => ucfirst($problem).'.', $problems)];
        }

        $this->files->writeResource($manifest);

        return [];
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
