<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\BuildsRequestFields;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesGeneratedFiles;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * One action that is not create, edit or delete (actions.md), read off the Command that
 * `make:use-case {UseCase} --command` scaffolded and a human filled in: the invokable
 * `{Model}{Verb}Controller` (or `{Model}Bulk{Verb}Controller`), its `{Model}{Verb}Request` whose
 * `toCommand()` builds that Command, and the controller's test with a todo per case. The route,
 * the policy ability and the translations are printed, never edited in.
 */
#[Signature('make:action {verb : The action\'s verb, e.g. Cancel or ChangeStatus} {--model= : The model the action acts on, e.g. LotteryDraw} {--domain= : Context under App\Application that owns the use case} {--use-case= : The use case whose Command the request builds, the verb then the model by default} {--bulk : Write the bulk twin, which acts on the ids a list page selected}')]
#[Description('Create the controller, request and test of one non-CRUD action from its use case\'s Command')]
class MakeActionCommand extends Command implements PromptsForMissingInput
{
    use BuildsRequestFields;
    use ResolvesDomain;
    use WritesGeneratedFiles;

    /**
     * @var array<string, string> what every stub of this run shares
     */
    protected array $shared = [];

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->forgetResolvedDomain();
        $this->warnings = [];
        $this->langKeys = [];

        if (blank($this->option('model'))) {
            $this->components->error('Pass --model to name the model the action acts on.');

            return self::FAILURE;
        }

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        $command = $this->useCaseClass('Command');

        if (! class_exists($command)) {
            $this->components->error(sprintf(
                '[%s] does not exist. Run `php artisan make:use-case %s --domain=%s --command` and fill in its Command first.',
                $command,
                $this->useCase(),
                $this->resolveDomain(),
            ));

            return self::FAILURE;
        }

        if (! class_exists($this->modelClass())) {
            $this->warnings[] = sprintf('Model [%s] does not exist — the request authorizes against it and the route binds it.', $this->modelClass());
        }

        $handler = $this->useCaseClass('Handler');

        if (! class_exists($handler)) {
            $this->warnings[] = sprintf('[%s] does not exist yet — the controller calls it.', $handler);
        }

        $this->shared = [
            '{{ stem }}' => $this->stem(),
            '{{ model }}' => $this->model(),
            '{{ variable }}' => $this->variable(),
            '{{ command }}' => class_basename($command),
            '{{ handler }}' => class_basename($handler),
            '{{ handlerVariable }}' => Str::camel($this->useCase()),
            '{{ langPrefix }}' => $this->langPrefix(),
            '{{ uri }}' => $this->uri(),
            '{{ routeName }}' => $this->routeName(),
        ];

        $this->writeRequest($command);
        $this->writeController($handler, $this->counts($command, $handler));
        $this->writeTest($command, $handler);

        $this->warnAboutLangKeys();
        $this->warnAboutAbility();

        foreach (array_unique($this->warnings) as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info('Register the route, then run `php artisan wayfinder:generate --with-form --no-interaction`:');
        $this->line(sprintf(
            "    Route::post('%s', %sController::class)->name('%s');",
            $this->uri(),
            $this->stem(),
            $this->routeName(),
        ));

        return self::SUCCESS;
    }

    protected function domainSubject(): string
    {
        return 'action';
    }

    /**
     * `ChangeStatus`, whatever casing or suffix the caller typed.
     */
    protected function verb(): string
    {
        return (string) Str::of($this->argument('verb'))->trim()->studly()->chopEnd('Controller');
    }

    protected function model(): string
    {
        return Str::studly((string) $this->option('model'));
    }

    protected function bulk(): bool
    {
        return (bool) $this->option('bulk');
    }

    /**
     * `LotteryDrawCancel` or `LotteryDrawBulkCancel`: the controller and the request share it.
     */
    protected function stem(): string
    {
        return $this->model().($this->bulk() ? 'Bulk' : '').$this->verb();
    }

    protected function useCase(): string
    {
        return Str::studly($this->option('use-case') ?: $this->verb().$this->model());
    }

    protected function useCaseClass(string $suffix): string
    {
        return $this->laravel->getNamespace().'Application\\'.$this->resolveDomain().'\\UseCases\\'.$this->useCase().'\\'.$this->useCase().$suffix;
    }

    protected function modelClass(): string
    {
        return $this->laravel->getNamespace().'Models\\'.$this->model();
    }

    /**
     * `lotteryDraw`: the bound model's variable and accessor.
     */
    protected function variable(): string
    {
        return Str::camel($this->model());
    }

    /**
     * `lottery_draw`: the route parameter, as `Route::resource()` names it.
     */
    protected function parameter(): string
    {
        return Str::snake($this->model());
    }

    /**
     * `lottery-draws`: the uri's and the route name's prefix, and the translation file's.
     */
    protected function prefix(): string
    {
        return Str::kebab(Str::pluralStudly($this->model()));
    }

