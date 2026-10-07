<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Routing\Console\ControllerMakeCommand;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesGeneratedFiles;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Laravel's `make:controller`, held to the rules: it writes only a resource controller for one
 * model, each method in the one shape authorization.md and form-pages.md allow, read off the
 * use cases a human already filled in. `--only` picks the methods, and a run against a
 * controller that already exists adds the ones it lacks, in resource order, without touching
 * the rest. Each new action gets its test at the path testing.md mirrors, every case a todo.
 * `--grid` writes index for a grid page: rows through `Inertia::scroll()` and no sort.
 *
 * An action that is not create, edit or delete is an invokable controller of its own
 * (`make:action`). The route, the policy abilities and the translations are printed, never
 * edited in.
 */
#[AsCommand(name: 'make:controller')]
class MakeControllerCommand extends ControllerMakeCommand
{
    use ResolvesDomain;
    use WritesGeneratedFiles;

    /**
     * The seven methods `Route::resource()` registers, in the order a controller declares them.
     */
    protected const array METHODS = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

    /**
     * What each method answers to, for the test's header.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const array ROUTES = [
        'index' => ['GET', ''],
        'create' => ['GET', '/create'],
        'store' => ['POST', ''],
        'show' => ['GET', '/{%s}'],
        'edit' => ['GET', '/{%s}/edit'],
        'update' => ['PUT', '/{%s}'],
        'destroy' => ['DELETE', '/{%s}'],
    ];

    /**
     * The cases each action's test needs beyond the guest and the refused user (testing.md).
     *
     * @var array<string, list<string>>
     */
    protected const array CASES = [
        'index' => [
            'renders the page with its rows, sort and filters',
            'tells the page what the user may do through can',
        ],
        'index.grid' => [
            'renders the page with its rows through Inertia::scroll() and its filters',
            'tells the page what the user may do through can',
        ],
        'create' => [
            'renders the form with empty defaults',
        ],
        'store' => [
            'creates the row, redirects and answers with a success toast',
            'rejects each invalid field',
            'answers with an error toast for each refusal the controller catches',
        ],
        'show' => [
            'renders the row and what the user may do with it',
            'answers 404 for an unknown id',
        ],
        'edit' => [
            'renders the form with the defaults of the row',
            'answers 404 for an unknown id',
        ],
        'update' => [
            'updates the row, redirects and answers with a success toast',
            'rejects each invalid field',
            'answers with an error toast for each refusal the controller catches',
            'answers 404 for an unknown id',
        ],
        'destroy' => [
            'deletes the row and answers with a success toast',
            'answers with an error toast when the entity refuses the delete',
            'answers 404 for an unknown id',
        ],
    ];

    /**
     * Laravel's flags for the controllers this command does not write, and why.
     *
     * @var array<string, string>
     */
    protected const array REFUSED = [
        'invokable' => 'An action that is not create, edit or delete is an invokable controller of its own: run `php artisan make:action` (actions.md).',
        'api' => 'make:controller writes the pages of an Inertia resource. Leave out --api, and pick the methods with --only.',
        'singleton' => 'make:controller writes resource controllers only. A singleton resource is not scaffolded here.',
        'creatable' => 'make:controller writes resource controllers only. A singleton resource is not scaffolded here.',
        'parent' => 'make:controller writes resource controllers only. A nested resource is not scaffolded here.',
        'type' => 'make:controller writes one shape of controller. Pick the methods with --only instead of a stub type.',
    ];

    protected $description = 'Create a resource controller from its use cases, and a test per action';

    /**
     * @var list<string> the classes the file being rendered imports
     */
    protected array $imports = [];

    /**
     * @var list<string> the policy abilities the written methods ask
     */
    protected array $abilities = [];

    /**
     * @var array<string, list<string>> the imports each rendered method needs
     */
    protected array $methodImports = [];

