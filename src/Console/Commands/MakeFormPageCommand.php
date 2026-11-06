<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ReadsDialogForms;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesGeneratedFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\FormDialogs;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * The frontend half of a form page (form-pages.md), read off the `{X}FormValues` that
 * `make:form-request` scaffolded and a human filled in: the TypeScript type mirrors its
 * `toArray()`, the form component renders one uncontrolled input per key inside `<Form>`, and the
 * create and edit pages are shells that pass the server's `defaults` on. `--dialog[=create,edit]`
 * puts the forms it names in a dialog of `components/{aggregate}/form-dialog.tsx` instead of a
 * page, and the form takes what a dialog needs to close (form-pages.md, "Form dialogs").
 */
#[Signature('make:form-page {name : The aggregate the form writes, e.g. Customer} {--dialog= : The forms to put in a dialog instead of a page, comma separated: create, edit (both when left empty)}')]
#[Description('Create the form component, create/edit pages or dialogs and TypeScript type of a form from its PHP form values')]
class MakeFormPageCommand extends Command implements PromptsForMissingInput
{
    use ReadsDialogForms;
    use WritesGeneratedFiles;

    /**
     * Translation keys the generated files read under their prefix, besides one per field.
     */
    protected const array PAGE_LANG_KEYS = ['title', 'form_title', 'create_title', 'create_description', 'edit_title', 'edit_description'];

    /**
     * The Browser test todos the index page's test gains for each dialog, which has no page of its
     * own to mirror (testing.md).
     */
    protected const array DIALOG_TODOS = [
        'create' => ['creates a row from the create dialog and keeps the list where it was'],
        'edit' => ['edits a row from the edit dialog and keeps the list where it was'],
        'both' => ['shows each invalid field under its input in the dialog'],
    ];

    /**
     * @var list<string> the forms this run puts in a dialog
     */
    protected array $dialogs = [];

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->warnings = [];
        $this->langKeys = [];

        $dialogs = $this->dialogFormsNamed();

        if ($dialogs === null) {
            return self::FAILURE;
        }

        $this->dialogs = $dialogs;
        $pages = array_values(array_diff(FormDialogs::forms(), $dialogs));

        if (! class_exists($this->namespacedFormValues())) {
            $this->components->error(sprintf(
                '[%s] does not exist. Run `php artisan make:form-request %s` and fill in its form values first.',
                $this->namespacedFormValues(),
                $this->subject(),
            ));

            return self::FAILURE;
        }

        $shape = $this->shapeOf($this->namespacedFormValues(), 'toArray');

        if ($shape === null) {
            return self::FAILURE;
        }

        foreach (self::PAGE_LANG_KEYS as $key) {
            if ($key !== 'form_title' || $pages !== []) {
                $this->lang($key);
            }
        }

        $written = array_values(array_filter([
            $this->writeTypes($shape),
            $this->writeForm($shape, $pages),
            in_array('create', $pages, true) ? $this->writePage('create', ['create', 'index']) : null,
            in_array('edit', $pages, true) ? $this->writePage('edit', ['edit', 'index', ...($this->hasShow() ? ['show'] : [])], [
                '{{ back }}' => $this->hasShow() ? 'show('.$this->variable().')' : 'index()',
            ]) : null,
            $dialogs === [] ? null : $this->writeDialogs(),
        ]));

        $this->format($written);

        foreach ($pages as $page) {
            $this->writeFromStub("form-page-{$page}-test.stub", $this->browserTestPath($page), 'Browser test', []);
        }

        if ($dialogs !== []) {
            $this->addDialogTodos();
            $this->warnAboutDialogPages();
            $this->explainDialogWiring();
        }

        $this->warnAboutRoutes();
        $this->warnAboutLangKeys();

        foreach (array_unique($this->warnings) as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
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
            ->chopEnd('FormValues')
            ->chopEnd('Form');
    }

    protected function namespacedFormValues(): string
    {
        return $this->laravel->getNamespace().'Http\\Requests\\'.$this->subject().'\\'.$this->subject().'FormValues';
    }

    /**
     * `lottery-types`: the page folder, the Wayfinder route module and the translation prefix.
     */
    protected function prefix(): string
    {
        return Str::kebab(Str::pluralStudly($this->subject()));
    }

    /**
     * `lottery-type`: the types file and the component folder.
     */
    protected function slug(): string
    {
        return Str::kebab($this->subject());
    }

    /**
     * `lotteryType`: the prop the edit page receives the row under.
     */
    protected function variable(): string
    {
        return Str::camel($this->subject());
    }