    protected function uri(): string
    {
        return $this->bulk()
            ? sprintf('%s/%s', $this->prefix(), Str::kebab($this->verb()))
            : sprintf('%s/{%s}/%s', $this->prefix(), $this->parameter(), Str::kebab($this->verb()));
    }

    protected function routeName(): string
    {
        return sprintf('%s.%s%s', $this->prefix(), $this->bulk() ? 'bulk-' : '', Str::kebab($this->verb()));
    }

    /**
     * `lottery-draws.cancel` or `lottery-draws.bulk_cancel`: what each toast key starts with.
     */
    protected function langPrefix(): string
    {
        return sprintf('%s.%s%s', $this->prefix(), $this->bulk() ? 'bulk_' : '', Str::snake($this->verb()));
    }

    /**
     * Whether the handler returns how many rows it changed: a bulk action always, a row action
     * when its handler (or, before the handler exists, its Command's `ids`) says so.
     */
    protected function counts(string $command, string $handler): bool
    {
        if ($this->bulk()) {
            return true;
        }

        if (method_exists($handler, '__invoke')) {
            $return = (new ReflectionMethod($handler, '__invoke'))->getReturnType();

            return $return instanceof ReflectionNamedType && $return->getName() === 'int';
        }

        return in_array('ids', $this->argumentNames($command), true);
    }