    #[Override]
    public function handle(): int
    {
        $this->forgetResolvedDomain();
        $this->warnings = [];
        $this->langKeys = [];
        $this->abilities = [];
        $this->methodImports = [];

        foreach (self::REFUSED as $option => $reason) {
            if ($this->input->hasParameterOption('--'.$option)) {
                $this->components->error($reason);

                return self::FAILURE;
            }
        }

        $only = $this->only();

        if ($only === null) {
            return self::FAILURE;
        }

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        if (in_array('index', $only, true) && ! class_exists($this->useCaseClass('list', 'Command'))) {
            $this->components->error(sprintf(
                '[%s] does not exist. Run `php artisan make:use-case %s --domain=%s --command --result --query` and fill in its Command first.',
                $this->useCaseClass('list', 'Command'),
                $this->useCase('list'),
                $this->resolveDomain(),
            ));

            return self::FAILURE;
        }

        $path = $this->controllerPath();
        $existing = $this->files->exists($path) ? $this->publicMethodsIn($this->files->get($path)) : [];
        $adding = array_values(array_diff($only, $existing));
        $methods = array_values(array_intersect(self::METHODS, [...$existing, ...$adding]));

        foreach (array_intersect($only, $existing) as $method) {
            $this->components->info(sprintf('%s already has %s(), left as it is.', $this->controllerClass(), $method));
        }

        if ($adding !== []) {
            $this->warnAboutMissingClasses($adding);

            $rendered = array_map(fn (string $method): string => $this->renderMethod($method, $methods), $adding);
            $files = array_combine($adding, $rendered);

            if (! $this->files->exists($path)) {
                $this->writeController($files);
            } else {
                $this->addToController($path, $files);
                $this->warnAboutCanKeys($existing, $adding);
            }

            foreach ($adding as $method) {
                $this->writeTest($method);
            }
        }

        $this->warnAboutAbilities();
        $this->warnAboutLangKeys();

        foreach (array_unique($this->warnings) as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info('Register the route, then run `php artisan wayfinder:generate --with-form --no-interaction`:');
        $this->line('    '.$this->routeLine($methods));

        return self::SUCCESS;
    }

    /**
     * The name prompt is all this command asks; Laravel's question about the kind of controller
     * has one answer here.
     */
    #[Override]
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void {}

    /**
     * @return array<int, array<int, mixed>>
     */
    #[Override]
    protected function getOptions(): array
    {
        return [
            ...parent::getOptions(),
            ['domain', null, InputOption::VALUE_REQUIRED, 'Context under App\Application that owns the use cases'],
            ['only', null, InputOption::VALUE_REQUIRED, 'The resource methods to write, comma separated (all seven by default)'],
            ['list', null, InputOption::VALUE_REQUIRED, 'The use case index reads, List and the plural model by default'],
            ['create', null, InputOption::VALUE_REQUIRED, 'The use case store calls, Create and the model by default'],
            ['update', null, InputOption::VALUE_REQUIRED, 'The use case update calls, Update and the model by default'],
            ['delete', null, InputOption::VALUE_REQUIRED, 'The plain use case destroy calls, Delete and the model by default'],
            ['grid', null, InputOption::VALUE_NONE, 'Write index for a grid page: rows through Inertia::scroll() and no sort'],
        ];
    }

    protected function domainSubject(): string
    {
        return 'controller';
    }

    /**
     * The methods `--only` names, in resource order, or null when it names one that is not.
     *
     * @return list<string>|null
     */
    protected function only(): ?array
    {
        $option = $this->option('only');
        $option = is_string($option) ? $option : '';

        if (blank($option)) {
            return self::METHODS;
        }

        $names = array_values(array_filter(array_map('trim', explode(',', strtolower($option)))));
        $unknown = array_diff($names, self::METHODS);

        if ($unknown !== []) {
            $this->components->error(sprintf(
                '--only names %s, which a resource controller does not have. Pick from %s.',
                implode(', ', $unknown),
                implode(', ', self::METHODS),
            ));

            return null;
        }

        return array_values(array_intersect(self::METHODS, $names));
    }

    /**
     * `LotteryType`, whether the caller typed the model or the controller.
     */
    protected function model(): string
    {
        $model = is_string($this->option('model')) && filled($this->option('model'))
            ? $this->option('model')
            : Str::chopEnd(class_basename(str_replace('/', '\\', $this->getNameInput())), 'Controller');

        return Str::studly(class_basename(str_replace('/', '\\', $model)));
    }

    protected function controllerClass(): string
    {
        return $this->model().'Controller';
    }

    protected function controllerPath(): string
    {
        return app_path('Http/Controllers/'.$this->controllerClass().'.php');
    }

    protected function modelClass(): string
    {
        return $this->qualifyModel($this->model());
    }

    /**
     * `lotteryType`: the bound model's variable and its prop on show and edit.
     */
    protected function variable(): string
    {
        return Str::camel($this->model());
    }

    /**
     * `lottery_type`: the route parameter `Route::resource()` names.
     */
    protected function parameter(): string
    {
        return Str::snake($this->model());
    }

    /**
     * `lottery-types`: the uri, the route names, the page folder and the translation file.
     */
    protected function prefix(): string
    {
        return Str::kebab(Str::pluralStudly($this->model()));
    }

    /**
     * The use case behind one of `--list`, `--create`, `--update` or `--delete`.
     */
    protected function useCase(string $kind): string
    {
        $default = match ($kind) {
            'list' => 'List'.Str::pluralStudly($this->model()),
            'create' => 'Create'.$this->model(),
            'update' => 'Update'.$this->model(),
            default => 'Delete'.$this->model(),
        };

        $option = $this->option($kind);

        return Str::studly(is_string($option) && $option !== '' ? $option : $default);
    }

    /**
     * A use case's class: in its own folder, or at `UseCases/` for the plain delete handler.
     */
    protected function useCaseClass(string $kind, string $suffix): string
    {
        $namespace = $this->laravel->getNamespace().'Application\\'.$this->resolveDomain().'\\UseCases\\';

        return $kind === 'delete'
            ? $namespace.$this->useCase($kind).$suffix
            : $namespace.$this->useCase($kind).'\\'.$this->useCase($kind).$suffix;
    }

    protected function requestClass(string $name): string
    {
        return $this->laravel->getNamespace().'Http\\Requests\\'.$this->model().'\\'.$name;
    }

    /**
     * The public methods a controller file declares, read from its text: the class may already be
     * loaded from before this run.
     *
     * @return list<string>
     */
    protected function publicMethodsIn(string $contents): array
    {
        preg_match_all('/^\s*public function (\w+)\s*\(/m', $contents, $matches);

        return $matches[1];
    }

    /**
     * One method, filled for the methods the controller has once this run is done.
     *
     * @param  list<string>  $methods
     */
    protected function renderMethod(string $method, array $methods): string
    {
        $this->imports = [];

        $replacements = match ($method) {
            'index' => $this->indexReplacements($methods),
            'create', 'edit' => $this->formReplacements($method),
            'store' => $this->storeReplacements($methods),
            'show' => $this->showReplacements($methods),
            'update' => $this->updateReplacements($methods),
            default => $this->destroyReplacements(),
        };

        $this->methodImports[$method] = $this->imports;

        return rtrim($this->render('resource-controller-methods/'.$this->shapeOf($method).'.stub', $replacements));
    }

    /**
     * The stub and test cases a method takes: `index.grid` for index under `--grid`, its own
     * name otherwise.
     */
    protected function shapeOf(string $method): string
    {
        return $method === 'index' && $this->option('grid') ? 'index.grid' : $method;
    }

    /**
     * @param  list<string>  $methods
     * @return array<string, string>
     */
    protected function indexReplacements(array $methods): array
    {
        $command = $this->useCaseClass('list', 'Command');
        $handler = $this->useCaseClass('list', 'Handler');

        $this->imports = [$command, $handler, $this->modelClass(), 'Illuminate\Http\Request', 'Illuminate\Support\Facades\Gate', 'Inertia\Inertia', 'Inertia\Response'];
        $this->abilities[] = 'viewAny';

        $arguments = array_map(
            fn (ReflectionParameter $parameter): string => sprintf(
                "            %s: \$request->string('%s')->toString(),",
                $parameter->getName(),
                Str::snake($parameter->getName()),
            ),
            (new ReflectionMethod($command, '__construct'))->getParameters(),
        );

        $this->warnAboutGridSort($command);

        $can = array_filter([
            'create' => in_array('create', $methods, true) || in_array('store', $methods, true) ? 'create' : null,
            'update' => in_array('edit', $methods, true) || in_array('update', $methods, true) ? 'updateAny' : null,
            'delete' => in_array('destroy', $methods, true) ? 'deleteAny' : null,
        ]);

        return [
            '{{ listCommand }}' => class_basename($command),
            '{{ listHandler }}' => class_basename($handler),
            '{{ listVariable }}' => Str::camel($this->useCase('list')),
            '{{ listArguments }}' => implode("\n", $arguments),
            '{{ rows }}' => $this->rowsProperty(),
            '{{ can }}' => $this->canBlock($can, $this->model().'::class'),
        ];
    }

    /**
     * A grid sends no sort (list-pages.md), so a list Command that still takes one hands the
     * handler a value no page ever sets.
     */
    protected function warnAboutGridSort(string $command): void
    {
        if (! $this->option('grid')) {
            return;
        }

        $sorting = array_values(array_intersect(
            array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod($command, '__construct'))->getParameters()),
            ['sort', 'direction'],
        ));

