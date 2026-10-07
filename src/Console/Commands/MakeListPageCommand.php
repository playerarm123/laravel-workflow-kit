<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use BackedEnum;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\ResolvesDomain;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesGeneratedFiles;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The frontend half of a list page (list-pages.md), read off the PHP half that
 * `make:use-case List{Name} --query` scaffolded and a human filled in: the row type mirrors
 * `{Item}ListRow::toArray()`, the filter type mirrors `List{Name}Criteria::toFilters()`, and
 * the toolbar offers every backed enum filter's cases. Both PHP methods are read through their
 * `@return array{…}` shape, which is the one place that names a key together with its type.
 *
 * `--grid` writes the other shape of list-pages.md instead: cards loaded as the user scrolls
 * (`Inertia::scroll()`), with no sort and no page size, so no `{Items}Query` type, and a card
 * component the page maps over.
 *
 * `--types-only` writes the TypeScript twins alone, for a list that replaces another behind a page
 * that already exists (structure.md): kit:apply then points that page at them.
 */
#[Signature('make:list-page {name : The list use case, e.g. ListLotteryTypes} {--domain= : Context under App\Application that owns the list use case} {--grid : Write a grid of cards loaded as the user scrolls instead of a table} {--types-only : Write only the TypeScript types, for a page that already exists}')]
#[Description('Create the table or grid page, toolbar and TypeScript types of a list from its PHP row and criteria')]
class MakeListPageCommand extends Command implements PromptsForMissingInput
{
    use ResolvesDomain;
    use WritesGeneratedFiles;

    /**
     * The keys every filter set already carries through `DtFilters`.
     */
    protected const array SHARED_FILTER_KEYS = ['search', 'created_from', 'created_to'];

    /**
     * Translation keys the generated page reads under its prefix, besides columns and filters.
     */
    protected const array PAGE_LANG_KEYS = [
        'title', 'description', 'search_placeholder',
        'empty_title', 'empty_description', 'no_results_title', 'no_results_description',
    ];

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

        foreach ([$this->namespacedRow(), $this->namespacedCriteria()] as $class) {
            if (! class_exists($class)) {
                $this->components->error(sprintf(
                    '[%s] does not exist. Run `php artisan make:use-case %s --domain=%s --command --result --query` and fill in its row and criteria first.',
                    $class,
                    $this->useCaseName(),
                    $this->resolveDomain(),
                ));

                return self::FAILURE;
            }
        }

        $row = $this->shapeOf($this->namespacedRow(), 'toArray');
        $filters = $this->shapeOf($this->namespacedCriteria(), 'toFilters');

        if ($row === null || $filters === null) {
            return self::FAILURE;
        }

        if ($this->option('types-only')) {
            $types = $this->writeTypes($row, $filters);
            $this->format($types === null ? [] : [$types]);

            foreach ($this->warnings as $warning) {
                $this->components->warn($warning);
            }

            return self::SUCCESS;
        }

        $written = array_values(array_filter([
            $this->writeTypes($row, $filters),
            $this->writeToolbar($filters),
            $this->isGrid() ? $this->writeCard($row) : null,
            $this->writePage($row),
        ]));

        $this->format($written);

        $this->writeFromStub($this->isGrid() ? 'list-page-grid-test.stub' : 'list-page-test.stub', $this->browserTestPath(), 'Browser test', []);

        $this->warnAboutRoute();
        $this->warnAboutLangKeys();

        foreach ($this->warnings as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }

    protected function isGrid(): bool
    {
        return (bool) $this->option('grid');
    }

    protected function domainSubject(): string
    {
        return 'list page';
    }

    /**
     * The use case name, `List` prefixed when the caller named only the subject.
     */
    protected function useCaseName(): string
    {
        $name = (string) Str::of($this->argument('name'))
            ->trim()
            ->replace('/', '\\')
            ->afterLast('\\')
            ->studly()
            ->chopEnd('Handler');

        return str_starts_with($name, 'List') && strlen($name) > 4 ? $name : 'List'.$name;
    }

    /**
     * What the list lists, plural: `ListLotteryTypes` → `LotteryTypes`.
     */
    protected function subjects(): string
    {
        return substr($this->useCaseName(), 4);
    }

