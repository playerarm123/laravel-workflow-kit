---
name: list-page
description: "Build a server-paginated list page end to end (List{Aggregate}s use case, Eloquent read query, resource controller index/destroy, Inertia React page on useDataTable + DataTable + useActions, Pest tests) with the workflow kit's generators. Activate when the user asks to create a list page, index page, table page, data table, or CRUD listing for an aggregate; says ทำหน้า list, หน้ารายการ, ตาราง {aggregate}, หน้า index; or invokes /list-page. Starts by collecting the full page spec from the user through explicit questions — never guesses columns, filters, sorting, or row actions — then implements backend, frontend, and tests in order. Do not use for create/edit forms, detail (show) pages, or changes to the shared DataTable component itself."
---

# List Page

A list page is a table that searches, filters, sorts and pages on the server, plus row buttons (view / edit / delete / others) and the `destroy` the table is tied to.
This skill walks the whole line, backend → frontend → tests, with the kit's generators, then fills in what the generators cannot write.

**Scope:** the `index` and `destroy` pages only. It covers no create/edit form (use `/create-page`) and no show page, and it never changes the shared `DataTable`/`useDataTable`/`useActions`. If the new page needs something the shared table does not have yet, stop and report to the user first.

Read these rules before you touch a file: the guidelines `list-queries.md` (backend), `list-pages.md` (frontend), `handlers.md`, `authorization.md`, `dates.md`, `numbers.md`, `testing.md`, and the project's `.ai/rules` when it has them (read `.ai/rules/index.md` to find the files that match the path).

If the project already has a finished list page, open one as a model (look for `useDataTable(` in `resources/js/pages/**/index.tsx`). What the generator writes is the truth, though: when the two disagree, trust the generator and the guideline.

---

## Step 0 — Collect the full page spec before you touch a file (a hard gate)

**Rule: never guess.** Every item below needs an answer from the user, or a clear reading of the existing code (the Model, the migration, Enums, the Policy), before the first file is created.

How to ask:
- Use `AskUserQuestion` one set at a time (at most 4 questions per call), in order from set A to set D.
- Before each set, **read the code first** and offer what you find as options: the columns from the migration and `#[Fillable]`, the enums in `app/Domain/{Context}/**/Enums`, the roles and enums the policy reads, the methods in the Policy. Don't ask an open question when you can offer options.
- When the user answers vaguely ("like the old page" / "whatever you think fits"), **ask again, narrower**: propose the values you will actually use and have them confirm each one. Never interpret it yourself.
- Don't start until every set is closed. Then **summarize the spec in one table for the user to confirm once** before step 1.

### Set A — Identity

- The aggregate / Model name and its context → namespace `App\Application\{Context}`, route `{aggregates}`, lang prefix `{aggregates}.`, page path `pages/{aggregates}/index.tsx`
- Who may see this page (→ `viewAny`), and whether the Policy exists or must be created (`make:policy`)
- The page shape: **Table** (columns, sorting, paging; the default) or **Grid** (cards that load more as the user scrolls, with `Inertia::scroll()`; no sort or page size to pick) → a Grid passes `--grid` to both `make:controller` and `make:list-page`

### Set B — The table (Grid: ask which fields the card shows and how each renders, instead of columns. Don't ask about sortable columns; use the default sort only.)

- Each column shown, **one at a time**: the field it comes from, its type (string / int / enum / datetime / money / relation), and how it renders (text, badge, date by locale, money, icon)
- Which columns **can be sorted** → the `{Aggregate}ListSort` enum, and the **default sort column + direction**
- Which columns are enums → each value needs a translation `{aggregates}.{field}.{value}` in every `lang/*.json`

### Set C — Search / filters

- The columns free-text search runs on → `SEARCHABLE_COLUMNS` (or no search)
- The created-date range (`created_from`/`created_to` → `DateRange $createdAt` + `applyCreatedBetween()`) is on every page without asking. Ask only when the column is not `created_at`.
- Each filter: its query param name, the enum it reads (→ `tryFrom` with a `null` fallback) or another kind of value, and whether its toolbar option is a Select or something else
- `PageSize` is fixed at 10/25/50. Another set must be asked for (and means changing a kit file, which is outside this skill).

