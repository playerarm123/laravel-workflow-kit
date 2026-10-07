# List pages

A list page renders what list-queries.md reads. It has one client path:

```
props (rows, sort, filters) → query → useDataTable / useListQuery → fetch(next) → visit(query) → router.get
```

The URL owns the state. The page passes the current query in, and every change (sort, page size, search, filter) goes out through the one `visit` it declares.

Enforced by the package's `tests/Architecture/ListPagesTest.php` and by the package's ESLint rules in `tests/ESLint/list-pages.js`, which `eslint.config.js` imports from `vendor/` and spreads in. The spec in `listPagesSpec()` is the machine-checked copy of this file: change the two together. The package's `tests/Architecture/ListPagesEslintTest.php` proves the ESLint rules against fixtures.

## Kit files

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`). Copy the rest into a new project as they are.
- `resources/js/hooks/use-list-query.ts`, `use-data-table.tsx`, `use-data-table-toolbar.ts`, `use-actions.ts`, `use-dialog.ts`, `use-translation.ts`
- `resources/js/components/dt-table.tsx`, `dt-toolbar.tsx`, `dialog.tsx`, `buttons.tsx`, `icons.tsx`, `heading.tsx`, `ui/date-range-picker.tsx`
- `resources/js/types/data-table.ts`, `pagination.ts`
- *package:* `tests/ESLint/list-pages.js`, imported by `eslint.config.js` (*check `eslint`*), and the fixtures its proof lints in `tests/ESLint/Fixtures/`
- *package:* `tests/ESLint/Support/rules.js`, which every ESLint kit file reads its overrides and shared helpers through. Its `internalAlias`, spread in first, ranks an unresolved `@/` import as internal, so a generated page passes import/order before `wayfinder:generate` runs

The kit builds on these shadcn components, added with `npx shadcn add`: badge, button, calendar, checkbox, dialog, dropdown-menu, input, label, pagination, popover, select, table, tooltip.

## Scaffold, never hand-write

Scaffold a list page after its PHP half (list-queries.md) is filled in. Run `php artisan make:list-page List{Name} --domain={Context}`, with `--grid` for the grid shape. It reads the `@return array{…}` of `{Name}ListRow::toArray()` and `List{Names}Criteria::toFilters()`, and writes:
- `{Name}Row`, `{Name}Filters` and, for a table, `{Names}Query` into `resources/js/types/{aggregate}.ts`, adding only the ones the file lacks, and re-exports the file from `types/index.ts`
- the domain toolbar, with a Select of the cases of every backed-enum filter and one date range for every `{x}_from`/`{x}_to` pair
- the table page, with one column per row key, the one `visit`, page sizes and both empty states
- or, with `--grid`, the grid page (the one `visit`, `<InfiniteScroll>` and both empty states) and `resources/js/components/{aggregate}/card.tsx`, with one label and value per row key
- the page's Browser test (testing.md)

Then add the cells or card fields, row actions, dialogs, `can` and translations. The command lists the translation keys and the route still missing. *Review only.*

The controller half is `php artisan make:controller {Name} --domain={Context} --only=index,destroy`, with `--grid` for the grid shape. It reads `List{Names}Command` for the raw query string arguments and `List{Names}Result` for the rows prop, and writes each action's test. A grid's index sends the rows through `Inertia::scroll(…)` and no `sort`, and the command warns when the Command still takes `sort` or `direction`. *Review only.*

## Translations

The kit speaks through `t()` from `use-translation.ts`, which reads the `locale`, `timezone` and `translations` props.

**Do**
- Share those three props from `HandleInertiaRequests::share()`, with `translations` holding the active locale's `lang/{locale}.json` over the fallback locale's. Declare them in `SharedProps`. *Check `shared-props`.*
- Keep every key the kit uses in every `lang/*.json`. *Check `lang-keys`.* The spec lists the keys, and the check also fails when a kit file uses a key the spec does not list.

A key with no translation renders as itself, so a missing key shows up on screen instead of crashing the page.

## Two shapes of list page

A list page is `resources/js/pages/**/index.tsx` whose props carry a `Paginated<…>`. It takes one of two shapes. *Check `pages`.*

| Shape | Use | Built from |
|---|---|---|
| Table | rows with columns, sorting, page size, row actions | `useDataTable({ … })` + `<DataTable dt>` |
| Grid | cards loaded as the user scrolls (`Inertia::scroll()`) | `useListQuery({ query, visit })` + `<InfiniteScroll>` + `<DataTableToolbar dt>` or the domain toolbar |

**Table page**
- Build the columns in the component with `createColumnHelper<DataTableFeatures, Row>()`. A string `header` is a translation key.
- Pass `query: { ...filters, sort: sort.column, direction: sort.direction, per_page: rows.per_page }`, straight from the props.
- Pass `perPageOptions: [10, 25, 50]` to match `PageSize` on the server, and both `emptyState` (nothing yet) and `noResultsState` (filtered to nothing). The hook picks between them.
- Row buttons come from `useActions()`. The page renders its own dialogs (`useItemDialog`, `useConfirmDialog`) next to `<DataTable>`, because the table renders none.
- Page-level buttons (create) go in `headerActions`, and the domain toolbar goes in `toolbar`.

**Grid page**
- Pass `query: { ...filters }` and render `<DataTableToolbar dt={list}>`, or the domain toolbar `<{Name}TableToolbar dt={list} />`, above the grid.
- Render each item through `{Name}Card` from `@/components/{aggregate}/card`, which holds the item's actions.
- The `visit` reloads the whole scroll prop, so a new search starts again from page one.

## One way to the server

**Do** declare the visit once, on the page:

```tsx
visit: (query) => {
    router.get(index.url({ query }), {}, { preserveScroll: true, preserveState: true, replace: true });
},
```

- `replace: true`, because a debounced search would otherwise fill the history.
- Never carry `page`. A new result set has a different page count. Paging links come from the paginator, which keeps the query string (`withQueryString()`). *Review only.*

**Don't**
- Call `router.get` on a page anywhere but inside `visit`. *ESLint check `visit-only`.*
- Debounce, hold filter state or call the router in a domain toolbar (see below).

**Why:** when every change goes through `fetch(next)`, the hook merges it into the current query and drops empty values, so no change ever forgets the parameters it did not touch. Before the hook existed, sorting dropped the filters and searching dropped the sort.

## Domain toolbars

The shared toolbar (`<DataTableToolbar>`) already has a debounced search, a filter dialog (created-at range plus domain fields) and removable chips. A domain toolbar only names its fields.

**Do**
- Put it at `resources/js/components/{aggregate}/table-toolbar.tsx`. *Check `toolbars`.*
- Take `dt` and render `<DataTableToolbar dt fields={FIELDS}>`. Use `search={false}` when the list has no searchable columns.
- Type each field as `DtFilterField<{Aggregates}Query>` (a grid's: `DtFilterField<{Name}Filters>`, with `dt: ListQuery<{Name}Filters>`), so a key that is not a real filter is a type error. A field is a Select (`{ key, label, options }`) or a `{ type: 'date-range', fromKey, toKey, label }`. Labels are translation keys.

**Don't** import `router`, or call `useState`, `useEffect` or `setTimeout` in a domain toolbar. *ESLint check `toolbar-state`.*

**Why:** each toolbar that kept its own search state, debounce and `router.get` built the query its own way and lost a different parameter.

## One table

**Don't**
- Import `@/components/ui/table` in a page. *ESLint check `one-table`.* A component may still use it for a small fixed table that is not a list, such as the lines inside a detail dialog.
- Import `useTable` from `@tanstack/react-table` outside the kit. *ESLint check `one-table`.*

**Why:** a page that copies the table markup also copies, or forgets, the sorting, selection, empty states and paging that `<DataTable>` keeps in one place.

## Row and filter types mirror the server

Every `{Name}ListRow` (list-queries.md) has a TypeScript twin in `resources/js/types/{aggregate}.ts`. *Check `row-types`.*
- It is named `{Name}Row` and carries `@see` with the PHP class's full name.
- Its top-level keys are exactly the keys of `toArray()`, snake_case, nothing optional that PHP always sends, and no `Date` (JSON carries strings). `toArray()` spells every key out, with no spread.

Every `List{Names}Criteria` has a `{Name}Filters` twin, named after the `{Name}ListRow` beside it. *Check `filter-types`.*
- It carries `@see` with the Criteria's full name.
- Its keys are exactly the keys `toFilters()` sends. The check follows a spread of `...$this->{property}->{method}()` into that property's class, which is how `...$this->createdAt->toFilters()` adds `created_from` and `created_to`. Any other spread fails the check.
- Write it as object literals and exported type names joined by `&`, usually `DtFilters & { … }`, so the check can read it.
- `search` is a `string` and every other filter `string | null`. *Review only.*

`{Names}Query` is `{Name}Filters & DtQuery` for a table page. *Review only.*

**Why:** JSON converts nothing. A type that drifts from `toArray()` or `toFilters()` still compiles. A drifting row breaks the page at render time, and a drifting filter set breaks the toolbar: a chip that never shows, or a clear button that leaves a filter on the URL.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "list-pages"`, exactly as stack.md describes. An ESLint check is overridden per file: the `subject` is the file's path from the project root. Only the user may add an entry.