    /**
     * One of what the list lists: `LotteryType`.
     */
    protected function item(): string
    {
        return Str::singular($this->subjects());
    }

    protected function namespacedRow(): string
    {
        return $this->useCaseNamespace().'\\'.$this->item().'ListRow';
    }

    protected function namespacedCriteria(): string
    {
        return $this->useCaseNamespace().'\\'.$this->useCaseName().'Criteria';
    }

    protected function useCaseNamespace(): string
    {
        return $this->laravel->getNamespace().'Application\\'.$this->resolveDomain().'\\UseCases\\'.$this->useCaseName();
    }

    protected function rowType(): string
    {
        return $this->item().'Row';
    }

    protected function filtersType(): string
    {
        return $this->item().'Filters';
    }

    protected function queryType(): string
    {
        return $this->subjects().'Query';
    }

    protected function toolbarName(): string
    {
        return $this->item().'TableToolbar';
    }

    protected function cardName(): string
    {
        return $this->item().'Card';
    }

    /**
     * `lotteryType`: the card's prop and the grid's map variable.
     */
    protected function itemVariable(): string
    {
        return Str::camel($this->item());
    }

    /**
     * `lottery-types`: the page folder, the Wayfinder route module and the translation prefix.
     */
    protected function prefix(): string
    {
        return Str::kebab($this->subjects());
    }

    /**
     * `lottery-type`: the types file and the component folder.
     */
    protected function itemSlug(): string
    {
        return Str::kebab($this->item());
    }

    /**
     * `lotteryTypes`: the prop the controller sends the paginator under.
     */
    protected function prop(): string
    {
        return Str::camel($this->subjects());
    }