### Set D — Row buttons / bulk

- **view**: opens the show page (`actions.view.page({ route: show })`) or a dialog (`actions.view.dialog({ dialog })`; the page renders the dialog itself, so ask what its body holds)
- **edit**: whether there is one, and whether it is a page / a dialog / still disabled, waiting for the backend
- **delete**: whether there is one, who may delete (→ `can.delete` from `deleteAny`), whether the confirm text carries the item's name, and the toast text after deleting
- Other row actions and bulk actions → build them with `make:action`, per the `actions.md` guideline (is there an endpoint yet, or only a `disabled` placeholder?)
- Relations a column needs → eager load them in the adapter

### What you decide yourself, without asking (but list it in the summary table)

File and class names by convention, the column order the user gave, the icons of the standard buttons (presets in `use-actions.ts`), translation keys under the prefix, test case names, helper names in the tests.

### Spec summary table (the user confirms it before step 1)

| Topic | Value |
| --- | --- |
| Aggregate / Context / route / lang prefix | |
| Page shape (Table / Grid) | |
| Policy / who sees / who deletes | |
| Columns (field → render) | |
| Sortable + default | |
| search columns | |
| filters (param → enum/value) | |
| row actions (view/edit/delete/other) | |
| bulk actions | |
| eager loads | |

---

## Workflow

If the project uses the structure manifest (`.kit/structure/`), design the use case and the page in the manifest first, then let `php artisan kit:apply` run the commands in item 1 and item 7 (the `structure.md` guideline). A step where apply stops and waits is the part a person fills in.

### 1. Backend

