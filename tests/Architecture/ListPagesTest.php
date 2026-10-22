<?php

require_once __DIR__.'/Support/rules.php';

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMenu;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The machine-checked half of list-pages.md — change the two together.
 *
 * The in-file rules (router calls, toolbar state, table imports) are ESLint's, in
 * tests/ESLint/list-pages.js, proven by ListPagesEslintTest. This file checks what spans
 * files: the kit, its translations, the shape of every list page, where domain toolbars
 * live, that every TypeScript row and filter set mirrors its PHP twin key for key, and that
 * every list page has its entry in the main menu (app/Http/Navigation.php), in the group its
 * manifest names.
 *
 * @return array{
 *     kit_files: list<string>,
 *     shadcn_components: list<string>,
 *     eslint_config: string,
 *     eslint_rules: string,
 *     kit_lang_keys: list<string>,
 *     lang_glob: string,
 *     locale_env_files: list<string>,
 *     locale_env_keys: list<string>,
 *     shared_props: list<string>,
 *     share_file: string,
 *     types_path: string,
 *     pages_path: string,
 *     list_page_marker: string,
 *     shapes: array<string, array<string, string>>,
 *     toolbar_marker: string,
 *     toolbar_pattern: string,
 *     row_glob: string,
 *     criteria_glob: string,
 *     lucide_icons: string,
 * }
 */
function listPagesSpec(): array
{
    return [
        'kit_files' => [
            'resources/js/hooks/use-list-query.ts',
            'resources/js/hooks/use-data-table.tsx',
            'resources/js/hooks/use-data-table-toolbar.ts',
            'resources/js/hooks/use-actions.ts',
            'resources/js/hooks/use-dialog.ts',
            'resources/js/hooks/use-translation.ts',
            'resources/js/components/dt-table.tsx',
            'resources/js/components/dt-toolbar.tsx',
            'resources/js/components/dialog.tsx',
            'resources/js/components/buttons.tsx',
            'resources/js/components/icons.tsx',
            'resources/js/components/heading.tsx',
            'resources/js/components/ui/date-range-picker.tsx',
            'resources/js/types/data-table.ts',
            'resources/js/types/pagination.ts',
            'resources/js/types/ui.ts',
            'resources/js/hooks/use-main-nav-items.tsx',
            'resources/js/components/main-nav-sidebar.tsx',
            'resources/js/components/main-nav-header.tsx',
            'app/Http/Concerns/BuildsMainMenu.php',
            StructureMenu::FILE,
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/list-pages.js',
            'vendor/playerarm123/laravel-workflow-kit/tests/ESLint/Support/rules.js',
        ],
        'shadcn_components' => [
            'badge', 'button', 'calendar', 'checkbox', 'collapsible', 'dialog', 'dropdown-menu',
            'input', 'label', 'navigation-menu', 'pagination', 'popover', 'select', 'sidebar',
            'table', 'tooltip',
        ],
        'eslint_config' => 'eslint.config.js',
        'eslint_rules' => './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/list-pages.js',
        'kit_lang_keys' => [
            'common.action_delete',
            'common.action_edit',
            'common.action_view',
            'common.actions',
            'common.all',
            'common.cancel',
            'common.clear',
            'common.clear_filters',
            'common.clear_selection',
            'common.confirm_delete_description',
            'common.confirm_delete_title',
            'common.next',
            'common.previous',
            'common.rows_per_page',
            'common.search',
            'common.select_all',
            'common.select_row',
            'common.selected_count',
            'common.showing_range',
            'data_table.any_day',
            'data_table.apply_filters',
            'data_table.created_between',
            'data_table.filters',
            'data_table.not_found_description',
            'data_table.not_found_title',
            'data_table.remove_filter',
        ],
        'lang_glob' => 'lang/*.json',
        // The app runs in each locale these keys name, read from each file that exists.
        'locale_env_files' => ['.env.example', '.env'],
        'locale_env_keys' => ['APP_LOCALE', 'APP_FALLBACK_LOCALE'],
        'shared_props' => ['locale', 'timezone', 'translations', 'menu'],
        'share_file' => 'app/Http/Middleware/HandleInertiaRequests.php',
        'types_path' => 'resources/js/types',
        'pages_path' => 'resources/js/pages',
        'list_page_marker' => 'Paginated<',
        'shapes' => [
            'table' => [
                'useDataTable()' => '/\buseDataTable\s*(<[^()]*>)?\s*\(/',
                '<DataTable>' => '/<DataTable\b/',
            ],
            'grid' => [
                'useListQuery()' => '/\buseListQuery\s*(<[^()]*>)?\s*\(/',
                '<InfiniteScroll>' => '/<InfiniteScroll\b/',
                '<DataTableToolbar> or its domain toolbar' => '/<\w*TableToolbar\b/',
            ],
        ],
        'toolbar_marker' => '<DataTableToolbar',
        'toolbar_pattern' => '#^resources/js/components/[^/]+/table-toolbar\.tsx$#',
        'row_glob' => 'app/Application/*/UseCases/List*/*ListRow.php',
        'criteria_glob' => 'app/Application/*/UseCases/List*/List*Criteria.php',
        'lucide_icons' => 'node_modules/lucide-react/dist/esm/dynamicIconImports.mjs',
    ];
}