    /**
     * The page's Browser test, mirrored from its path (testing.md): `LotteryTypes/CreateTest.php`.
     */
    protected function browserTestPath(string $page): string
    {
        return base_path('tests/Browser/'.Str::pluralStudly($this->subject()).'/'.Str::studly($page).'Test.php');
    }

    /**
     * Add the form values type to the aggregate's types file, unless it already declares one.
     *
     * @param  array<string, string>  $shape
     */
    protected function writeTypes(array $shape): ?string
    {
        $path = resource_path('js/types/'.$this->slug().'.ts');
        $existing = $this->files->exists($path) ? $this->files->get($path) : null;
        $type = $this->subject().'FormValues';

        $this->registerTypesFile($this->slug());

        if ($existing !== null && $this->declaresType($existing, $type)) {
            $this->components->warn(sprintf('Types [%s] already declare %s.', $this->relativePath($path), $type));

            return null;
        }

        $block = $this->render('form-page-types.stub', [
            '{{ keys }}' => implode("\n", array_map(
                fn (string $key, string $tsType): string => "    {$key}: {$tsType};",
                array_keys($shape),
                $shape,
            )),
        ]);

        $this->files->put($path, ($existing === null ? '' : rtrim($existing)."\n\n").trim($block)."\n");

        $this->components->info(sprintf('Types [%s] %s successfully.', $this->relativePath($path), $existing === null ? 'created' : 'updated'));

        return $path;
    }

    /**
     * One uncontrolled input per key: a checkbox for a boolean (through hidden inputs, since
     * radix's is not an input), a file input for a key the server always sends null, a number
     * input for a number, and a text input for the rest.
     *
     * The stub follows where the form is used: a page only, a dialog only, or both, when one
     * form is a page and the other a dialog.
     *
     * @param  array<string, string>  $shape
     * @param  list<string>  $pages  the forms that keep a page of their own
     */
    protected function writeForm(array $shape, array $pages): ?string
    {
        $fields = [];
        $state = [];
        $components = ['Label' => 'label', 'Input' => 'input'];

        foreach ($shape as $key => $tsType) {
            $key = rtrim($key, '?');
            $label = $this->lang($key);

            if ($tsType === 'boolean') {
                $components['Checkbox'] = 'checkbox';
                $variable = Str::camel($key);
                $setter = 'set'.Str::studly($key);
                $state[] = "    const [{$variable}, {$setter}] = useState<boolean>(defaults.{$key});";
                $fields[] = <<<TSX
                            <div className="grid gap-2">
                                <div className="flex items-center gap-2">
                                    <input type="hidden" name="{$key}" value="0" />
                                    {{$variable} && <input type="hidden" name="{$key}" value="1" />}
                                    <Checkbox
                                        id="{$key}"
                                        checked={{$variable}}
                                        onCheckedChange={(checked) => {$setter}(checked === true)}
                                    />
                                    <Label htmlFor="{$key}">{t('{$label}')}</Label>
                                </div>
                                <InputError message={errors.{$key}} />
                            </div>
TSX;

                continue;
            }

            $input = match ($tsType) {
                'null' => "<Input id=\"{$key}\" name=\"{$key}\" type=\"file\" />",
                'number' => "<Input id=\"{$key}\" name=\"{$key}\" type=\"number\" defaultValue={defaults.{$key}} />",
                'string' => "<Input id=\"{$key}\" name=\"{$key}\" defaultValue={defaults.{$key}} />",
                'string | null' => "<Input id=\"{$key}\" name=\"{$key}\" defaultValue={defaults.{$key} ?? ''} />",
                default => null,
            };

            if ($input === null) {
                $this->warnings[] = sprintf('Field "%s" is `%s` — the form renders a text input for it; build its input by hand.', $key, $tsType);
                $input = "<Input id=\"{$key}\" name=\"{$key}\" />";
            }

            $fields[] = <<<TSX
                            <div className="grid gap-2">
                                <Label htmlFor="{$key}">{t('{$label}')}</Label>
                                {$input}
                                <InputError message={errors.{$key}} />
                            </div>
TSX;
        }

        $stub = match (true) {
            $this->dialogs === [] => 'form-page-form.stub',
            $pages === [] => 'form-page-form.dialog.stub',
            default => 'form-page-form.mixed.stub',
        };

        $imports = [
            '@inertiajs/react' => $pages === [] ? "import { Form } from '@inertiajs/react';" : "import { Form, Link } from '@inertiajs/react';",
            '@/components/input-error' => "import InputError from '@/components/input-error';",
            '@/components/ui/button' => "import { Button } from '@/components/ui/button';",
            '@/components/ui/card' => "import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';",
            '@/components/ui/spinner' => "import { Spinner } from '@/components/ui/spinner';",
            '@/hooks/use-translation' => "import { useTranslation } from '@/hooks/use-translation';",
            '@/types' => sprintf("import type { %sFormValues } from '@/types';", $this->subject()),
            '@/wayfinder' => $pages === [] ? "import type { RouteFormDefinition } from '@/wayfinder';" : "import type { RouteDefinition, RouteFormDefinition } from '@/wayfinder';",
        ];

        if ($pages === []) {
            unset($imports['@/components/ui/card']);
        }

        foreach ($components as $component => $file) {
            $imports['@/components/ui/'.$file] = "import { {$component} } from '@/components/ui/{$file}';";
        }

        if ($state !== []) {
            $imports['react'] = "import { useState } from 'react';";
        }

        return $this->writeFromStub($stub, resource_path('js/components/'.$this->slug().'/form.tsx'), 'Form', [
            '{{ imports }}' => $this->importBlock($imports),
            '{{ state }}' => $state === [] ? '' : implode("\n", $state)."\n",
            '{{ fields }}' => implode("\n\n", $fields),
        ]);
    }

