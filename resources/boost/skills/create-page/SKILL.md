---
name: create-page
description: "Build a create/edit form page end to end (Create{Aggregate} + Update{Aggregate} use cases, Store/Update{Aggregate}Request with toCommand(), {Aggregate}FormValues, resource controller create/store/edit/update, reusable Inertia React <Form> component + create/edit page shells, create button in the list header, Pest tests) on the form-pages rule, scaffolded by the workflow kit's make:use-case, make:form-request, make:controller and make:form-page. Activate when the user asks to create a create page, edit page, add form, new-record form, store/update endpoint, or CRUD create/edit for an aggregate; says ทำหน้าสร้าง, หน้าเพิ่ม, หน้าแก้ไข, ฟอร์มสร้าง {aggregate}, หน้า create, หน้า edit; or invokes /create-page. Starts by collecting the full form spec from the user through explicit questions — never guesses fields, validation, upload handling, or the post-save destination — then implements backend, frontend, and tests in order. Do not use for list pages (use /list-page) or detail (show) pages."
---

# Create Page

A create or edit page is one form that serves both pages. Its data takes the path in the `form-pages.md` guideline:

```
props.defaults ({X}FormValues) → <Form {...action}> → Store/Update{X}Request (authorize + rules) → toCommand() → handler → flash toast → to_route
```

This skill walks the whole line, backend → frontend → tests, with the kit's generators, then fills in what the generators cannot write: an enum select, fields shown by a condition, rules across fields, values converted before they are shown, and files.

If the project already has a finished form, open one set as a model (look for `components/*/form.tsx`). What the generator writes is the truth, though: when the two disagree, trust the generator and the guideline.

**Scope:** `create`/`store`/`edit`/`update`, the button that leads in from the list page, and one form component. Don't change the shared `DataTable`, beyond using its existing `headerActions` slot.

Read these rules before you touch a file: the `form-pages.md` guideline (the shared rules that `FormPagesTest` and ESLint enforce), `handlers.md`, `authorization.md`, `exceptions.md`, `numbers.md`, `testing.md`, and the project's `.ai/rules` when it has them (read `.ai/rules/index.md` to find the files that match the path).

---

## Step 0 — Collect the full form spec before you touch a file (a hard gate)

