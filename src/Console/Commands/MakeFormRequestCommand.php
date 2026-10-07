<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\BuildsRequestFields;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesGeneratedFiles;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The server half of a form page (form-pages.md), read off the Commands that
 * `make:use-case Create{X} --command --creates` and `Update{X} --command` scaffolded and a human
 * filled in: one `Validates{X}` trait with the rules, `Store{X}Request` and `Update{X}Request` whose
 * `toCommand()` build those Commands, and `{X}FormValues` with one key per rule. Every rule is a
 * first guess from the argument's type, which a human tightens.
 */
#[Signature('make:form-request {name : The aggregate the form writes, e.g. Customer} {--domain= : Context under App\Application that owns the create and update use cases} {--create= : The create use case, Create{name} by default} {--update= : The update use case, Update{name} by default}')]
#[Description('Create the FormRequests and form values of a create/edit form from its Commands')]
class MakeFormRequestCommand extends Command implements PromptsForMissingInput
{
    use BuildsRequestFields;
    use ResolvesDomain;
    use WritesGeneratedFiles;

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->forgetResolvedDomain();
        $this->warnings = [];
        $this->langKeys = [];

        if ($this->resolveDomain() === null) {
            $this->reportMissingDomain();

            return self::FAILURE;
        }

        $create = $this->commandClass($this->createUseCase());

        if (! class_exists($create)) {
            $this->components->error(sprintf(
                '[%s] does not exist. Run `php artisan make:use-case %s --domain=%s --command --creates` and fill in its Command first.',
                $create,
                $this->createUseCase(),
                $this->resolveDomain(),
            ));

            return self::FAILURE;
        }

        if (! class_exists($this->modelClass())) {
            $this->warnings[] = sprintf('Model [%s] does not exist — the requests authorize against it and the form values read it.', $this->modelClass());
        }

        $fields = $this->fieldsOf($create);
        $update = $this->commandClass($this->updateUseCase());

        $this->writeFields($fields);
        $this->writeStore($create, $fields);

        if (class_exists($update)) {
            $this->writeUpdate($update);
        } else {
            $this->warnings[] = sprintf('[%s] does not exist, so no Update%sRequest was written — run `php artisan make:use-case %s --domain=%s --command` and this command again.', $update, $this->subject(), $this->updateUseCase(), $this->resolveDomain());
        }

        $this->writeFormValues($fields);

        $this->warnAboutLangKeys();

        foreach (array_unique($this->warnings) as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }

    protected function domainSubject(): string
    {
        return 'form';
    }

    /**
     * `Customer`, whatever suffix or path the caller typed.
     */
    protected function subject(): string
    {
        return (string) Str::of($this->argument('name'))
            ->trim()
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopEnd('Request')
            ->chopStart(['Store', 'Update', 'Create']);
    }

    protected function createUseCase(): string
    {
        return Str::studly($this->option('create') ?: 'Create'.$this->subject());
    }

    protected function updateUseCase(): string
    {
        return Str::studly($this->option('update') ?: 'Update'.$this->subject());
    }

    protected function commandClass(string $useCase): string
    {
        return $this->laravel->getNamespace().'Application\\'.$this->resolveDomain().'\\UseCases\\'.$useCase.'\\'.$useCase.'Command';
    }

    protected function modelClass(): string
    {
        return $this->laravel->getNamespace().'Models\\'.$this->subject();
    }

    protected function requestNamespace(): string
    {
        return $this->laravel->getNamespace().'Http\\Requests\\'.$this->subject();
    }

    protected function requestPath(string $class): string
    {
        return app_path('Http/Requests/'.$this->subject().'/'.$class.'.php');
    }

    /**
     * `customer`: the bound model's variable and accessor.
     */
    protected function variable(): string
    {
        return Str::camel($this->subject());
    }

    /**
     * `lottery_type`: the route parameter `Route::resource()` binds.
     */
    protected function parameter(): string
    {
        return Str::snake($this->subject());
    }

    /**
     * `lottery-types`: the translation prefix the pages use too.
     */
    protected function prefix(): string
    {
        return Str::kebab(Str::pluralStudly($this->subject()));
    }

    /**
     * One field per argument of the Command, the id of the row it changes left out.
     *
     * @return list<array{argument: string, key: string, kind: string, class: string|null, nullable: bool}>
     */
    protected function fieldsOf(string $command): array
    {
        $fields = [];

        foreach ((new ReflectionMethod($command, '__construct'))->getParameters() as $parameter) {
            if ($this->isTargetId($parameter)) {
                continue;
            }

            $fields[] = $this->fieldOf($parameter, $command);
        }

        return $fields;
    }

    protected function isTargetId(ReflectionParameter $parameter): bool
    {
        return in_array($parameter->getName(), ['id', $this->variable().'Id'], true);
    }

    /**
     * @param  list<array{argument: string, key: string, kind: string, class: string|null, nullable: bool}>  $fields
     */
    protected function writeFields(array $fields): void
    {
        $this->imports = [
            'Illuminate\Contracts\Validation\ValidationRule',
            'Illuminate\Foundation\Http\FormRequest',
        ];

        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $rules[] = sprintf("            '%s' => [%s],", $field['key'], implode(', ', $this->rulesOf($field)));
            $attributes[] = sprintf("            '%s' => __('%s'),", $field['key'], $this->lang($field['key']));
        }