    /**
     * `components/{aggregate}/form-dialog.tsx`: one dialog per form `--dialog` names, each wiring
     * the routes to the form inside the kit's `FormDialog`.
     */
    protected function writeDialogs(): ?string
    {
        if ($this->files->exists($this->dialogPath())) {
            return $this->addDialogs();
        }

        $dialogs = array_map(
            fn (string $form): string => trim($this->render("form-page-dialog-{$form}.stub", [])),
            $this->dialogs,
        );

        $hooks = array_map($this->dialogHook(...), $this->dialogs);

        return $this->writeFromStub('form-page-dialog.stub', $this->dialogPath(), 'Dialog', [
            '{{ imports }}' => $this->importBlock([
                '@/actions/App/Http/Controllers/'.$this->subject().'Controller' => sprintf("import %1\$sController from '@/actions/App/Http/Controllers/%1\$sController';", $this->subject()),
                '@/components/'.$this->slug().'/form' => sprintf("import { %sForm } from '@/components/%s/form';", $this->subject(), $this->slug()),
                '@/components/form-dialog' => "import { FormDialog } from '@/components/form-dialog';",
                '@/hooks/use-dialog' => sprintf("import type { %s } from '@/hooks/use-dialog';", implode(', ', $hooks)),
                '@/hooks/use-translation' => "import { useTranslation } from '@/hooks/use-translation';",
                '@/types' => sprintf("import type { %sFormValues } from '@/types';", $this->subject()),
            ]),
            '{{ dialogs }}' => implode("\n\n", $dialogs),
        ]);
    }

    /**
     * A `form-dialog.tsx` that is already there gains the dialogs `--dialog` names and it does
     * not export yet, below the ones it has, with their hook types in its `use-dialog` import.
     * What it already exports is left as it is.
     */
    protected function addDialogs(): ?string
    {
        $path = $this->dialogPath();
        $contents = $this->files->get($path);
        $missing = array_values(array_diff($this->dialogs, FormDialogs::exportedIn($contents, $this->subject())));

        if ($missing === []) {
            $this->components->warn(sprintf('Dialog [%s] already exists.', $this->relativePath($path)));

            return null;
        }

        $hooks = array_map($this->dialogHook(...), $missing);

        if (preg_match("/^import\\s+type\\s*\\{([^}]*)\\}\\s*from\\s*'@\\/hooks\\/use-dialog';/m", $contents, $import) === 1) {
            $names = array_values(array_unique([...array_filter(array_map('trim', explode(',', $import[1]))), ...$hooks]));
            sort($names);
            $contents = str_replace($import[0], sprintf("import type { %s } from '@/hooks/use-dialog';", implode(', ', $names)), $contents);
        } else {
            $contents = $this->addImport($contents, sprintf("import type { %s } from '@/hooks/use-dialog';", implode(', ', $hooks)), '@/hooks/use-dialog');
        }

        $dialogs = array_map(fn (string $form): string => trim($this->render("form-page-dialog-{$form}.stub", [])), $missing);
        $this->files->put($path, rtrim($contents)."\n\n".implode("\n\n", $dialogs)."\n");

        $this->components->info(sprintf('Dialog [%s] gained %s.', $this->relativePath($path), implode(', ', array_map(fn (string $form): string => FormDialogs::dialogOf($this->subject(), $form), $missing))));

        return $path;
    }