/**
 * The kit's TS/TSX files — the ones whose translation keys belong to the kit.
 *
 * @return list<string>
 */
function listPagesKitScripts(): array
{
    return array_values(array_filter(
        listPagesSpec()['kit_files'],
        fn (string $file): bool => str_starts_with($file, 'resources/js/'),
    ));
}

/**
 * @return list<string> the list pages: an index page whose props carry a paginator
 */
function listPagesListPages(): array
{
    return array_values(array_filter(
        ruleSourceFiles(listPagesSpec()['pages_path'], ['tsx']),
        fn (string $file): bool => basename($file) === 'index.tsx'
            && str_contains(ruleCodeWithoutComments($file), listPagesSpec()['list_page_marker']),
    ));
}

/**
 * The route a list page opens from: its folder under the pages, dotted, then `.index`
 * (`pages/audit-entries/index.tsx` → `audit-entries.index`), as Route::resource() names it.
 */
function listPagesRouteOf(string $page): string
{
    $folder = substr(dirname($page), strlen(listPagesSpec()['pages_path']) + 1);

    return str_replace('/', '.', $folder).'.index';
}

/**
 * The list pages a resource manifest keeps out of the menu on purpose, with `"menu": null`.
 *
 * @return list<string> page paths from the project root
 */
function listPagesOutOfMenu(): array
{
    $files = new StructureFiles(ruleProjectPath());
    $pages = [];

    foreach ($files->resources() as $resource) {
        $manifest = $files->readResource($resource);

        if (! is_array($manifest) || ! array_key_exists('menu', $manifest) || $manifest['menu'] !== null) {
            continue;
        }

        foreach (array_keys((array) ($manifest['pages'] ?? [])) as $path) {
            $pages[] = listPagesSpec()['pages_path'].'/'.$path.'.tsx';
        }
    }

    return $pages;
}

/**
 * The group each list page's resource manifest puts its menu entry in, null for none, for the
 * list pages whose manifest has a menu entry.
 *
 * @return array<string, string|null> page path from the project root => group key
 */
function listPagesMenuGroups(): array
{
    $files = new StructureFiles(ruleProjectPath());
    $groups = [];

    foreach ($files->resources() as $resource) {
        $manifest = $files->readResource($resource);
        $menu = is_array($manifest) ? $manifest['menu'] ?? null : null;
        $page = is_array($menu) && is_array($manifest['pages'] ?? null) ? StructureReader::listPageOf($manifest['pages']) : null;

        if ($page !== null) {
            $groups[listPagesSpec()['pages_path'].'/'.$page.'.tsx'] = is_string($menu['group'] ?? null) ? $menu['group'] : null;
        }
    }

    return $groups;
}

/**
 * The icon names the installed lucide-react knows, or null when it cannot be read.
 *
 * @return list<string>|null
 */
function listPagesLucideIcons(): ?array
{
    $file = ruleProjectPath(listPagesSpec()['lucide_icons']);

    if (! is_file($file) || preg_match_all('/^\s*"([a-z0-9-]+)":/m', (string) file_get_contents($file), $names) < 1) {
        return null;
    }

    return $names[1];
}

/**
 * The keys `Criteria::toFilters()` sends, with each `...$this->{property}->{method}()` spread
 * replaced by the keys that property's class returns from that method.
 *
 * @return array{keys: list<string>, problems: list<string>}|null null when toFilters() returns no array literal
 */