    /**
     * @return list<string>
     */
    protected function argumentNames(string $command): array
    {
        return array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod($command, '__construct'))->getParameters(),
        );
    }

    protected function writeRequest(string $command): void
    {
        $this->imports = [
            $command,
            'Illuminate\Contracts\Validation\ValidationRule',
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Support\Facades\Gate',
        ];

        $rules = [];
        $attributes = [];
        $arguments = [];
        $helpers = [];
        $ability = Str::camel($this->verb());

        if ($this->bulk()) {
            $this->imports[] = $this->modelClass();
            $authorization = sprintf("'%sAny', %s::class", $ability, $this->model());

            if (! in_array('ids', $this->argumentNames($command), true)) {
                $this->warnings[] = sprintf('%s has no $ids — a bulk action hands its handler the ids the list page selected.', class_basename($command));
            }
        } else {
            $this->imports[] = $this->modelClass();
            $this->imports[] = 'LogicException';
            $authorization = sprintf("'%s', \$this->%s()", $ability, $this->variable());
            $helpers[] = $this->render('', [], <<<'PHP'

    private function {{ variable }}(): {{ model }}
    {
        ${{ variable }} = $this->route('{{ parameter }}');

        if (! ${{ variable }} instanceof {{ model }}) {
            throw new LogicException('{{ stem }}Request is bound only to routes with a {{{ parameter }}} model.');
        }

        return ${{ variable }};
    }

PHP);
        }

        foreach ((new ReflectionMethod($command, '__construct'))->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (! $this->bulk() && in_array($name, ['id', $this->variable().'Id'], true)) {
                $arguments[] = sprintf('            %s: $this->%s()->id,', $name, $this->variable());

                continue;
            }

            if ($name === 'ids') {
                if (! $this->bulk()) {
                    $arguments[] = sprintf('            ids: [$this->%s()->id],', $this->variable());

                    continue;
                }

                $this->imports[] = 'Illuminate\Validation\Rule';
                $rules[] = "            'ids' => ['required', 'array', 'min:1'],";
                $rules[] = sprintf("            'ids.*' => ['uuid', 'distinct', Rule::exists('%s', 'id')],", $this->idsTable());
                $attributes[] = sprintf("            'ids' => __('%s'),", $this->lang($this->prefix().'.title'));
                $attributes[] = sprintf("            'ids.*' => __('%s'),", $this->prefix().'.title');
                $arguments[] = '            ids: $this->ids(),';
                $helpers[] = <<<'PHP'

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated('ids');

        return array_values($ids);
    }

PHP;

                continue;
            }

            $field = $this->fieldOf($parameter, $command);

            if ($field['kind'] === 'file') {
                $this->warnings[] = sprintf('%sRequest reads $%s through uploadedFile() — copy the helper make:form-request writes.', $this->stem(), $name);
            }

            $rules[] = sprintf("            '%s' => [%s],", $field['key'], implode(', ', $this->rulesOf($field)));
            $attributes[] = sprintf("            '%s' => __('%s'),", $field['key'], $this->lang($this->prefix().'.'.$field['key']));
            $arguments[] = $this->argumentOf($field);
        }

        $path = app_path('Http/Requests/'.$this->model().'/'.$this->stem().'Request.php');

        $this->writeFromStub('action-request.stub', $path, 'Request', [
            '{{ namespace }}' => $this->laravel->getNamespace().'Http\\Requests\\'.$this->model(),
            '{{ authorization }}' => $authorization,
            '{{ rules }}' => implode("\n", $rules),
            '{{ attributes }}' => implode("\n", $attributes),
            '{{ arguments }}' => implode("\n", $arguments),
            '{{ helpers }}' => implode('', $helpers),
        ]);
    }

    protected function writeController(string $handler, bool $counts): void
    {
        $this->imports = [
            $handler,
            $this->laravel->getNamespace().'Http\\FlashToast',
            $this->laravel->getNamespace().'Http\\Requests\\'.$this->model().'\\'.$this->stem().'Request',
            'Inertia\Inertia',
            'Symfony\Component\HttpFoundation\RedirectResponse',
        ];

        if ($this->bulk()) {
            $this->writeFromStub('action-bulk-controller.stub', $this->controllerPath(), 'Controller', [
                '{{ namespace }}' => $this->laravel->getNamespace().'Http\\Controllers',
            ]);

            return;
        }

        $this->imports[] = $this->modelClass();
        $this->lang($this->langPrefix().'_succeeded');

        $body = $counts
            ? <<<'PHP'
        if (${{ handlerVariable }}($request->toCommand()) === 0) {
            return Inertia::flash(
                FlashToast::KEY,
                FlashToast::error(__('{{ langPrefix }}_unchanged')),
            )->back();
        }

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('{{ langPrefix }}_succeeded')))->back();
PHP
            : <<<'PHP'
        ${{ handlerVariable }}($request->toCommand());

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('{{ langPrefix }}_succeeded')))->back();
PHP;

        if ($counts) {
            $this->lang($this->langPrefix().'_unchanged');
        }

        $this->writeFromStub('action-controller.stub', $this->controllerPath(), 'Controller', [
            '{{ namespace }}' => $this->laravel->getNamespace().'Http\\Controllers',
            '{{ body }}' => $this->render('', [], $body),
        ]);
    }

    protected function writeTest(string $command, string $handler): void
    {
        $hasFields = array_diff($this->argumentNames($command), ['id', 'ids', $this->variable().'Id']) !== [];

        $cases = $this->bulk()
            ? [
                "it('acts on every selected row and answers with a success toast')->todo();",
                "it('tells how many changed and how many were skipped')->todo();",
                "it('answers with an error toast when nothing changed')->todo();",
                "it('rejects each invalid field')->todo();",
            ]
            : array_values(array_filter([
                "it('acts for a user the policy allows and answers with a success toast')->todo();",
                $hasFields ? "it('rejects each invalid field')->todo();" : null,
                $this->counts($command, $handler) ? "it('answers with an error toast when the row had already changed')->todo();" : null,
                "it('answers with an error toast for each refusal the controller catches')->todo();",
            ]));

        $path = base_path('tests/Feature/Http/Controllers/'.$this->stem().'ControllerTest.php');

        $this->writeFromStub('action-test.stub', $path, 'Test', [
            '{{ cases }}' => implode("\n\n", $cases),
        ]);

        if ($this->bulk()) {
            foreach (['_succeeded', '_partial', '_unchanged'] as $suffix) {
                $this->lang($this->langPrefix().$suffix);
            }
        }
    }

    /**
     * The policy must answer the ability the request asks, or Laravel denies it silently.
     */
    protected function warnAboutAbility(): void
    {
        $policy = $this->laravel->getNamespace().'Policies\\'.$this->model().'Policy';
        $ability = Str::camel($this->verb()).($this->bulk() ? 'Any' : '');

        if (! method_exists($policy, $ability)) {
            $this->warnings[] = sprintf('%s has no %s() — add it, or the request denies everyone (authorization.md).', $policy, $ability);
        }
    }

    protected function controllerPath(): string
    {
        return app_path('Http/Controllers/'.$this->stem().'Controller.php');
    }

    /**
     * The table `ids.*` must exist in, read from the model when it loads.
     */
    protected function idsTable(): string
    {
        $model = $this->modelClass();

        if (class_exists($model) && is_subclass_of($model, Model::class)) {
            return (new $model)->getTable();
        }

        return Str::snake(Str::pluralStudly($this->model()));
    }

    /**
     * Remember a translation key for the missing-key report, and hand it back.
     */
    protected function lang(string $key): string
    {
        $this->langKeys[] = $key;

        return $key;
    }

    /**
     * Fill a stub file, or the template given, with this run's shared values and the imports
     * of the file being written.
     *
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements, ?string $template = null): string
    {
        $imports = array_values(array_unique($this->imports));
        usort($imports, 'strcasecmp');

        $replacements = [
            '{{ imports }}' => implode("\n", array_map(fn (string $class): string => 'use '.ltrim($class, '\\').';', $imports)),
            '{{ parameter }}' => $this->parameter(),
            ...$this->shared,
            ...$replacements,
        ];

        $contents = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template ?? $this->files->get(WorkflowKit::stubPath($stub)),
        );

        return str_replace(["return [\n\n        ];", "\n\n}\n"], ['return [];', "\n}\n"], $contents);
    }
}