        $helpers = '';

        if (in_array('file', array_column($fields, 'kind'), true)) {
            $this->imports[] = UploadedFile::class;
            $this->imports[] = 'LogicException';
            $helpers = <<<'PHP'

    protected function uploadedFile(string $key): UploadedFile
    {
        $file = $this->file($key);

        if (! $file instanceof UploadedFile) {
            throw new LogicException("The validated [{$key}] is not one uploaded file.");
        }

        return $file;
    }

PHP;
        }

        $this->writeFromStub('form-request-fields.stub', $this->requestPath('Validates'.$this->subject()), 'Trait', [
            '{{ rules }}' => implode("\n", $rules),
            '{{ attributes }}' => implode("\n", $attributes),
            '{{ helpers }}' => $helpers,
        ]);
    }

    /**
     * @param  list<array{argument: string, key: string, kind: string, class: string|null, nullable: bool}>  $fields
     */
    protected function writeStore(string $command, array $fields): void
    {
        $this->imports = [
            $command,
            $this->modelClass(),
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Support\Facades\Gate',
        ];

        $this->writeFromStub('form-request-store.stub', $this->requestPath('Store'.$this->subject().'Request'), 'Request', [
            '{{ arguments }}' => implode("\n", array_map($this->argumentOf(...), $fields)),
            '{{ command }}' => class_basename($command),
        ]);
    }

    protected function writeUpdate(string $command): void
    {
        $this->imports = [
            $command,
            $this->modelClass(),
            'Illuminate\Foundation\Http\FormRequest',
            'Illuminate\Support\Facades\Gate',
            'LogicException',
        ];

        $arguments = [];

        foreach ((new ReflectionMethod($command, '__construct'))->getParameters() as $parameter) {
            $arguments[] = $this->isTargetId($parameter)
                ? sprintf('            %s: $this->%s()->id,', $parameter->getName(), $this->variable())
                : $this->argumentOf($this->fieldOf($parameter, $command));
        }

        $this->writeFromStub('form-request-update.stub', $this->requestPath('Update'.$this->subject().'Request'), 'Request', [
            '{{ arguments }}' => implode("\n", $arguments),
            '{{ command }}' => class_basename($command),
        ]);
    }

    /**
     * @param  list<array{argument: string, key: string, kind: string, class: string|null, nullable: bool}>  $fields
     */
    protected function writeFormValues(array $fields): void
    {
        $this->imports = [$this->modelClass(), 'Illuminate\Contracts\Support\Arrayable', 'LogicException'];

        $properties = [];
        $empty = [];
        $shape = [];
        $entries = [];
        $arrays = [];

        foreach ($fields as $field) {
            [$type, $blank, $shapeType] = match ($field['kind']) {
                'int' => ['int', '0', 'int'],
                'bool' => ['bool', 'false', 'bool'],
                'array' => ['array', '[]', 'list<string>'],
                'file' => [null, null, 'null'],
                default => ['string', "''", 'string'],
            };

            $shape[] = "{$field['key']}: {$shapeType}";

            if ($type === null) {
                $entries[] = sprintf("            '%s' => null,", $field['key']);

                continue;
            }

            if ($type === 'array') {
                $arrays[] = sprintf('     * @param  list<string>  $%s', $field['argument']);
            }

            $properties[] = sprintf('        public readonly %s $%s,', $type, $field['argument']);
            $empty[] = sprintf('            %s: %s,', $field['argument'], $blank);
            $entries[] = sprintf("            '%s' => \$this->%s,", $field['key'], $field['argument']);
        }

        $this->writeFromStub('form-values.stub', $this->requestPath($this->subject().'FormValues'), 'Form values', [
            '{{ constructorDoc }}' => $arrays === [] ? '' : "    /**\n".implode("\n", $arrays)."\n     */\n",
            '{{ properties }}' => implode("\n", $properties),
            '{{ emptyArguments }}' => implode("\n", $empty),
            '{{ shape }}' => implode(', ', $shape),
            '{{ entries }}' => implode("\n", $entries),
        ]);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements): string
    {
        $imports = array_values(array_unique($this->imports));
        usort($imports, 'strcasecmp');

        $replacements = [
            '{{ namespace }}' => $this->requestNamespace(),
            '{{ imports }}' => implode("\n", array_map(fn (string $class): string => 'use '.ltrim($class, '\\').';', $imports)),
            '{{ subject }}' => $this->subject(),
            '{{ words }}' => Str::lower(Str::headline($this->subject())),
            '{{ model }}' => class_basename($this->modelClass()),
            '{{ variable }}' => $this->variable(),
            '{{ parameter }}' => $this->parameter(),
            ...$replacements,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $this->files->get(WorkflowKit::stubPath($stub)));
    }

    /**
     * The translation key of a field's label, remembered for the missing-key report.
     */
    protected function lang(string $key): string
    {
        $key = $this->prefix().'.'.$key;
        $this->langKeys[] = $key;

        return $key;
    }
}