function listPagesFilterKeys(string $file): ?array
{
    $entries = ruleArrayKeysOf($file, 'toFilters');

    if ($entries === null) {
        return null;
    }

    $keys = [];
    $problems = [];

    foreach ($entries as $entry) {
        if (! str_starts_with($entry, '...')) {
            $keys[] = $entry;

            continue;
        }

        $class = ruleClassOf($file);
        $resolved = null;

        if (preg_match('/^\.\.\.\$this->(\w+)->(\w+)\(\)$/', $entry, $spread) === 1 && class_exists($class) && property_exists($class, $spread[1])) {
            $type = (new ReflectionProperty($class, $spread[1]))->getType();
            $source = $type instanceof ReflectionNamedType && class_exists($type->getName())
                ? (new ReflectionClass($type->getName()))->getFileName()
                : false;

            if (is_string($source) && str_starts_with($source, ruleProjectPath().'/')) {
                $resolved = ruleArrayKeysOf(substr($source, strlen(ruleProjectPath()) + 1), $spread[2]);
            }
        }

        if ($resolved === null || array_filter($resolved, fn (string $key): bool => str_starts_with($key, '...')) !== []) {
            $problems[] = sprintf('spreads `%s`, which the check cannot read — spread only `$this->{property}->{method}()` whose method returns a literal array', $entry);

            continue;
        }

        $keys = [...$keys, ...$resolved];
    }

    return ['keys' => $keys, 'problems' => $problems];
}