    /**
     * The `use-dialog` type a form's dialog takes: the create dialog opens empty, the edit one on
     * a row.
     */
    protected function dialogHook(string $form): string
    {
        return $form === 'create' ? 'Dialog' : 'ItemDialog';
    }

    protected function dialogPath(): string
    {
        return resource_path('js/components/'.$this->slug().'/form-dialog.tsx');
    }

    /**
     * A dialog has no page for a Browser test to mirror, so the list page's test, the page the
     * dialog opens on, gains its cases. Without that test there is nowhere to put them yet.
     */
    protected function addDialogTodos(): void
    {
        $path = $this->browserTestPath('index');
        $todos = [...array_merge(...array_map(fn (string $form): array => self::DIALOG_TODOS[$form], $this->dialogs)), ...self::DIALOG_TODOS['both']];

        if (! $this->files->exists($path)) {
            $this->warnings[] = sprintf('[%s] does not exist yet, so the dialog cases have nowhere to go. Once `make:list-page` writes it, add: %s', $this->relativePath($path), implode('; ', $todos));

            return;
        }

        $contents = $this->files->get($path);
        $missing = array_values(array_filter($todos, fn (string $todo): bool => ! str_contains($contents, "it('{$todo}')")));

        if ($missing === []) {
            return;
        }

        $this->files->put($path, rtrim($contents)."\n\n".implode("\n\n", array_map(fn (string $todo): string => "it('{$todo}')->todo();", $missing))."\n");

        $this->components->info(sprintf('Browser test [%s] gained the dialog cases.', $this->relativePath($path)));
    }

    /**
     * A form moved into a dialog leaves its page behind, which still posts and still has a test.
     */
    protected function warnAboutDialogPages(): void
    {
        $form = resource_path('js/components/'.$this->slug().'/form.tsx');

        if ($this->files->exists($form) && ! str_contains($this->files->get($form), 'onSuccess')) {
            $this->warnings[] = sprintf('[%s] was written for a page, so a dialog cannot close it. Give it the onCancel and onSuccess a dialog passes (form-pages.md, "Form dialogs").', $this->relativePath($form));
        }

        foreach ($this->dialogs as $form) {
            $page = resource_path('js/pages/'.$this->prefix()."/{$form}.tsx");

            if ($this->files->exists($page)) {
                $this->warnings[] = sprintf('[%s] still exists, but %s now lives in a dialog. Delete the page and its Browser test once the list opens the dialog.', $this->relativePath($page), $form);
            }
        }
    }

    /**
     * The list page renders the dialogs itself, beside `<DataTable>` (list-pages.md).
     */
    protected function explainDialogWiring(): void
    {
        $this->components->info(sprintf('Open the dialogs from resources/js/pages/%s/index.tsx:', $this->prefix()));

        $lines = [sprintf("import { %s } from '@/components/%s/form-dialog';", implode(', ', array_map(fn (string $form): string => Str::studly($form).$this->subject().'Dialog', $this->dialogs)), $this->slug())];

        if (in_array('create', $this->dialogs, true)) {
            $lines[] = 'const createDialog = useDialog();';
            $lines[] = 'headerActions={can.create ? <Button onClick={createDialog.open}><Plus />{t(\''.$this->prefix().'.create\')}</Button> : undefined}';
            $lines[] = sprintf('<Create%sDialog dialog={createDialog} />', $this->subject());
        }

        if (in_array('edit', $this->dialogs, true)) {
            $lines[] = 'const editDialog = useItemDialog<Row>();';
            $lines[] = 'actions.edit.dialog({ dialog: editDialog, visible: can.update })';
            $lines[] = sprintf('<Edit%sDialog dialog={editDialog} />', $this->subject());
        }

        foreach ($lines as $line) {
            $this->line('    '.$line);
        }
    }

    /**
     * Whether the resource has a page of its own to go back to: its `show` route is registered, or
     * its HTTP manifest (structure.md) lists a `show` method still to build. Without one, the edit
     * page goes back to the list, so it never imports a route Wayfinder does not write.
     */
    protected function hasShow(): bool
    {
        if (Route::has($this->prefix().'.show')) {
            return true;
        }

        $files = new StructureFiles(base_path());
        $manifest = $files->resourceExists($this->subject()) ? $files->readResource($this->subject()) : null;

        return is_array($manifest) && is_array($manifest['controller'] ?? null) && array_key_exists('show', $manifest['controller']);
    }

