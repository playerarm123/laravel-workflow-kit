# Actions

An action is anything a user does to a row that is not create, edit or delete: cancel, publish, credit, change a status. Each action takes one path:

```
component button / <Form {...action.form(model)}>
  → POST {prefix}/{param}/{verb}                         {prefix}.{verb}
  → {Model}{Verb}Controller::__invoke({Model}{Verb}Request $request, {Model} $model, {UseCase}Handler $handler)
  → $handler($request->toCommand())
  → Inertia::flash(FlashToast::KEY, FlashToast::success|error(…))->back()

bulk, from a list page's selection:
  → POST {prefix}/{verb}                                 {prefix}.bulk-{verb}
  → {Model}Bulk{Verb}Controller({Model}Bulk{Verb}Request) → the row action's handler
```

Enforced by `tests/Architecture/ActionsTest.php` and by the ESLint rules in `tests/ESLint/actions.js`, which `eslint.config.js` spreads in. The spec in `actionsSpec()` is the machine-checked copy of this file: change the two together. `tests/Architecture/ActionsEslintTest.php` proves the ESLint rules against fixtures. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Kit files

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`), and a stub there is replaced by a file of the same name in the project's `stubs/`. Copy the rest into a new project as they are.
- `tests/ESLint/actions.js`, imported by `eslint.config.js` (*check `eslint`*)
- `tests/ESLint/Support/rules.js`, which every ESLint kit file reads its overrides and shared helpers through
- *package:* `src/Console/Commands/MakeActionCommand.php` with `src/Console/Commands/Concerns/BuildsRequestFields.php`, which it shares with `make:form-request`
- *package:* `stubs/action-controller.stub`, `stubs/action-bulk-controller.stub`, `stubs/action-request.stub` and `stubs/action-test.stub`

## The controller

```php
class LotteryDrawCancelController extends Controller
{
    public function __invoke(LotteryDrawCancelRequest $request, LotteryDraw $lotteryDraw, CancelLotteryDrawHandler $cancelLotteryDraw): RedirectResponse
    {
        if ($cancelLotteryDraw($request->toCommand()) === 0) {
            return Inertia::flash(FlashToast::KEY, FlashToast::error(__('lottery-draws.cancel_refused')))->back();
        }

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('lottery-draws.cancelled')))->back();
    }
}
```

**Do**
- Give each action an invokable controller of its own, named `{Model}{Verb}Controller`. `{Model}` is the class of the route model it takes. A bulk action takes no route model and is named `{Model}Bulk{Verb}Controller`. Its only public method is `__invoke()`, which returns a `RedirectResponse`. *Check `shape`.*
- Name the action with a verb: `Cancel`, `Publish`, `Credit`, `ChangeStatus`. Never a noun such as `Status` or `Result`. *Review only.*
- Take one FormRequest, named after the controller: `app/Http/Requests/{Model}/{Model}{Verb}Request.php`. Its `authorize()` asks the policy (authorization.md), and its `toCommand()` builds the handler's Command. *Check `request`.*
- Read the request only through `$request->toCommand()`. A value the toast needs comes from the route model or from the Command. *Check `request`.*
- Catch the refusal the user can act on by name, and answer it with `FlashToast::error()` (exceptions.md).
- Answer with `->back()`. The button sits on a show page or a list row, and the list keeps its filters, sort and page in the URL. *Check `shape`.*

**Don't**
- Add an action as an extra method on a resource controller. A method other than the seven resource ones that takes a FormRequest is an action in the wrong home. *Check `shape`.*
- Redirect elsewhere with `to_route()` or `redirect()`. *Check `shape`.*

**Why:** one controller per action keeps its request, its policy ability and its test in files that share one name. A reader finds every part of "cancel a draw" by searching for `LotteryDrawCancel`. When an action is one more method on a resource controller, its FormRequest, test and route go by different names.

## The route

```php
Route::post('lottery-draws/cancel', LotteryDrawBulkCancelController::class)->name('lottery-draws.bulk-cancel');
Route::post('lottery-draws/{lottery_draw}/cancel', LotteryDrawCancelController::class)->name('lottery-draws.cancel');
```

**Do**
- Register every action with `Route::post`. *Check `route`.*
- Spell the verb in kebab case, the same in the URI and the route name:
  - a row action is `{prefix}/{param}/{verb}`, named `{prefix}.{verb}`;
  - a bulk action is `{prefix}/{verb}`, named `{prefix}.bulk-{verb}`.

  *Check `route`.*

**Don't** register `Route::patch()` or `Route::put()` outside `Route::resource()`. *Check `route`.*

**Why:** an action is a command, not a new state of the resource, so it has one verb. A resource registers no `POST {prefix}/{param}`, so a bulk URI such as `POST {prefix}/cancel` can never be read as a row. The route order stops mattering.

## A row action and its bulk twin

When a list page can act on many rows at once, the row action and the bulk action call **one** handler.

**Do**
- Give that use case a Command with `ids: list<string>` (plus the action's own fields). Its handler returns an `int`, the number of rows it really changed, and skips a row it may no longer change: for a status, one whose `canBecome()` refuses the target (states.md). *Check `bulk-handler`.*
- In the row controller, pass `[$model->id]` from the request's `toCommand()`. Answer a `0` with an error toast: someone changed the row between the page load and the click.
- In the bulk controller, compute `skipped = count($command->ids) - $changed` and choose one of three toasts: all changed, some changed (both numbers), none changed (an error). *Review only.*
- Authorize the bulk request against the class with `{ability}Any`, the same answer the list page uses to show the bulk button (authorization.md).

**Why:** two handlers for one action drift. The bulk one then skips a rule that the row one checks. Counting what really changed tells the user about the rows a race already took, and nothing is silently undone.

## The page side

**Do**
- Call an action from a component in `resources/js/components/{aggregate}/`, never from a page. *ESLint check `action-home`.*
  - An action with no fields of its own (a confirm dialog, a toggle) calls `router.post(action.url(model), {…}, { preserveScroll: true })`.
  - An action with fields renders a dialog with `<Form {...action.form(model)}>`, as form-pages.md describes for a form component.
- Take every url from Wayfinder (`action.url(…)`, `action.form(…)`). *ESLint check `wayfinder-url`.*
- Send the target value, never "toggle": `status: 'inactive'`, `is_favorited: false`. If someone else already changed the row, the handler then refuses the change and does not flip the row back. *Review only.*

**Don't** call `router.patch()` or `router.put()` anywhere. An update posts through `<Form {...update.form(model)}>` (form-pages.md), and an action posts. *ESLint check `post-only`.*

**Why:** a page that calls the router for an action becomes a second place that knows the action's url and payload. A string url survives a renamed route and then fails only when someone clicks it.

## Tests

An action controller is invokable, so its test sits at `tests/Feature/Http/Controllers/{Model}{Verb}ControllerTest.php` (testing.md). It covers a guest, a user the policy refuses (403), the success toast, each validation rule, each refusal the controller catches, and the `0` count of a row action.

## Scaffold, never hand-write

1. Write the use case with `php artisan make:use-case {UseCase} --domain={Context} --command`, and fill in its Command. A use case with a bulk twin takes `ids` and returns `int`.
2. Run `php artisan make:action {Verb} --model={Model} --domain={Context}`. Add `--bulk` for the bulk twin. Add `--use-case=` when the use case is not `{Verb}{Model}`.

The command reads the Command and writes three files:
- the controller;
- the request, with rules guessed from the Command's types and `toCommand()` filled in. The route model's id fills `{model}Id`, or `ids` as `[$model->id]`. A bulk request validates `ids`;
- the test, with a todo for each case.

It also prints the route line, the policy ability that is still missing and the translation keys still missing. *Review only.*

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "actions"`, exactly as stack.md describes. The `subject` is the controller class for a PHP check, the route file for a `route` check on a verb, and the file's path from the project root for an ESLint check. Only the user may add an entry.