    /**
     * The page's Browser test, mirrored from its path (testing.md): `LotteryTypes/IndexTest.php`.
     */
    protected function browserTestPath(): string
    {
        return base_path('tests/Browser/'.$this->subjects().'/IndexTest.php');
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string> the filter keys that belong to the list, not to every list
     */
    protected function domainFilterKeys(array $filters): array
    {
        return array_values(array_diff(array_keys($filters), self::SHARED_FILTER_KEYS));
    }

    /**
     * Write the three types into the item's types file, adding only the ones it lacks so an
     * existing file keeps its enums and detail types. A grid has no sort or page size, so it
     * gets no `{Items}Query`.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $filters
     */
    protected function writeTypes(array $row, array $filters): ?string
    {
        $path = resource_path('js/types/'.$this->itemSlug().'.ts');
        $existing = $this->files->exists($path) ? $this->files->get($path) : null;

        $domainKeys = $this->domainFilterKeys($filters);
        $filtersBody = $domainKeys === []
            ? 'DtFilters'
            : "DtFilters & {\n".implode("\n", array_map(fn (string $key): string => "    {$key}: string | null;", $domainKeys))."\n}";

        $rendered = $this->render('list-page-types.stub', [
            '{{ namespacedRow }}' => $this->namespacedRow(),
            '{{ namespacedCriteria }}' => $this->namespacedCriteria(),
            '{{ rowKeys }}' => implode("\n", array_map(
                fn (string $key, string $type): string => "    {$key}: {$type};",
                array_keys($row),
                $row,
            )),
            '{{ filtersBody }}' => $filtersBody,
        ]);

        $blocks = array_filter(
            explode("\n\n", trim($rendered)),
            fn (string $block): bool => ! ($this->isGrid() && $this->typeNameOf($block) === $this->queryType())
                && ($existing === null || ! $this->declaresType($existing, $this->typeNameOf($block))),
        );

        $this->registerTypesFile($this->itemSlug());

        if ($blocks === []) {
            $this->components->warn(sprintf('Types [%s] already declare %s.', $this->relativePath($path), implode(', ', $this->typeNames())));

            return null;
        }

        $contents = $existing ?? '';

        $imports = $this->isGrid()
            ? ['DtFilters' => './data-table']
            : ['DtQuery' => '@/hooks/use-data-table', 'DtFilters' => './data-table'];

        foreach ($imports as $name => $module) {
            if (! preg_match('/^import\s+type\s*\{[^}]*\b'.$name.'\b[^}]*\}/m', $contents)) {
                $contents = $this->addImport($contents, "import type { {$name} } from '{$module}';", $module);
            }
        }

        $contents = rtrim($contents)."\n\n".implode("\n\n", $blocks)."\n";

        $this->files->put($path, $contents);

        $this->components->info(sprintf('Types [%s] %s successfully.', $this->relativePath($path), $existing === null ? 'created' : 'updated'));

        return $path;
    }

    /**
     * @return list<string> the types the list's types file declares
     */
    protected function typeNames(): array
    {
        return $this->isGrid()
            ? [$this->rowType(), $this->filtersType()]
            : [$this->rowType(), $this->filtersType(), $this->queryType()];
    }

    /**
     * A table's toolbar reads the table (`DataTableInstance<Row, Query>`); a grid's reads the
     * list query alone (`ListQuery<Filters>`), because a grid has no sort or page size.
     *
     * @param  array<string, string>  $filters
     */
    protected function writeToolbar(array $filters): ?string
    {
        return $this->writeFromStub(
            $this->isGrid() ? 'list-page-grid-toolbar.stub' : 'list-page-toolbar.stub',
            resource_path('js/components/'.$this->itemSlug().'/table-toolbar.tsx'),
            'Toolbar',
            [
                '{{ typeImports }}' => $this->sortedNames([$this->rowType(), $this->queryType()]),
                '{{ fields }}' => implode("\n", $this->toolbarFields($this->domainFilterKeys($filters))),
            ],
        );
    }

    /**
     * One toolbar field per domain filter: a `{x}_from`/`{x}_to` pair is one date range, a
     * backed enum is a Select of its cases, and anything else is a Select left for a human.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function toolbarFields(array $keys): array
    {
        $parameters = [];

        foreach ((new ReflectionMethod($this->namespacedCriteria(), '__construct'))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter->getType();
        }

        $fields = [];

        foreach ($keys as $key) {
            if (str_ends_with($key, '_to') && in_array(substr($key, 0, -3).'_from', $keys, true)) {
                continue;
            }

            if (str_ends_with($key, '_from') && in_array(substr($key, 0, -5).'_to', $keys, true)) {
                $label = $this->lang('filter_'.substr($key, 0, -5));
                $fields[] = sprintf("    { type: 'date-range', fromKey: '%s', toKey: '%s', label: '%s' },", $key, substr($key, 0, -5).'_to', $label);

                continue;
            }

            $type = $parameters[Str::camel($key)] ?? null;
            $enum = $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), BackedEnum::class) ? $type->getName() : null;

            $options = [];

            if ($enum === null) {
                $this->warnings[] = sprintf('Filter "%s" is not a backed enum on %s — fill its options in the toolbar by hand.', $key, class_basename($this->namespacedCriteria()));
            } else {
                foreach ($enum::cases() as $case) {
                    $value = addcslashes((string) $case->value, "'\\");
                    $options[] = sprintf("{ value: '%s', label: '%s' }", $value, $this->lang($key.'.'.$value));
                }
            }

            $fields[] = sprintf("    { key: '%s', label: '%s', options: [%s] },", $key, $this->lang('filter_'.$key), implode(', ', $options));
        }

        return $fields;
    }

    /**
     * The grid's card: one label and value per row key, `id` aside. The value is the raw one;
     * a human formats money, rates and dates through the kit.
     *
     * @param  array<string, string>  $row
     */
    protected function writeCard(array $row): ?string
    {
        $fields = [];

        foreach ($row as $key => $type) {
            $key = rtrim($key, '?');

            if ($key === 'id') {
                continue;
            }

            $value = sprintf(str_contains($type, 'null') ? '%s.%s ?? \'\'' : '%s.%s', $this->itemVariable(), $key);

            $fields[] = sprintf('                    <dt className="text-muted-foreground">{t(\'%s\')}</dt>', $this->lang($key));
            $fields[] = sprintf('                    <dd>{String(%s)}</dd>', $value);
        }

        return $this->writeFromStub(
            'list-page-grid-card.stub',
            resource_path('js/components/'.$this->itemSlug().'/card.tsx'),
            'Card',
            ['{{ fields }}' => implode("\n", $fields)],
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function writePage(array $row): ?string
    {
        foreach (self::PAGE_LANG_KEYS as $key) {
            $this->lang($key);
        }

        if ($this->isGrid()) {
            return $this->writeGridPage();
        }

        $componentImports = [
            '@/components/dt-table' => "import DataTable from '@/components/dt-table';",
            '@/components/'.$this->itemSlug().'/table-toolbar' => sprintf("import { %s } from '@/components/%s/table-toolbar';", $this->toolbarName(), $this->itemSlug()),
        ];
        uksort($componentImports, 'strcasecmp');

        $columns = array_map(
            fn (string $key): string => sprintf("        columnHelper.accessor('%s', { header: '%s' }),", $key, $this->lang($key)),
            array_values(array_filter(array_map(fn (string $key): string => rtrim($key, '?'), array_keys($row)), fn (string $key): bool => $key !== 'id')),
        );

        return $this->writeFromStub(
            'list-page.stub',
            resource_path('js/pages/'.$this->prefix().'/index.tsx'),
            'Page',
            [
                '{{ componentImports }}' => implode("\n", $componentImports),
                '{{ typeImports }}' => $this->sortedNames([$this->filtersType(), $this->rowType(), $this->queryType(), 'Paginated', 'SortState']),
                '{{ columns }}' => implode("\n", $columns),
            ],
        );
    }

    /**
     * The grid shape: `useListQuery` + `<InfiniteScroll>` + the toolbar, one card per row.
     */
    protected function writeGridPage(): ?string
    {
        $componentImports = [
            '@/components/'.$this->itemSlug().'/card' => sprintf("import { %s } from '@/components/%s/card';", $this->cardName(), $this->itemSlug()),
            '@/components/'.$this->itemSlug().'/table-toolbar' => sprintf("import { %s } from '@/components/%s/table-toolbar';", $this->toolbarName(), $this->itemSlug()),
            '@/components/heading' => "import Heading from '@/components/heading';",
            '@/components/ui/spinner' => "import { Spinner } from '@/components/ui/spinner';",
        ];
        uksort($componentImports, 'strcasecmp');

        return $this->writeFromStub(
            'list-page-grid.stub',
            resource_path('js/pages/'.$this->prefix().'/index.tsx'),
            'Page',
            [
                '{{ componentImports }}' => implode("\n", $componentImports),
                '{{ typeImports }}' => $this->sortedNames([$this->filtersType(), $this->rowType(), 'Paginated']),
            ],
        );
    }

    /**
     * @param  list<string>  $names
     */
    protected function sortedNames(array $names): string
    {
        usort($names, 'strcasecmp');

        return implode(', ', $names);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements): string
    {
        $replacements = [
            '{{ row }}' => $this->rowType(),
            '{{ filters }}' => $this->filtersType(),
            '{{ query }}' => $this->queryType(),
            '{{ toolbar }}' => $this->toolbarName(),
            '{{ card }}' => $this->cardName(),
            '{{ itemVar }}' => $this->itemVariable(),
            '{{ prop }}' => $this->prop(),
            '{{ plural }}' => $this->prefix(),
            '{{ prefix }}' => $this->prefix(),
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
     * The page reaches its own URL through Wayfinder's `@/routes/{plural}`, which exists only
     * once the route does and `wayfinder:generate` has run.
     */
    protected function warnAboutRoute(): void
    {
        if (! Route::has($this->prefix().'.index')) {
            $this->warnings[] = sprintf('Route [%s.index] does not exist yet — register it, then run `php artisan wayfinder:generate`.', $this->prefix());
        }

        $this->warnings[] = sprintf(
            $this->isGrid()
                ? "Render it with Inertia::render('%s/index', ['%s' => Inertia::scroll(\$result->…), 'filters' => \$result->filters]) — a grid sends no sort, so drop it from what make:controller wrote."
                : "Render it with Inertia::render('%s/index', ['%s' => \$result->…, 'sort' => \$result->sort, 'filters' => \$result->filters]).",
            $this->prefix(),
            $this->prop(),
        );
    }
}