**Rule: never guess.** Every item below needs an answer from the user, or a clear reading of the existing code (the Entity's `create()` and the methods that change values, the migration, Enums, VOs, the Policy), before the first file is created.

How to ask:
- Use `AskUserQuestion` one set at a time (at most 4 questions per call), in order from set A to set D.
- Before each set, **read the code first** and offer what you find as options: the parameters of `{Aggregate}Entity::create()`, the migration's columns and unique keys, the enums in `app/Domain/**/Enums`, the VOs that throw on a malformed value (`X::from…()`), the VOs that hold a rule across fields, the methods in the Policy.
- When the user answers vaguely ("like the old one" / "whatever you think fits"), **ask again, narrower**: propose the values you will actually use and have them confirm each one.
- Don't start until every set is closed. Then **summarize the spec in one table for the user to confirm once** before step 1.

### Set A — Identity

- The aggregate / Model / context → namespace `App\Application\{Context}`, route `{aggregates}`, lang prefix `{aggregates}.`, pages `pages/{aggregates}/{create,edit}.tsx`, component `components/{aggregate}/form.tsx`
- Who may create and edit (Policy `create()`/`update()`), and whether the Policy exists or must be created (`make:policy`)
- Whether this aggregate's list page exists yet (the create button goes in the `headerActions` of `<DataTable>`, with `can.create` from the index controller)

### Set B — Fields

- Each field **one at a time**: its name (snake_case, matching the request), the Command parameter it comes from, the input type (`Input` text / `type="time"` / `Select` enum / `ToggleGroup` for several values / checkbox / image), whether it is required, and its starting value on create
- Which fields **cannot be changed** on the edit page (the form component takes `mode: 'create' | 'edit'` and hides them)
- Fields shown or hidden by another field (such as `days` by `frequency`). A field that does not apply under that condition must be `exclude`d on the PHP side.
- **Images and files**: uploaded directly as an `UploadedFile` in the Command, then written by the handler through the storage port (`form-pages.md`, section File uploads). If the project has its own file library, ask whether this field picks from the library instead.
- Every enum → its options in TS are a constant with `@see` to the PHP file, plus a translation `{aggregates}.{field}.{value}` in every `lang/*.json`

### Set C — Validation

- The table's unique key (composite? → `Rule::unique()->where(...)`, and `->ignore(...)` on update)
- The value format the domain VO accepts (for example `HH:mm` → `date_format:H:i`), so the FormRequest refuses before the domain throws
- The bounds an enum or VO holds (`minDay/maxDay`) → read them from the enum in `rules()`, never repeat the numbers
- Rules across fields the domain already holds → check them in `after()` by **reusing** the existing VO, and name the field the error goes to
- Validation always lives in the **FormRequest** (not spatie Data validation, not inline in the controller)

### Set D — After saving

- Where to redirect: the item's `show` (the default) or `index`
- Whether the toast `{aggregates}.created` / `{aggregates}.updated` carries `:name`
- Values converted before the edit page shows them (minutes → `HH:mm`, camelCase json → snake_case fields). Convert in `{X}FormValues::of()`, not in TS.

### What you decide yourself, without asking (but list it in the summary table)

File and class names by convention, the field order the user gave, grouping fields into Cards, translation keys under the prefix, test case and helper names, resetting child fields when their parent field changes.

### Spec summary table (the user confirms it before step 1)

| Topic | Value |
| --- | --- |
| Aggregate / Context / route / lang prefix | |
| Policy / who may create and edit | |
| Fields (name → input → Command param → starting value) | |
| Fields locked on the edit page | |
| Conditional fields | |
| Images / files | |
| Unique key | |
| Rules across fields (VO reused) | |
| After saving (redirect + toast) | |

---

## Workflow

### 1. Backend

If the project uses the structure manifest (`.kit/structure/`), design the use cases, controller methods and pages in the manifest first, then let `php artisan kit:apply` run the generators (the `structure.md` guideline). A step where apply stops and waits is the part a person fills in.

Order:

1. `php artisan make:use-case Create{Aggregate} --domain={Context} --repo={Aggregate} --command --creates --no-interaction` (the handler mints the id through `IdGenerator` and returns a `string`) and `php artisan make:use-case Update{Aggregate} --domain={Context} --repo={Aggregate} --command --no-interaction`. The id of the item being edited is a field of the Command (`id` or `{aggregate}Id`), never passed beside it (`handlers.md`).
2. Write the **Command** in full first. The type of each parameter is what the next generator guesses the rule from: string / `?string` / int / bool / enum / `array` / `UploadedFile`. A time is an `HH:mm` string that the handler turns into a VO. Money, rates and multipliers are a decimal string (`'1500.50'`) or a VO. **Never `float`** (numbers.md, test `no-float`).
3. Write the **Handler**: `{Aggregate}Entity::create(...)` → `$this->repo->save()` (create), or `getById()` → the entity's method → `update()` (update). Every handler that writes opens a `DB::transaction` and records an audit entry inside it (`audit-log.md`). Add `@throws` for every domain exception that can pass through.
4. `php artisan make:form-request {Aggregate} --domain={Context} --no-interaction` writes `Validates{Aggregate}`, `Store`/`Update{Aggregate}Request` and `{Aggregate}FormValues`. Then write on:
   - The rules the generator guesses are only a start. Add unique (`abstract protected function {x}UniqueRule(): Unique` in the trait, which each request writes itself), bounds from the enum, `exclude`, `after()` reusing the VO, and files with `File::image()/types()` and a size from `config()`.
   - `rules()` must `return [...]` as an array literal (the test reads the keys from it).
   - `toCommand()` uses `new {Name}Command(...)` with named args only. Never `::from([...])`.
   - Helpers that narrow a type (**never name one `image()`**: `Request::image()` already exists).
   - `{Aggregate}FormValues`: the keys of `toArray()` are the top-level keys of `Store{Aggregate}Request::rules()` (the `form-values` test checks this). Write `empty()` (the starting values on create) and `of($model)` (the stored values turned into what the inputs show) in full. `of()` throws `LogicException` until you write it.
5. **Controller**: `php artisan make:controller {Aggregate} --domain={Context} --only=create,store,edit,update --no-interaction` (add `--update=`/`--create=` when the use case is not named `Update{Aggregate}`/`Create{Aggregate}`). **Never write the methods yourself.** The generator adds these 4 methods to the existing controller (or creates it) in resource order, leaves the existing methods alone, and writes `CreateTest`/`StoreTest`/`EditTest`/`UpdateTest` with `->todo()`. Then fill in what it cannot know, following the warnings it prints (the missing `can` line of index, the ability, the translation keys):
   - `index`: `'create' => Gate::allows('create', Model::class)` in `can`
   - `create()`: `Gate::authorize('create', Model::class)` → `Inertia::render('{aggregates}/create', ['defaults' => {X}FormValues::empty()])`
   - `store(Store{X}Request $request, Create{X}Handler $handler)`: no second `Gate::authorize` → `$id = $handler($request->toCommand())` → `Inertia::flash(FlashToast::KEY, FlashToast::success(...))` as a statement → `return to_route('{aggregates}.show', $id)`
   - `edit($model)`: `Gate::authorize('update', $model)` → render with `'{model}' => $model` (breadcrumb + `update.form()`) and `'defaults' => {X}FormValues::of($model)`
   - `update(Update{X}Request $request, $model, Update{X}Handler $handler)`: `$handler($request->toCommand())` → flash → `to_route('{aggregates}.show', $model)`
   - Never build a Command in the controller, and never open a transaction there.
6. Every `lang/*.json` (keys sorted): `{aggregates}.create` (the button), `form_title`, `create_title`, `create_description`, `edit_title`, `edit_description`, `created`/`updated` (`:name`), the label of every field, and `validation.{rule}` for errors from `after()`
7. `php artisan wayfinder:generate --with-form`

### 2. Frontend

Order:

1. `php artisan make:form-page {Aggregate} --no-interaction` reads the `@return array{…}` of `{X}FormValues::toArray()`, then writes the type `{X}FormValues` into `types/{aggregate}.ts`, `components/{aggregate}/form.tsx` (one input per key), the `create.tsx`/`edit.tsx` shells, and the Browser tests `tests/Browser/{Aggregates}/CreateTest.php` + `EditTest.php` with `->todo()`. It reports the route and the translations still missing.
2. You may narrow the type in `types/` (an enum as a string union), but its keys must match PHP.
3. Shape the form component:
   - Keep `<Form {...action}>` uncontrolled (`defaultValue={defaults.x}`). Use `useState` only for a parent field that controls what shows, a ToggleGroup/Checkbox value that must post through a hidden input, and a value a widget picks and writes into a hidden input.
   - Enum: `<Select name defaultValue>` from a constant `X_OPTIONS` with `@see`, and `t('{aggregates}.{field}.${value}')`
   - Several values: `<ToggleGroup type="multiple">` + one `<input type="hidden" name="x[]">` per value. Read the error as `errors.x ?? errors['x.0']`.
   - Time: `<Input type="time">` sends `HH:mm` as is.
   - Money and rates: `<Input inputMode="decimal">` takes the decimal string as is. A total shown before submitting uses `addMoney`/`subtractMoney` from `@/lib/numbers`.
   - Fields that cannot be changed: the prop `mode: 'create' | 'edit'`
   - Never `useForm` / `useHttp` / `router` / `fetch` (ESLint `form-only`)
4. The create and edit pages are shells: they take `defaults` from the props and pass it to the form. They hold no `<Form>` or input, and build no starting values (ESLint `page-shell`, test `pages`). The edit page narrows the type of its model prop to `{X}Detail` when there is one.
5. `pages/{aggregates}/index.tsx`: `can.create` in Props → `<DataTable headerActions={can.create ? <Button asChild><Link href={create()}><Plus />{t('{aggregates}.create')}</Link></Button> : undefined} />`. **Not `toolbar`.**

### 3. Tests

The generators have already scaffolded the test files with `->todo()`. Write in those files, never create a second one (paths follow `testing.md`).

| File | Cases it must have |
| --- | --- |
| `Create{X}HandlerTest` / `Update{X}HandlerTest` | persists every field + the audit entry; conditional fields are null; two runs give different ids (create); every domain exception that can pass through (dataset); a duplicate unique → `expectFailedWrite(...)`; a repo throw passes through and is not swallowed |
| `{Aggregate}Controller/CreateTest` | guest → login; a role that may write → `component('{aggregates}/create')` + `where('defaults', [...])` for every key; a role without the right → `assertForbidden` |
| `{Aggregate}Controller/EditTest` | as Create, plus `defaults.*` holds the converted values of that row; an unknown id → 404 |
| `{Aggregate}Controller/StoreTest` / `UpdateTest` | helper `valid{X}Payload($overrides)`; success → `assertRedirect(show)` + `assertInertiaFlash('toast.*')` + every column; a validation dataset `[overrides, field]` → `assertInvalid([$field])` + the count unchanged; edge cases that **pass**; a role without the right, both ways (403 / `X-Inertia` → error toast); a smuggled field is dropped (update) |
| `{Aggregate}Controller/IndexTest` | `can.create` per role |
| `tests/Browser/{Aggregates}/CreateTest` / `EditTest` (already written by the generator) | renders with no JS error (edit: shows the stored values); fill in and save → reaches the destination + shows the toast; an invalid value → the error under the right field; conditional fields hide and show |

Name each file's helpers so no two files share one (`{aggregates}CreateActor`, `{aggregates}StoreActor`). Pest shares one global namespace.

### 4. Verify

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact --testsuite=Architecture
php artisan test --compact --filter={Aggregate}
php artisan wayfinder:generate --with-form && npx tsc --noEmit
npx eslint <files you touched>
npx prettier --check <files you touched> lang/*.json
npm run build && php artisan test --compact --testsuite=Browser
```

Then check against the real dev server (`composer run dev`): the create button shows only for roles that may create · the create and edit pages render with their starting values · conditional fields hide and show · save → reaches the show page + toast · an invalid value gets its error at the right field · **delete the test rows** when done

---

## Don't

- Validate in the controller or on spatie Data. Only the FormRequest validates.
- Build a Command in the controller, or use `Command::from([...])`. Use `$request->toCommand()`, built with `new` and named args.
- Make `Update{X}Request extends Store{X}Request`. Their `toCommand()` return different Command classes, so share the trait `Validates{X}` instead.
- Call `Gate::authorize` again in `store()`/`update()`. Authorization lives in the request's `authorize()`.
- Build the form's starting values in TS (`EMPTY_VALUES`, `toFormValues()`). They come from `{X}FormValues` as the `defaults` prop.
- Write a new rule across fields when the domain already has a VO for it, or repeat bound numbers the enum already gives.
- Use `required_unless` for a field that does not apply under a condition. Use `exclude`.
- Store or move a file, or call `Storage::`, in `app/Http`. The handler does it through the storage port.
- Use `Inertia::flash(...)->route(...)` or `->with(...)`. Flash as a statement, then `to_route()`.
- Use `useForm` or make every field controlled. Keep `<Form>` uncontrolled and use `useState` only where needed.
- Call setState inside `useEffect` (eslint `react-hooks/set-state-in-effect`).
- Put the create button in the DataTable's `toolbar`. Use `headerActions`.

## Closing checklist

**Spec**
- [ ] The user confirmed the spec summary table before any file was touched
- [ ] Every decision made without asking was shown to the user in the summary

**Backend**
- [ ] Scaffolded with `make:use-case` + `make:form-request`; the create handler returns the id
- [ ] FormRequest: `authorize()`, rules as a literal, unique by key, `exclude`, `after()` reusing the VO, `attributes()`, `toCommand()` with `new`
- [ ] `{X}FormValues` keys match the rules; `empty()`/`of()` written in full
- [ ] Controller scaffolded with `make:controller --only=create,store,edit,update`, not written by hand, then filled in from the generator's warnings; `can.create` in index
- [ ] Translations complete in every `lang/*.json`; wayfinder regenerated

**Frontend**
- [ ] Scaffolded with `make:form-page`; the type in `types/` has `@see`
- [ ] One form component serves both pages, uncontrolled, with state only where needed
- [ ] create/edit are shells that take `defaults`; the create button is in `headerActions`

**Tests + verify**
- [ ] Handler / Create / Edit / Store / Update / Index / Browser are all green (including the edge cases that **pass**, and no `->todo()` left in the Browser tests)
- [ ] Architecture (`FormPagesTest`) / pint / phpstan / tsc / eslint / prettier / Browser pass
- [ ] Checked against the dev server per step 4, the results reported as they are, and the test data deleted
