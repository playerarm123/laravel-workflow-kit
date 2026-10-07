<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesGeneratedFiles;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * The frontend half of a form page (form-pages.md), read off the `{X}FormValues` that
 * `make:form-request` scaffolded and a human filled in: the TypeScript type mirrors its
 * `toArray()`, the form component renders one uncontrolled input per key inside `<Form>`, and the
 * create and edit pages are shells that pass the server's `defaults` on.
 */
#[Signature('make:form-page {name : The aggregate the form writes, e.g. Customer}')]
#[Description('Create the form component, create/edit pages and TypeScript type of a form from its PHP form values')]
class MakeFormPageCommand extends Command implements PromptsForMissingInput
{
    use WritesGeneratedFiles;

    /**
     * Translation keys the generated files read under their prefix, besides one per field.
     */
    protected const array PAGE_LANG_KEYS = ['title', 'form_title', 'create_title', 'create_description', 'edit_title', 'edit_description'];

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->warnings = [];
        $this->langKeys = [];

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
            $this->lang($key);
        }

        $written = array_values(array_filter([
            $this->writeTypes($shape),
            $this->writeForm($shape),
            $this->writePage('create', ['create', 'index']),
            $this->writePage('edit', ['edit', 'index', 'show']),
        ]));

        $this->format($written);

        foreach (['create', 'edit'] as $page) {
            $this->writeFromStub("form-page-{$page}-test.stub", $this->browserTestPath($page), 'Browser test', []);
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
     * @param  array<string, string>  $shape
     */
    protected function writeForm(array $shape): ?string
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

        $imports = [
            '@inertiajs/react' => "import { Form, Link } from '@inertiajs/react';",
            '@/components/input-error' => "import InputError from '@/components/input-error';",
            '@/components/ui/button' => "import { Button } from '@/components/ui/button';",
            '@/components/ui/card' => "import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';",
            '@/components/ui/spinner' => "import { Spinner } from '@/components/ui/spinner';",
            '@/hooks/use-translation' => "import { useTranslation } from '@/hooks/use-translation';",
            '@/types' => sprintf("import type { %sFormValues } from '@/types';", $this->subject()),
            '@/wayfinder' => "import type { RouteDefinition, RouteFormDefinition } from '@/wayfinder';",
        ];

        foreach ($components as $component => $file) {
            $imports['@/components/ui/'.$file] = "import { {$component} } from '@/components/ui/{$file}';";
        }

        if ($state !== []) {
            $imports['react'] = "import { useState } from 'react';";
        }

        return $this->writeFromStub('form-page-form.stub', resource_path('js/components/'.$this->slug().'/form.tsx'), 'Form', [
            '{{ imports }}' => $this->importBlock($imports),
            '{{ state }}' => $state === [] ? '' : implode("\n", $state)."\n",
            '{{ fields }}' => implode("\n\n", $fields),
        ]);
    }

    /**
     * @param  list<string>  $routes  the Wayfinder route functions the page calls
     */
    protected function writePage(string $page, array $routes): ?string
    {
        sort($routes);

        return $this->writeFromStub("form-page-{$page}.stub", resource_path('js/pages/'.$this->prefix()."/{$page}.tsx"), 'Page', [
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
            ['index', 'show', 'create', 'store', 'edit', 'update'],
            fn (string $action): bool => ! Route::has($this->prefix().'.'.$action),
        ));

        if ($missing !== []) {
            $this->warnings[] = sprintf('Routes [%s] do not exist yet — register them, then run `php artisan wayfinder:generate`.', implode(', ', array_map(fn (string $action): string => $this->prefix().'.'.$action, $missing)));
        }

        $this->warnings[] = sprintf(
            "Render them with Inertia::render('%s/create', ['defaults' => %sFormValues::empty()]) and Inertia::render('%s/edit', ['%s' => \$%s, 'defaults' => %sFormValues::of(\$%s)]).",
            $this->prefix(),
            $this->subject(),
            $this->prefix(),
            $this->variable(),
            $this->variable(),
            $this->subject(),
            $this->variable(),
        );
    }
}