        if ($sorting !== []) {
            $this->warnings[] = sprintf('%s takes %s, but a grid sends no sort. Drop it from the Command and settle the order in the handler.', class_basename($command), implode(' and ', $sorting));
        }
    }

    /**
     * @return array<string, string>
     */
    protected function formReplacements(string $method): array
    {
        $this->imports = [$this->modelClass(), $this->requestClass($this->model().'FormValues'), 'Illuminate\Support\Facades\Gate', 'Inertia\Inertia', 'Inertia\Response'];
        $this->abilities[] = $method === 'create' ? 'create' : 'update';

        return [];
    }

    /**
     * @param  list<string>  $methods
     * @return array<string, string>
     */
    protected function storeReplacements(array $methods): array
    {
        $handler = $this->useCaseClass('create', 'Handler');
        $variable = Str::camel($this->useCase('create'));

        $this->imports = [$handler, $this->requestClass('Store'.$this->model().'Request'), $this->laravel->getNamespace().'Http\FlashToast', 'Inertia\Inertia', 'Symfony\Component\HttpFoundation\RedirectResponse'];
        $this->abilities[] = 'create';
        $this->langKeys[] = $this->prefix().'.created';

        $toShow = in_array('show', $methods, true) && $this->returnsTheNewId($handler);

        $body = $toShow
            ? <<<'PHP'
        $id = ${{ createVariable }}($request->toCommand());

        Inertia::flash(FlashToast::KEY, FlashToast::success(__('{{ prefix }}.created')));

        return to_route('{{ prefix }}.show', $id);
PHP
            : <<<'PHP'
        ${{ createVariable }}($request->toCommand());

        Inertia::flash(FlashToast::KEY, FlashToast::success(__('{{ prefix }}.created')));

        return to_route('{{ prefix }}.index');
PHP;

        return [
            '{{ createHandler }}' => class_basename($handler),
            '{{ createVariable }}' => $variable,
            '{{ body }}' => str_replace('{{ createVariable }}', $variable, $body),
        ];
    }

    /**
     * @param  list<string>  $methods
     * @return array<string, string>
     */
    protected function showReplacements(array $methods): array
    {
        $this->imports = [$this->modelClass(), 'Illuminate\Support\Facades\Gate', 'Inertia\Inertia', 'Inertia\Response'];
        $this->abilities[] = 'view';

        $can = array_filter([
            'update' => in_array('edit', $methods, true) || in_array('update', $methods, true) ? 'update' : null,
            'delete' => in_array('destroy', $methods, true) ? 'delete' : null,
        ]);

        return ['{{ can }}' => $this->canBlock($can, '$'.$this->variable())];
    }

    /**
     * @param  list<string>  $methods
     * @return array<string, string>
     */
    protected function updateReplacements(array $methods): array
    {
        $handler = $this->useCaseClass('update', 'Handler');

        $this->imports = [$handler, $this->modelClass(), $this->requestClass('Update'.$this->model().'Request'), $this->laravel->getNamespace().'Http\FlashToast', 'Inertia\Inertia', 'Symfony\Component\HttpFoundation\RedirectResponse'];
        $this->abilities[] = 'update';
        $this->langKeys[] = $this->prefix().'.updated';

        return [
            '{{ updateHandler }}' => class_basename($handler),
            '{{ updateVariable }}' => Str::camel($this->useCase('update')),
            '{{ redirect }}' => in_array('show', $methods, true)
                ? sprintf("to_route('%s.show', \$%s)", $this->prefix(), $this->variable())
                : sprintf("to_route('%s.index')", $this->prefix()),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function destroyReplacements(): array
    {
        $handler = $this->useCaseClass('delete', 'Handler');

        $this->imports = [$handler, $this->modelClass(), $this->laravel->getNamespace().'Http\FlashToast', 'Illuminate\Support\Facades\Gate', 'Inertia\Inertia', 'Symfony\Component\HttpFoundation\RedirectResponse'];
        $this->abilities[] = 'delete';
        $this->langKeys[] = $this->prefix().'.deleted';

        return [
            '{{ deleteHandler }}' => class_basename($handler),
            '{{ deleteVariable }}' => Str::camel($this->useCase('delete')),
        ];
    }

    /**
     * The `can` prop, one `Gate::allows()` per key (authorization.md), or nothing when no button
     * needs one.
     *
     * @param  array<string, string>  $abilities  key → the ability it asks
     */
    protected function canBlock(array $abilities, string $subject): string
    {
        if ($abilities === []) {
            return '';
        }

        $this->abilities = [...$this->abilities, ...array_values($abilities)];

        $lines = array_map(
            fn (string $key, string $ability): string => '                '.$this->canLine($key, $ability, $subject).',',
            array_keys($abilities),
            $abilities,
        );

        return "\n            'can' => [\n".implode("\n", $lines)."\n            ],";
    }

    /**
     * One key of `can`, as the stub spells it, without its trailing comma.
     */
    protected function canLine(string $key, string $ability, string $subject): string
    {
        return rtrim(trim($this->render('resource-controller-can.stub', [
            '{{ key }}' => $key,
            '{{ ability }}' => $ability,
            '{{ subject }}' => $subject,
        ])), ',');
    }

    /**
     * The Result's paginator: the one property besides `sort` and `filters`.
     */
    protected function rowsProperty(): string
    {
        $result = $this->useCaseClass('list', 'Result');
        $fallback = Str::camel(Str::pluralStudly($this->model()));

        if (! class_exists($result)) {
            $this->warnings[] = sprintf('[%s] does not exist — index reads $result->%s.', $result, $fallback);

            return $fallback;
        }

        $names = array_values(array_diff(
            array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod($result, '__construct'))->getParameters()),
            ['sort', 'filters'],
        ));

        if (count($names) !== 1) {
            $this->warnings[] = sprintf('%s should carry its rows, sort and filters only — index reads $result->%s.', class_basename($result), $fallback);

            return $fallback;
        }

        return $names[0];
    }

    /**
     * Whether store can send the user to the row it created: the handler returns its id, as
     * `make:use-case --creates` writes it. One that does not exist yet is assumed to.
     */
    protected function returnsTheNewId(string $handler): bool
    {
        if (! method_exists($handler, '__invoke')) {
            return true;
        }

        $return = (new ReflectionMethod($handler, '__invoke'))->getReturnType();

        return $return instanceof ReflectionNamedType && $return->getName() === 'string';
    }

    /**
     * @param  array<string, string>  $methods  method → its rendered code, in resource order
     */
    protected function writeController(array $methods): void
    {
        $this->imports = array_merge(...array_values(array_intersect_key($this->methodImports, $methods)));

        $this->writeFromStub('resource-controller.stub', $this->controllerPath(), 'Controller', [
            '{{ namespace }}' => $this->laravel->getNamespace().'Http\Controllers',
            '{{ class }}' => $this->controllerClass(),
            '{{ methods }}' => implode("\n\n", $methods),
        ]);
    }

    /**
     * Add methods to a controller that exists, each before the next resource method it already
     * has, and merge their imports. No existing line is changed.
     *
     * @param  array<string, string>  $methods  method → its rendered code, in resource order
     */
    protected function addToController(string $path, array $methods): void
    {
        $contents = $this->files->get($path);

        foreach ($methods as $method => $code) {
            $contents = $this->insertMethod($contents, $method, $code);
        }

        $imports = array_merge(...array_values(array_intersect_key($this->methodImports, $methods)));
        $contents = $this->mergeImports($contents, $imports);

        $this->files->put($path, $contents);

        $this->components->info(sprintf(
            'Controller [%s] gained %s.',
            $this->relativePath($path),
            implode(', ', array_map(fn (string $method): string => $method.'()', array_keys($methods))),
        ));
    }

    protected function insertMethod(string $contents, string $method, string $code): string
    {
        $later = array_slice(self::METHODS, (int) array_search($method, self::METHODS, true) + 1);

        foreach ($later as $next) {
            $offset = $this->memberOffset($contents, '/^    public function '.$next.'\s*\(/m');

            if ($offset !== null) {
                return substr($contents, 0, $offset).$code."\n\n".substr($contents, $offset);
            }
        }

        $offset = $this->memberOffset($contents, '/^    (?:private|protected) function /m');

        if ($offset !== null) {
            return substr($contents, 0, $offset).$code."\n\n".substr($contents, $offset);
        }

        $before = rtrim(substr($contents, 0, (int) strrpos($contents, '}')));

        return $before.(str_ends_with($before, '{') ? "\n" : "\n\n").$code."\n}\n";
    }

    /**
     * Where the first member matching the pattern starts, its docblock included.
     */
    protected function memberOffset(string $contents, string $pattern): ?int
    {
        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $match[0][1];

        if (preg_match('#\n(    /\*\*(?:(?!\*/).)*\*/\n)$#s', substr($contents, 0, $offset), $docblock) === 1) {
            $offset -= strlen($docblock[1]);
        }

        return $offset;
    }

    /**
     * @param  list<string>  $imports
     */
    protected function mergeImports(string $contents, array $imports): string
    {
        preg_match_all('/^use ([^;\s]+);\n/m', $contents, $existing, PREG_OFFSET_CAPTURE);

        $classes = array_values(array_unique([...array_column($existing[1], 0), ...array_map(fn (string $class): string => ltrim($class, '\\'), $imports)]));
        usort($classes, 'strcasecmp');
        $block = implode('', array_map(fn (string $class): string => 'use '.$class.";\n", $classes));

        if ($existing[0] === []) {
            return (string) preg_replace('/^(namespace [^;]+;\n)\n?/m', "$1\n".$block."\n", $contents, 1);
        }

        $start = $existing[0][0][1];
        $last = $existing[0][count($existing[0]) - 1];
        $end = $last[1] + strlen($last[0]);

        return substr($contents, 0, $start).$block.substr($contents, $end);
    }

    protected function writeTest(string $method): void
    {
        [$verb, $suffix] = self::ROUTES[$method];

        $this->writeFromStub(
            'resource-controller-test.stub',
            base_path('tests/Feature/Http/Controllers/'.$this->controllerClass().'/'.Str::studly($method).'Test.php'),
            'Test',
            [
                '{{ verb }}' => $verb,
                '{{ uri }}' => $this->prefix().sprintf($suffix, $this->parameter()),
                '{{ routeName }}' => $this->prefix().'.'.$method,
                '{{ class }}' => $this->controllerClass(),
                '{{ action }}' => $method,
                '{{ cases }}' => implode("\n\n", array_map(
                    fn (string $case): string => sprintf("it('%s')->todo();", $case),
                    self::CASES[$this->shapeOf($method)],
                )),
            ],
        );
    }

    /**
     * The use cases, requests and form values the written methods name, which nothing else would
     * point out until the page is opened.
     *
     * @param  list<string>  $adding
     */
    protected function warnAboutMissingClasses(array $adding): void
    {
        $needs = [
            'index' => [[$this->useCaseClass('list', 'Handler'), 'make:use-case']],
            'create' => [[$this->requestClass($this->model().'FormValues'), 'make:form-request']],
            'store' => [[$this->requestClass('Store'.$this->model().'Request'), 'make:form-request'], [$this->useCaseClass('create', 'Handler'), 'make:use-case']],
            'edit' => [[$this->requestClass($this->model().'FormValues'), 'make:form-request']],
            'update' => [[$this->requestClass('Update'.$this->model().'Request'), 'make:form-request'], [$this->useCaseClass('update', 'Handler'), 'make:use-case']],
            'destroy' => [[$this->useCaseClass('delete', 'Handler'), 'make:use-case --plain']],
        ];

        if (! class_exists($this->modelClass())) {
            $this->warnings[] = sprintf('Model [%s] does not exist — the controller authorizes against it and the route binds it.', $this->modelClass());
        }

        foreach ($adding as $method) {
            foreach ($needs[$method] ?? [] as [$class, $command]) {
                if (! class_exists($class)) {
                    $this->warnings[] = sprintf('[%s] does not exist yet — %s() names it. Write it with `php artisan %s`.', $class, $method, $command);
                }
            }
        }
    }

    /**
     * index and show were written before the buttons the new methods back, and their bodies are
     * never edited, so name the `can` line each one still lacks.
     *
     * @param  list<string>  $existing
     * @param  list<string>  $adding
     */
    protected function warnAboutCanKeys(array $existing, array $adding): void
    {
        $contents = $this->files->get($this->controllerPath());
        $class = $this->model().'::class';
        $model = '$'.$this->variable();

        $wanted = [
            'index' => [
                [['create', 'store'], $this->canLine('create', 'create', $class)],
                [['edit', 'update'], $this->canLine('update', 'updateAny', $class)],
                [['destroy'], $this->canLine('delete', 'deleteAny', $class)],
            ],
            'show' => [
                [['edit', 'update'], $this->canLine('update', 'update', $model)],
                [['destroy'], $this->canLine('delete', 'delete', $model)],
            ],
        ];

        foreach ($wanted as $page => $keys) {
            if (! in_array($page, $existing, true)) {
                continue;
            }

            foreach ($keys as [$methods, $line]) {
                if (array_intersect($methods, $adding) !== [] && ! str_contains($contents, $line)) {
                    $this->warnings[] = sprintf('Add %s to the can of %s(), so the page shows the button.', $line, $page);
                }
            }
        }
    }

    /**
     * Laravel denies an ability no policy declares, so a missing one hides a button silently.
     */
    protected function warnAboutAbilities(): void
    {
        $policy = $this->laravel->getNamespace().'Policies\\'.$this->model().'Policy';

        if ($this->abilities === []) {
            return;
        }

        if (! class_exists($policy)) {
            $this->warnings[] = sprintf('[%s] does not exist — run `php artisan make:policy %s` (authorization.md).', $policy, $this->model());

            return;
        }

        $missing = array_values(array_filter(
            array_unique($this->abilities),
            fn (string $ability): bool => ! method_exists($policy, $ability),
        ));

        if ($missing !== []) {
            $this->warnings[] = sprintf('%s has no %s — add them, or the Gate denies everyone (authorization.md).', $policy, implode(', ', array_map(fn (string $ability): string => $ability.'()', $missing)));
        }
    }

    /**
     * @param  list<string>  $methods
     */
    protected function routeLine(array $methods): string
    {
        $line = sprintf("Route::resource('%s', %s::class)", $this->prefix(), $this->controllerClass());

        if ($methods !== self::METHODS) {
            $line .= sprintf('->only([%s])', implode(', ', array_map(fn (string $method): string => "'{$method}'", $methods)));
        }

        return $line.';';
    }

    /**
     * Fill a stub with this run's shared values and the imports of the file being written.
     *
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements): string
    {
        $imports = array_values(array_unique(array_map(fn (string $class): string => ltrim($class, '\\'), $this->imports)));
        usort($imports, 'strcasecmp');

        $replacements = [
            '{{ imports }}' => implode("\n", array_map(fn (string $class): string => 'use '.$class.';', $imports)),
            ...$replacements,
            '{{ model }}' => $this->model(),
            '{{ variable }}' => $this->variable(),
            '{{ prefix }}' => $this->prefix(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $this->files->get(WorkflowKit::stubPath($stub)));
    }
}