1. `php artisan make:use-case List{Aggregate}s --domain={Context} --command --result --query --no-interaction`. **Never add `--repo`** (a repository is the write side and cannot page). You get the Command, Criteria, Handler, Query (port), Result, `{Aggregate}ListRow`, `{Aggregate}ListSort` (at the context level), the adapter `EloquentList{Aggregate}sQuery`, and tests with `->todo()`.
2. `{Aggregate}ListSort`: add a case for every sortable column. `fromInput()` falls back to the default and never throws.
3. `{Aggregate}ListRow implements Arrayable`, **not a spatie `Data`** (that would change the paginator's envelope). `toArray()` is the contract the TS twin mirrors. Write its `@return array{…}` with every key, because `make:list-page` reads it. Money goes out as a decimal string per `numbers.md`, and dates and times as ISO 8601 per `dates.md`.
4. The `Criteria` has no defaults, every field is required, and `toFilters()` has a full `@return array{…}`. The `Handler` is the one place that settles raw values (`fromInput`, `tryFrom`, `trim`, `PageSize::fromInput`, `DateRange::fromInput`), and the Result echoes `toSort()`/`toFilters()` back.
5. The adapter `extends EloquentListQuery`, has `SEARCHABLE_COLUMNS` and `applySearch()`, and ends with `paginateRows()` (never `->paginate(` directly). It eager loads per the spec, with `sortColumn()`/`toRow()` private. The full rules are in `list-queries.md`.
6. Bind the port to the adapter in a service provider's `$bindings` (the generator prints the line).
7. Controller: `php artisan make:controller {Aggregate} --domain={Context} --only=index,destroy --no-interaction`. **Never write the controller yourself.** The generator reads `List{Aggregate}s{Command,Result}` and writes `index` (`Gate::authorize('viewAny')` → every `$request->string('x')->toString()` raw into the Command, **no FormRequest** → render `{aggregates}`/`sort`/`filters`/`can`) and `destroy` (`Gate::authorize('delete')` → `Delete{Aggregate}Handler($model->id)` → toast → `back()`), with the tests `{Aggregate}Controller/IndexTest.php` and `DestroyTest.php` holding `->todo()`. A Grid adds `--grid`: index then sends `Inertia::scroll($result->…)` and no `sort` (if it warns that the Command still takes `sort`/`direction`, remove them from the Command).
   When the controller already exists, the generator adds only the missing methods and leaves the existing ones alone. Fill in yourself: `:name` in the toast `{aggregates}.deleted` (keep the name before deleting), `can` for other models, and a `catch` of the entity's refusal in `destroy`, by name (`exceptions.md`). Read the warnings the generator prints (abilities the policy lacks, missing translation keys, the route line).
8. Policy `viewAny` / `view` / `deleteAny` / `delete` when missing (`make:policy`), plus the route `Route::resource(…)->only(['index', 'destroy'])`

### 2. Frontend

1. `php artisan make:list-page List{Aggregate}s --domain={Context} --no-interaction`, only once the backend is done, because it reads the `@return array{…}` of `toArray()`/`toFilters()` and fails when the docblock is incomplete:
   - `resources/js/types/{aggregate}.ts`: `{Aggregate}Row`, `{Aggregate}Filters`, `{Aggregates}Query` with `@see` (appended to the existing file, only the types it lacks), exported from `types/index.ts`
   - the toolbar `components/{aggregate}/table-toolbar.tsx`: a Select from the cases of each enum filter, and a date range from each `{x}_from`/`{x}_to` pair
   - the page `pages/{aggregates}/index.tsx`: one column per key of the Row (except `id`), the one `visit`, `perPageOptions`, `emptyState` + `noResultsState`
   - the Browser test `tests/Browser/{Aggregates}/IndexTest.php` (the page's mirror, per `testing.md`) with `->todo()`
   - **Grid (`--grid`)**: no `{Aggregates}Query` (the toolbar takes `dt: ListQuery<{Aggregate}Filters>`). The page uses `useListQuery` + `<InfiniteScroll>` + the toolbar + `{Aggregate}Card`, and it writes `components/{aggregate}/card.tsx` with one label and value per key of the Row (except `id`). Add the value formatting and the item's buttons in the card, not in the page.
   - A page, toolbar, card or Browser test that already exists is never overwritten. The warnings at the end name the route and translation keys still missing.
2. Narrow the types the generator wrote as `string` or `unknown` to fit the spec (an enum as a string union with `@see` to the PHP file), then run `php artisan wayfinder:generate --with-form` and check that `@/routes/{aggregates}` has the `index`/`show`/`edit`/`destroy` the page uses.
3. Fill in the page per the spec, in this order in the component:
   `useTranslation` → `useActions<Row>()` → `useConfirmDialog`/`useItemDialog` per the spec → `setLayoutProps({ breadcrumbs })` → the columns (remove the ones not shown, order them per the spec, add `cell`/`enableSorting: false`. Format dates only through `@/lib/dates`: `formatDateTime`/`formatDate` with `locale`/`timezone` from `useTranslation()`, and `formatCalendarDate` for a `Y-m-d` day. Format money and rates only through `@/lib/numbers`: `formatMoney` with `locale`/`currency`, and `formatPercent` for a rate) → `rowAction` + `can` in props → `<DataTable dt toolbar bulkActions headerActions />` + the dialogs the page renders itself. **Never touch the `query`/`visit` the generator wrote. Never carry `page`.**
4. The toolbar: fill in the `options` of filters that are not enums (the generator has already warned about them by name), and add `search={false}` when there is no column to search. **A toolbar never has its own `router.get`, debounce, dialog or state.**
5. Translations in every `lang/*.json` (keys sorted alphabetically): `{aggregates}.title` `description` `confirm_delete` (`:name`) `deleted` (`:name`) `empty_title` `empty_description` `no_results_title` `no_results_description`, plus every column header and every enum value

### 3. Tests

The generators have already scaffolded the test files with `->todo()`. Write in those files, never create a second one (paths follow `testing.md`).

| File | Cases it must have |
| --- | --- |
| `List{Aggregate}sHandlerTest` | default sort + no filter; a silent fallback for unknown values (sort/direction/filter/perPage); sorts by every value of the enum (dataset); desc; search (case-insensitive, escapes `%`/`_`, trims + echoes); each filter + echo; paging default 10 / every size / fallback; the row has every field; a flat envelope |
| `EloquentList{Aggregate}sQueryTest` | calls `listQueryContract()` + resolves from the port; sorts on the real column for every sort key (dataset); paging links point at the current request |
| `{Aggregate}Controller/IndexTest` | guest redirect to login; `assertInertia` `component('{aggregates}/index')` + `has('{aggregates}')` `has('sort')` `has('filters')` + `where('can.x', bool)` as a dataset of the users the policy answers differently |
| `tests/Browser/{Aggregates}/IndexTest` | renders with no JS error or console log; what the browser computes itself (money/rate/date formatting, badges); searching or filtering in the toolbar keeps `sort`/`direction` on the URL; the empty + no-results states; the row buttons' dialogs |
| `{Aggregate}Controller/DestroyTest` | guest redirect + `assertModelExists`; a user who may delete: `->from(index)->delete()->assertRedirect(index)` + `assertInertiaFlash('toast.type', 'success')` / `toast.title` / `toast.message` + `assertModelMissing`; a user who may not: `assertForbidden` + `assertModelExists` |

Name each file's helpers so no two files share one (`{aggregates}IndexActor`, `{aggregates}DestroyActor`). Pest shares one global namespace.

### 4. Verify

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan
php artisan test --compact --filter={Aggregate}
php artisan test --compact --testsuite=Architecture
npx tsc --noEmit
npm run build && php artisan test --compact tests/Browser/{Aggregates}
npx eslint <files you touched>
npx prettier --check <files you touched>
```

Then open the browser (`composer run dev` must be running; if a change does not show, ask the user): sort every sortable column · each filter and the search keep the current `sort`/`direction`/`per_page` · change the page size · select rows, then change page → the bulk bar goes away · every row button (its tooltip shows) · delete → the dialog has the name → toast · the empty state switches with and without filters · a hand-edited URL (`?sort=xxx&status=zzz`) still gets a page, not an error

---

## Don't

- Copy `<Table>` markup or call `useTable` yourself in a page. Use only `useDataTable` + `<DataTable>`.
- Validate the list's values in the controller or a FormRequest. The handler settles them and falls back (a hand-edited URL must get a page, not a 422).
- Let `DataTable` render a dialog. The page places every dialog itself, beside the table.
- Add `--repo` to a list use case, or return a domain entity from the read port.
- Generalise across lists (a shared Criteria, a `BackedEnum` sort, `array $filters`). Each list has its own types (`list-queries.md`).
- Use a spatie `Data` as a row.

## Closing checklist

**Spec**
- [ ] The user confirmed the spec summary table before any file was touched
- [ ] Every decision made without asking was shown to the user in the summary

**Backend**
- [ ] Scaffolded with `make:use-case --query`, without `--repo`
- [ ] Sort enum + `fromInput()` fallback
- [ ] The Row is `Arrayable`, not `Data`; `toArray()` covers every column in the spec, with `@return array{…}`
- [ ] The Criteria has no defaults, has `toSort()`/`toFilters()`, and `@param 'asc'|'desc'`
- [ ] The Handler settles every raw value; the Result echoes sort/filters
- [ ] The adapter `extends EloquentListQuery`, ends with `paginateRows()`, eager loads everything, and its test calls `listQueryContract()`
- [ ] The port's binding is in a provider
- [ ] Controller index/destroy scaffolded with `make:controller --only=index,destroy`, not written by hand; Policy + route

**Frontend**
- [ ] Scaffolded with `make:list-page`, not written from scratch
- [ ] The row type matches `toArray()` key by key, with `@see`
- [ ] The columns are in the component, with `DataTableFeatures`
- [ ] `useDataTable` + `<DataTable dt>`; row buttons from `useActions`; the page renders its own dialogs
- [ ] `query` has every key of the Filters + `sort`/`direction`/`per_page`; one `visit` that carries no `page`; the toolbar takes `dt` and wraps `<DataTableToolbar dt fields>`
- [ ] Translations complete in every `lang/*.json`

**Tests + verify**
- [ ] The 5 test files in the table above are all green (no `->todo()` left)
- [ ] pint / phpstan / tsc / eslint / prettier pass
- [ ] Checked in the browser per item 4, and the results reported as they are