describe('list pages', function () {
    it('ships the list page kit at its fixed home', function () {
        $violations = ruleKitFileViolations(listPagesSpec()['kit_files']);

        foreach (listPagesSpec()['shadcn_components'] as $component) {
            $file = sprintf('resources/js/components/ui/%s.tsx', $component);

            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => sprintf('is missing — npx shadcn add %s', $component)];
            }
        }

        expect(ruleUnexcused('list-pages', 'kit-files', $violations))->toBe([]);
    });

    it('spreads the list page ESLint rules into the ESLint config', function () {
        $config = listPagesSpec()['eslint_config'];
        $code = is_file(ruleProjectPath($config)) ? ruleCodeWithoutComments($config) : '';
        $import = sprintf('/^import\s+(\w+)\s+from\s+[\'"]%s[\'"]/m', preg_quote(listPagesSpec()['eslint_rules'], '/'));
        $violations = [];

        if (preg_match($import, $code, $match) !== 1) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must import %s', listPagesSpec()['eslint_rules'])];
        } elseif (! str_contains($code, '...'.$match[1])) {
            $violations[] = ['subject' => $config, 'message' => sprintf('must spread ...%s into the exported config', $match[1])];
        }

        expect(ruleUnexcused('list-pages', 'eslint', $violations))->toBe([]);
    });

    it('shares the props the kit translates with', function () {
        $violations = [];
        $share = listPagesSpec()['share_file'];
        $code = is_file(ruleProjectPath($share)) ? ruleCodeWithoutComments($share) : '';

        foreach (listPagesSpec()['shared_props'] as $prop) {
            if (! str_contains($code, sprintf("'%s' =>", $prop))) {
                $violations[] = ['subject' => $share, 'message' => sprintf("share() must return '%s'", $prop)];
            }
        }

        $declared = null;

        foreach (ruleSourceFiles(listPagesSpec()['types_path'], ['ts']) as $file) {
            $declared ??= ruleTsTypeKeys($file, 'SharedProps');
        }

        foreach (listPagesSpec()['shared_props'] as $prop) {
            if (! in_array($prop, $declared ?? [], true)) {
                $violations[] = ['subject' => 'SharedProps', 'message' => sprintf('must declare %s', $prop)];
            }
        }

        expect(ruleUnexcused('list-pages', 'shared-props', $violations))->toBe([]);
    });

    it('translates every kit key in every locale', function () {
        $violations = [];
        $keys = listPagesSpec()['kit_lang_keys'];

        foreach (listPagesSpec()['locale_env_files'] as $env) {
            foreach (listPagesSpec()['locale_env_keys'] as $name) {
                $locale = ruleEnvValue($env, $name);
                $file = str_replace('*', (string) $locale, listPagesSpec()['lang_glob']);

                if ($locale !== null && $locale !== '' && ! is_file(ruleProjectPath($file))) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('is missing, while %s in %s names %s — the pages would show their keys instead of their words', $name, $env, $locale)];
                }
            }
        }

        foreach (ruleGlob(ruleProjectPath(listPagesSpec()['lang_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $messages = ruleReadJson($file);

            foreach ($keys as $key) {
                if (! is_string($messages[$key] ?? null)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('has no "%s"', $key)];
                }
            }
        }

        foreach (listPagesKitScripts() as $file) {
            if (! is_file(ruleProjectPath($file))) {
                continue;
            }

            preg_match_all('/[\'"]([a-z][a-z_]*\.[a-z][a-z_.]*)[\'"]/', ruleCodeWithoutComments($file), $used);

            foreach (array_unique($used[1]) as $key) {
                if (! in_array($key, $keys, true)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('uses "%s", which listPagesSpec() kit_lang_keys does not list', $key)];
                }
            }
        }

        expect(ruleUnexcused('list-pages', 'lang-keys', $violations))->toBe([]);
    });

    it('puts every list page in the main menu, in the group its manifest names', function () {
        $menu = new StructureMenu(ruleProjectPath());
        $kept = listPagesOutOfMenu();
        $groups = listPagesMenuGroups();
        $violations = [];

        foreach (listPagesListPages() as $page) {
            $entry = $menu->entryFor(listPagesRouteOf($page));

            if ($entry !== null && array_key_exists($page, $groups) && $groups[$page] !== $entry['group']) {
                $violations[] = ['subject' => $page, 'message' => sprintf(
                    'sits in %s in %s, while its resource manifest puts it in %s — move the line, or change `"menu".group`',
                    $entry['group'] === null ? 'no group' : "the group '{$entry['group']}'",
                    StructureMenu::FILE,
                    $groups[$page] === null ? 'no group' : "the group '{$groups[$page]}'",
                )];
            }

            if (in_array($page, $kept, true) || $entry !== null) {
                continue;
            }

            $violations[] = ['subject' => $page, 'message' => sprintf(
                "has no entry for '%s' in %s — add `\"menu\"` to its resource manifest and run `php artisan kit:apply`, or set `\"menu\": null` to keep it out",
                listPagesRouteOf($page),
                StructureMenu::FILE,
            )];
        }

        expect(ruleUnexcused('list-pages', 'menu', $violations))->toBe([]);
    });

    it('declares every menu group once, and gives each entry a group it declares', function () {
        $menu = new StructureMenu(ruleProjectPath());
        $declared = array_column($menu->groups(), 'key');
        $violations = [];

        foreach (array_count_values($declared) as $key => $count) {
            if ($count > 1) {
                $violations[] = ['subject' => StructureMenu::FILE, 'message' => sprintf("declares the group '%s' %d times — declare a group once, where it should sit", $key, $count)];
            }

            if (preg_match(StructureFiles::MENU_GROUP, (string) $key) !== 1) {
                $violations[] = ['subject' => StructureMenu::FILE, 'message' => sprintf("declares the group '%s', whose key is not a lowercase slug such as finance", $key)];
            }
        }

        if ($declared !== [] && ! $menu->supportsGroups()) {
            $violations[] = ['subject' => StructureMenu::FILE, 'message' => 'declares groups without BuildsMainMenu, which draws them — run `php artisan kit:setup`'];
        }

        foreach ($menu->entries() as $entry) {
            if ($entry['group'] !== null && ! in_array($entry['group'], $declared, true)) {
                $violations[] = ['subject' => $entry['route'], 'message' => sprintf("sits in the group '%s', which %s does not declare — add \$this->group('%s', '{title key}', '{lucide icon}') where the group should sit", $entry['group'], StructureMenu::FILE, $entry['group'])];
            }
        }

        expect(ruleUnexcused('list-pages', 'menu-group', $violations))->toBe([]);
    });

    it('shows a menu entry about a model only to whom its policy lets open the page', function () {
        $violations = [];

        foreach ((new StructureMenu(ruleProjectPath()))->entries() as $entry) {
            if ($entry['model'] === null) {
                continue;
            }

            $policy = class_exists($entry['model'])
                ? (new ReflectionClass($entry['model']))->getAttributes(UsePolicy::class)[0] ?? null
                : null;
            $class = $policy?->getArguments()[0] ?? null;

            if (! is_string($class) || ! method_exists($class, $entry['ability'])) {
                $violations[] = ['subject' => $entry['route'], 'message' => sprintf(
                    'asks %s of %s, whose policy does not answer it — add %s() to its policy, or name with `ability:` the one the page asks (authorization.md)',
                    $entry['ability'],
                    $entry['model'],
                    $entry['ability'],
                )];
            }
        }

        expect(ruleUnexcused('list-pages', 'menu-ability', $violations))->toBe([]);
    });

    it('names every menu icon lucide knows', function () {
        $known = listPagesLucideIcons();
        $violations = [];

        $menu = new StructureMenu(ruleProjectPath());
        $lines = [
            ...array_map(fn (array $entry): array => ['subject' => $entry['route'], 'icon' => $entry['icon']], $menu->entries()),
            ...array_map(fn (array $group): array => ['subject' => "group '{$group['key']}'", 'icon' => $group['icon']], $menu->groups()),
        ];

        foreach ($known === null ? [] : $lines as $line) {
            if (! in_array($line['icon'], $known, true)) {
                $violations[] = ['subject' => $line['subject'], 'message' => sprintf('shows the icon "%s", which lucide-react does not have — pick a kebab-case name from lucide.dev', $line['icon'])];
            }
        }

        expect(ruleUnexcused('list-pages', 'menu-icon', $violations))->toBe([]);
    });

    it('translates every menu title, group title and description in every locale', function () {
        $violations = [];

        foreach (ruleGlob(ruleProjectPath(listPagesSpec()['lang_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $translations = ruleReadJson($file);

            $menu = new StructureMenu(ruleProjectPath());

            foreach ($menu->entries() as $entry) {
                if (! is_string($translations[$entry['title']] ?? null)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('is missing the menu title "%s"', $entry['title'])];
                }

                if ($entry['description'] !== null && ! is_string($translations[$entry['description']] ?? null)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('is missing the menu description "%s"', $entry['description'])];
                }
            }

            foreach ($menu->groups() as $group) {
                if (! is_string($translations[$group['title']] ?? null)) {
                    $violations[] = ['subject' => $file, 'message' => sprintf('is missing the menu group title "%s"', $group['title'])];
                }
            }
        }

        expect(ruleUnexcused('list-pages', 'lang-keys', $violations))->toBe([]);
    });

    it('builds every list page in one of the two shapes', function () {
        $violations = [];

        foreach (listPagesListPages() as $file) {
            $code = ruleCodeWithoutComments($file);
            $shapes = array_filter(
                listPagesSpec()['shapes'],
                fn (array $markers): bool => array_filter($markers, fn (string $pattern): bool => preg_match($pattern, $code) !== 1) === [],
            );

            if ($shapes === []) {
                $violations[] = ['subject' => $file, 'message' => 'must be a '.implode(' or a ', array_map(
                    fn (string $shape, array $markers): string => sprintf('%s (%s)', $shape, implode(' + ', array_keys($markers))),
                    array_keys(listPagesSpec()['shapes']),
                    listPagesSpec()['shapes'],
                )).' — it renders a paginator some other way'];
            }
        }

        expect(ruleUnexcused('list-pages', 'pages', $violations))->toBe([]);
    });

    it('keeps every domain toolbar at components/{aggregate}/table-toolbar.tsx', function () {
        $violations = [];
        $kit = listPagesSpec()['kit_files'];

        foreach (ruleSourceFiles('resources/js', ['tsx']) as $file) {
            if (in_array($file, $kit, true) || str_starts_with($file, listPagesSpec()['pages_path'].'/')) {
                continue;
            }

            $isToolbar = str_contains(ruleCodeWithoutComments($file), listPagesSpec()['toolbar_marker'])
                || str_ends_with($file, 'table-toolbar.tsx');

            if ($isToolbar && preg_match(listPagesSpec()['toolbar_pattern'], $file) !== 1) {
                $violations[] = ['subject' => $file, 'message' => 'is a domain toolbar — move it to resources/js/components/{aggregate}/table-toolbar.tsx'];
            }
        }

        expect(ruleUnexcused('list-pages', 'toolbars', $violations))->toBe([]);
    });

    it('mirrors every list row in TypeScript key for key', function () {
        $violations = [];
        $types = ruleSourceFiles(listPagesSpec()['types_path'], ['ts']);

        foreach (ruleGlob(ruleProjectPath(listPagesSpec()['row_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $class = ruleClassOf($file);
            $type = substr(basename($file, '.php'), 0, -strlen('ListRow')).'Row';
            $php = ruleArrayKeysOf($file, 'toArray');

            $found = null;

            foreach ($types as $candidate) {
                $keys = ruleTsTypeKeys($candidate, $type);

                if ($keys !== null) {
                    $found = [$candidate, $keys];

                    break;
                }
            }

            if ($found === null) {
                $violations[] = ['subject' => $class, 'message' => sprintf('has no `export type %s = { … }` under %s', $type, listPagesSpec()['types_path'])];

                continue;
            }

            [$typeFile, $ts] = $found;
            $docblock = preg_match('#/\*\*((?:(?!\*/).)*)\*/\s*export\s+type\s+'.preg_quote($type, '#').'\b#s', (string) file_get_contents(ruleProjectPath($typeFile)), $doc) === 1 ? $doc[1] : '';

            if (preg_match('/@see\s+\\\\?'.preg_quote($class, '/').'\b/', $docblock) !== 1) {
                $violations[] = ['subject' => $class, 'message' => sprintf('%s in %s needs `@see %s`', $type, $typeFile, $class)];
            }

            if ($php === null) {
                $violations[] = ['subject' => $class, 'message' => 'toArray() must return an array literal with every key spelled out'];

                continue;
            }

            foreach (array_diff($php, $ts) as $key) {
                $violations[] = ['subject' => $class, 'message' => str_starts_with($key, '...')
                    ? sprintf('toArray() spreads `%s` — spell every key out', $key)
                    : sprintf('toArray() sends "%s" but %s does not declare it', $key, $type)];
            }

            foreach (array_diff($ts, $php) as $key) {
                $violations[] = ['subject' => $class, 'message' => sprintf('%s declares "%s" but toArray() never sends it', $type, $key)];
            }
        }

        expect(ruleUnexcused('list-pages', 'row-types', $violations))->toBe([]);
    });

    it('mirrors every list filter set in TypeScript key for key', function () {
        $violations = [];
        $types = ruleSourceFiles(listPagesSpec()['types_path'], ['ts']);

        foreach (ruleGlob(ruleProjectPath(listPagesSpec()['criteria_glob'])) as $path) {
            $file = ltrim(substr($path, strlen(ruleProjectPath())), '/');
            $class = ruleClassOf($file);
            $rows = ruleGlob(dirname($path).'/*ListRow.php');

            if (count($rows) !== 1) {
                $violations[] = ['subject' => $class, 'message' => 'needs exactly one *ListRow beside it to name its {Name}Filters type'];

                continue;
            }

            $type = substr(basename($rows[0], '.php'), 0, -strlen('ListRow')).'Filters';
            $php = listPagesFilterKeys($file);
            $ts = ruleTsResolvedTypeKeys($types, $type);

            if ($ts === null) {
                $violations[] = ['subject' => $class, 'message' => sprintf('has no `export type %s` under %s written as object literals and named types joined by &', $type, listPagesSpec()['types_path'])];

                continue;
            }

            $declaredIn = array_values(array_filter($types, fn (string $candidate): bool => preg_match('/\bexport\s+type\s+'.preg_quote($type, '/').'\s*=/', ruleCodeWithoutComments($candidate)) === 1))[0];
            $docblock = preg_match('#/\*\*((?:(?!\*/).)*)\*/\s*export\s+type\s+'.preg_quote($type, '#').'\b#s', (string) file_get_contents(ruleProjectPath($declaredIn)), $doc) === 1 ? $doc[1] : '';

            if (preg_match('/@see\s+\\\\?'.preg_quote($class, '/').'\b/', $docblock) !== 1) {
                $violations[] = ['subject' => $class, 'message' => sprintf('%s in %s needs `@see %s::toFilters()`', $type, $declaredIn, $class)];
            }

            if ($php === null) {
                $violations[] = ['subject' => $class, 'message' => 'toFilters() must return an array literal'];

                continue;
            }

            foreach ($php['problems'] as $problem) {
                $violations[] = ['subject' => $class, 'message' => 'toFilters() '.$problem];
            }

            foreach (array_diff($php['keys'], $ts) as $key) {
                $violations[] = ['subject' => $class, 'message' => sprintf('toFilters() sends "%s" but %s does not declare it', $key, $type)];
            }

            foreach (array_diff($ts, $php['keys']) as $key) {
                $violations[] = ['subject' => $class, 'message' => sprintf('%s declares "%s" but toFilters() never sends it', $type, $key)];
            }
        }

        expect(ruleUnexcused('list-pages', 'filter-types', $violations))->toBe([]);
    });
});