    /**
     * @param  list<string>  $routes  the Wayfinder route functions the page calls
     * @param  array<string, string>  $replacements
     */
    protected function writePage(string $page, array $routes, array $replacements = []): ?string
    {
        sort($routes);

        return $this->writeFromStub("form-page-{$page}.stub", resource_path('js/pages/'.$this->prefix()."/{$page}.tsx"), 'Page', [
            ...$replacements,
            '{{ imports }}' => $this->importBlock([
                '@inertiajs/react' => "import { Head, Link, setLayoutProps } from '@inertiajs/react';",
                'lucide-react' => "import { ArrowLeft } from 'lucide-react';",
                '@/actions/App/Http/Controllers/'.$this->subject().'Controller' => sprintf("import %1\$sController from '@/actions/App/Http/Controllers/%1\$sController';", $this->subject()),
                '@/components/'.$this->slug().'/form' => sprintf("import { %sForm } from '@/components/%s/form';", $this->subject(), $this->slug()),
                '@/components/heading' => "import Heading from '@/components/heading';",
                '@/components/ui/button' => "import { Button } from '@/components/ui/button';",
                '@/hooks/use-translation' => "import { useTranslation } from '@/hooks/use-translation';",
                '@/routes/'.$this->prefix() => sprintf("import { %s } from '@/routes/%s';", implode(', ', $routes), $this->prefix()),
                '@/types' => sprintf("import type { %sFormValues } from '@/types';", $this->subject()),
            ]),
        ]);
    }

    /**
     * The imports in import/order's order: packages first, then `@/` aliases, each alphabetical.
     *
     * @param  array<string, string>  $imports  keyed by module
     */
    protected function importBlock(array $imports): string
    {
        uksort($imports, fn (string $a, string $b): int => [str_starts_with($a, '@/'), strtolower($a)] <=> [str_starts_with($b, '@/'), strtolower($b)]);

        return implode("\n", $imports);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements): string
    {
        $replacements = [
            '{{ subject }}' => $this->subject(),
            '{{ words }}' => Str::lower(Str::headline($this->subject())),
            '{{ prefix }}' => $this->prefix(),
            '{{ variable }}' => $this->variable(),
            '{{ namespacedFormValues }}' => $this->namespacedFormValues(),
            ...$replacements,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $this->files->get(WorkflowKit::stubPath($stub)));
    }

    /**
     * The translation key under the page's prefix, remembered for the missing-key report.
     */
    protected function lang(string $key): string
    {
        $key = $this->prefix().'.'.$key;
        $this->langKeys[] = $key;

        return $key;
    }

    /**
     * The pages reach their URLs through Wayfinder's `@/routes/{prefix}` and the controller's
     * actions, which exist only once the routes do and `wayfinder:generate` has run.
     */
    protected function warnAboutRoutes(): void
    {
        $missing = array_values(array_filter(
            ['index', ...($this->hasShow() ? ['show'] : []), 'create', 'store', 'edit', 'update'],
            fn (string $action): bool => ! Route::has($this->prefix().'.'.$action),
        ));

        if ($missing !== []) {
            $this->warnings[] = sprintf('Routes [%s] do not exist yet — register them, then run `php artisan wayfinder:generate`.', implode(', ', array_map(fn (string $action): string => $this->prefix().'.'.$action, $missing)));
        }

        $answers = [
            'create' => in_array('create', $this->dialogs, true)
                ? sprintf("create() with response()->json(['defaults' => %sFormValues::empty()])", $this->subject())
                : sprintf("create() with Inertia::render('%s/create', ['defaults' => %sFormValues::empty()])", $this->prefix(), $this->subject()),
            'edit' => in_array('edit', $this->dialogs, true)
                ? sprintf("edit() with response()->json(['defaults' => %sFormValues::of(\$%s)])", $this->subject(), $this->variable())
                : sprintf("edit() with Inertia::render('%s/edit', ['%s' => \$%s, 'defaults' => %sFormValues::of(\$%s)])", $this->prefix(), $this->variable(), $this->variable(), $this->subject(), $this->variable()),
        ];

        $this->warnings[] = sprintf('Answer %s and %s.', $answers['create'], $answers['edit']);

        if ($this->dialogs !== []) {
            $this->warnings[] = sprintf('A dialog saves through store or update that ends with ->back(): `php artisan make:controller %s --dialog=%s` writes them so.', $this->subject(), implode(',', $this->dialogs));
        }
    }
}
