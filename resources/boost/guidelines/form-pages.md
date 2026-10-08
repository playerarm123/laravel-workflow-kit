# Form pages

A create or edit page writes through one path:

```
props.defaults ({X}FormValues) → <Form {...action}> → Store/Update{X}Request (authorize + rules) → toCommand() → handler → flash toast → to_route
```

The server owns the values on both ends. It sends the form its starting values, and it turns what comes back into a Command. The page and the form component only render.

Enforced by the package's `tests/Architecture/FormPagesTest.php` and by the package's ESLint rules in `tests/ESLint/form-pages.js`, which `eslint.config.js` imports from `vendor/` and spreads in. The spec in `formPagesSpec()` is the machine-checked copy of this file: change the two together. The package's `tests/Architecture/FormPagesEslintTest.php` proves the ESLint rules against fixtures.

## Kit files

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`). `php artisan kit:install` writes the rest from the kit, and the check fails when one is missing or differs from the kit's copy.
- `app/Http/FlashToast.php`, the one shape of a flash toast (`FlashToast::KEY`, `success()`, `error()`)
- `resources/js/hooks/use-flash-toast.ts` and `resources/js/components/flash-toast.tsx`, rendered once in `app.tsx` beside sonner's `<Toaster>`
- `resources/js/components/input-error.tsx`
- `resources/js/types/ui.ts`, which declares the `FlashToast` type
- *package:* `tests/ESLint/form-pages.js`, imported by `eslint.config.js` (*check `eslint`*), and the fixtures its proof lints in `tests/ESLint/Fixtures/`
- *package:* `tests/ESLint/Support/rules.js`, which every ESLint kit file reads its overrides and shared helpers through. Its `internalAlias`, spread in first, ranks an unresolved `@/` import as internal, so a generated page passes import/order before `wayfinder:generate` runs

The kit builds on these shadcn components: button, card, checkbox, input, label, select, spinner. Every `lang/*.json` carries the keys the kit speaks: `common.save`, `common.cancel`, `common.back`, `common.toast_success_title`, `common.toast_error_title`. *Check `lang-keys`.*

## Scaffold, never hand-write

1. `php artisan make:use-case Create{X} --domain={Context} --command --creates --repo={X}`, and `Update{X}` with `--command --repo={X}`.
2. `php artisan make:form-request {X} --domain={Context}` reads both Commands and writes `Store{X}Request`, `Update{X}Request` and `{X}FormValues`. Fill in the rules, `toCommand()` and `of()`.
3. `php artisan make:controller {X} --domain={Context} --only=create,store,edit,update` writes those four methods in the shape below, and adds them to a controller that already exists without touching its other methods. It writes a test per action with a todo for each case. Then fill in what it cannot know: a toast's `:name`, extra props, `can` keys for other models, and the refusals to catch. *Review only.*
4. `php artisan make:form-page {X}` reads the `@return array{…}` of `{X}FormValues::toArray()` and writes the TypeScript type, the form component and both page shells. Then lay out the fields and add translations. *Review only.*

## The controller

```php
public function create(): Response
{
    Gate::authorize('create', Customer::class);

    return Inertia::render('customers/create', ['defaults' => CustomerFormValues::empty()]);
}

public function store(StoreCustomerRequest $request, CreateCustomerHandler $createCustomer): RedirectResponse
{
    $id = $createCustomer($request->toCommand());

    Inertia::flash(FlashToast::KEY, FlashToast::success(__('customers.created')));

    return to_route('customers.show', $id);
}
```

**Do**
- Authorize `create` and `edit` with `Gate::authorize()`, and render the page with `defaults`: `{X}FormValues::empty()` for create, `{X}FormValues::of($model)` for edit.
- Give `store` and `update` a FormRequest. Authorize in its `authorize()`, never again in the controller, so a refused user gets 403 before any 422 (authorization.md). *Check `store-update`.*
- Pass the handler `$request->toCommand()`. *Check `store-update`.*
- Flash with `Inertia::flash(FlashToast::KEY, FlashToast::success|error(…))` as a statement of its own, then return `to_route(…)`. `Inertia::flash()` chains only `->back()`.
- Catch a refusal the handler throws by name and answer with `FlashToast::error()` (exceptions.md). A missing row, a forbidden user and a failure are answered by `ExceptionResponses`, so the controller catches none of them.

**Don't**
- Build a Command in a controller, with `new` or `::from()`. A `List{Name}Command` is the one exception, because a list takes raw strings with no FormRequest (list-queries.md). *Check `commands`.*
- Flash with `->with()`, `session()->flash()` or `Session::flash()`. The toast hook reads `FlashToast::KEY` only, and the title is translated in PHP. *Check `flash`.*
- Open a transaction (handlers.md).

The starter kit's settings controllers (`App\Http\Controllers\Settings`) update the signed-in user's own account beside Fortify, with no use case to hand a Command to, so the `store-update` check skips them (authorization.md, Self-service routes).

## The FormRequest

**Do**
- Authorize through `Gate`, against the class for store and the bound model for update.
- Return an array literal from `rules()`, so the check can read its keys. Compute what the rules depend on before the `return`, and send a field that does not apply to `['exclude']`.
- Declare `toCommand(): {Name}Command`, built with `new {Name}Command(…)` and named arguments, so PHPStan checks every argument. *Check `to-command`.*
- Convert in `toCommand()`: snake_case input to camelCase arguments, strings to enums with `Enum::from()` (the rules already proved the value), and the bound model's id into the Command's id field. Read the model through a typed accessor.
- Share the fields of `Store{X}Request` and `Update{X}Request` through a trait beside them (`Validates{X}`: `rules()`, `after()`, `attributes()`, input helpers) when the two forms are the same. Each request keeps its own `authorize()`, its unique rules (`->ignore(…)` on update) and `toCommand()`. *Review only.*

**Don't**
- Use `Command::from([...])`. Its array keys and types are invisible to PHPStan, so a renamed argument fails only at runtime.
- Make `Update{X}Request` extend `Store{X}Request`. Their `toCommand()` return different Commands, which PHP refuses as an override.

**Why:** the request is the one class that knows both the names on the wire and the types the use case wants. When it hands over a typed Command, the controller has nothing left to get wrong.

## Form values

`app/Http/Requests/{X}/{X}FormValues.php`, beside the requests it feeds:

```php
final class CustomerFormValues implements Arrayable
{
    public function __construct(public readonly string $name, public readonly string $type /* … */) {}

    public static function empty(): self { /* the create page's starting values */ }

    public static function of(Customer $customer): self { /* the stored row as the form shows it */ }

    /** @return array{name: string, type: string} */
    public function toArray(): array { return ['name' => $this->name, 'type' => $this->type]; }
}
```

**Do**
- Make it `final` and `implements Arrayable`, with `static empty()` and `static of({Model})`. *Check `form-values`.*
- Spell every key out in `toArray()`, snake_case, with a `@return array{…}` that names each key's type. The generator writes the TypeScript type from it.
- Send each value the way its input shows it (`HH:mm` for a time, a decimal string for an amount or a rate, numbers.md), so the form never converts.
- Match the request on the wire. The keys of `toArray()` are exactly the top-level keys of `Store{X}Request::rules()` (`items.*.name` counts as `items`), and every key of `Update{X}Request::rules()` is among them. *Check `form-values`.*
- Send `null` for a file field. The stored file goes to the page as a separate prop, its url read through the same storage port the handler writes with (its adapter calls `Storage::url()`).

**Why:** the edit form posts back what it was given. When the starting values and the rules share their keys, a field the server forgot to send, or one the form sends that nothing validates, fails a test instead of a user.

## Values mirror the server

Every `{X}FormValues` has a TypeScript twin in `resources/js/types/{aggregate}.ts`. *Check `form-values`.*
- It is named `{X}FormValues` and carries `@see` with the PHP class's full name.
- Its keys are exactly the keys of `toArray()`.

## Pages

`resources/js/pages/{aggregates}/create.tsx` and `edit.tsx` are shells: `<Head>`, breadcrumbs through `setLayoutProps()`, `<Heading>`, a back link, and the form component.

**Do**
- Take `defaults: {X}FormValues` from the props and pass it on. *Check `pages`.*
- Render the form component from `@/components/{aggregate}/form`, with `action` from Wayfinder (`Controller.store.form()`, `Controller.update.form(model)`), `submitLabel` and `cancelHref`. *Check `pages`.*

**Don't** render `<Form>`, a `<form>` or an `<input>` in a page, import `useForm`, `useHttp` or `router` there, or build starting values in TypeScript. *ESLint check `page-shell`.*

## The form component

`resources/js/components/{aggregate}/form.tsx` renders one form for both pages.

**Do**
- Submit with Inertia's `<Form {...action}>` and leave inputs uncontrolled: `defaultValue={defaults.x}`, `name` set to the request's key.
- Hold state with `useState` only for a value the form must know while the user types: a select that shows or hides other fields, or a widget that writes hidden inputs. *Review only.*
- Show each error under its field with `<InputError message={errors.x} />`. Read an array's error as `errors.x ?? errors['x.0']`.
- End with the submit button (disabled and showing `<Spinner />` while `processing`) and a cancel `<Link>` to `cancelHref`.
- Take `action`, `defaults`, `submitLabel` and `cancelHref`. Add `mode: 'create' | 'edit'` only when the fields differ between the two pages. *Review only.*

**Don't** import `useForm`, `useHttp` or `router`, or call `fetch`, in a form component. *ESLint check `form-only`.*

**Why:** `<Form>` posts what the inputs hold and hands back `errors` and `processing`, so a form has no state to drift from what it submits. A second form for the edit page would drift from the first.

## File uploads

**Do**
- Put an `<input type="file" name="…">` inside the `<Form>`. Inertia sends multipart by itself, and Wayfinder's `update.form()` posts with `_method`, so files reach an update too.
- Validate with `File::image()` or `File::types([…])`, taking the size limit from `config()`.
- Pass the `UploadedFile` to the handler inside the Command (layers.md allows `Illuminate\Http\UploadedFile` in the application).
- Store it in the handler, through an application port whose adapter writes to `Storage::disk(config(…))` (deployment.md). When the database write after it fails, the handler deletes the file it wrote. *Review only.*

**Don't** touch a file in `app/Http`: no `->store()`, `->storeAs()`, `->storePublicly()`, `->move()` or `Storage::`. A url the page needs comes from the storage port too. *Check `uploads`.*

**Why:** a file written by the controller escapes the handler's transaction, so a failed save leaves an orphan behind. It also skips the disk that config chooses, which breaks on Laravel Cloud.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "form-pages"`, exactly as stack.md describes. An ESLint check is overridden per file: the `subject` is the file's path from the project root. Only the user may add an entry.
