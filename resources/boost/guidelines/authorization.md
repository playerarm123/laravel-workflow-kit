# Authorization

Who may do what is decided in one place, the model's policy. Every entry point asks it the same way:

```
GET page        → controller: Gate::authorize('{ability}', $model | Model::class)  → {Model}Policy → 403
write / action  → FormRequest::authorize(): return Gate::allows('{ability}', …)     → {Model}Policy → 403, before any 422
page buttons    → controller: 'can' => ['{ability}' => Gate::allows('{ability}', …)] → the page shows or hides
```

A denial is a 403, and `ExceptionResponses` answers it (exceptions.md).

Enforced by `tests/Architecture/AuthorizationTest.php` (`php artisan test --testsuite=Architecture`). The spec in `authorizationSpec()` is the machine-checked copy of this file: change the two together. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Policies

```php
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model { /* … */ }

class CustomerPolicy
{
    public function before(User $user, string $ability): ?bool { /* false shuts every ability, null asks the method */ }

    public function viewAny(User $user): bool { /* … */ }
    public function update(User $user, Customer $customer): bool { /* … */ }
    public function updateAny(User $user): bool { /* the list's button, before there is a row */ }
}
```

**Do**
- Put one policy per model at `app/Policies/{Model}Policy.php`, and attach it with `#[UsePolicy({Model}Policy::class)]` on the model. *Check `policies`.*
- Give every ability the user as its first parameter, and return `bool` (or an `Illuminate\Auth\Access\Response` to carry a deny message). `before()` returns `?bool`. *Check `policies`.*
- Pair a row ability with an `{ability}Any` that takes no model, when a list page must decide on a button before it has a row (`deleteAny`, `updateAny`). *Review only.*
- Read only the user and the model the Gate passes in. A policy never calls a use case, a repository or a port (layers.md). *Review only.*
- Declare only abilities something in `app/Http` asks of the policy's model, through `Gate::authorize()`, `Gate::allows()`, `Gate::forUser(…)->allows()` or a closure that wraps one. Laravel denies an ability a policy does not declare, so a stub that returns `false` adds nothing. A helper another ability calls is private. *Check `unused-ability`.*
- Test every policy in `tests/Feature/Policies/{Model}PolicyTest.php`, through `Gate::forUser($user)->allows(…)`, with each ability allowed and denied. *Check `tests`.*

**Don't**
- Register an ability or a policy any other way: no `Gate::define()`, `Gate::policy()`, `Gate::before()` or `Gate::after()`. *Check `idiom`.*
- Keep a business rule only in the policy. The policy hides the button and answers 403. The rule that must hold when two requests race lives in the entity, and the controller catches its refusal (write-path.md, exceptions.md). *Review only.*

**Why:** with one policy per model, attached where the model is declared, a reader finds every decision about that model in one file. An ability that no policy answers is denied silently, so a renamed method hides a button and nothing turns red.

## Asking the policy

**Do**
- Authorize every controller action. *Check `actions`.*
  - An action that renders or reads calls `Gate::authorize('{ability}', $model)` (or `Model::class`).
  - An action that writes takes a FormRequest, and its `authorize()` returns `Gate::allows('{ability}', …)`. Laravel answers 403 before it validates, so a refused user never learns which fields were wrong.
- Return `Gate::allows(…)` and nothing else from every `authorize()`. Never `return true`. *Check `requests`.*
- Ask only an ability the subject's policy declares. *Check `abilities`.*

**Don't**
- Authorize an action twice. Once the FormRequest asks, the controller does not. *Check `once`.*
- Read the signed-in user in a controller or a FormRequest (`$request->user()`, `$this->user()`, `Auth::user()`, `auth()->id()`). Whether the user may act is the policy's question. Who is acting is the handler's, read from `UserContext` (handlers.md). *Check `user-read`.*
- Authorize in another idiom: `$this->authorize()`, `authorizeResource()`, `$user->can()`, `Gate::denies()`, `Gate::check()`, `Gate::inspect()`, `can:` middleware, or `@can`. *Check `idiom`.*
- Ask the Gate outside `app/Http`. The application and the domain never see the framework's Gate (layers.md). Work the system runs has no user to ask about. *Check `idiom`.*
- Check a role, a flag or an ownership column anywhere but a policy, in PHP or in TypeScript. *Review only.*

**Why:** each idiom answers a denial in its own way. The `can:` middleware answers before the route model is bound, and `$this->authorize()` needs a trait. One idiom means one place to look for each kind of action, and a denial always reaches `ExceptionResponses` the same way.

## What the page may do: `can`

The server decides which buttons a page shows. It sends `can`, and the page reads it.

```php
return Inertia::render('customers/index', [
    'customers' => $result->customers,
    'can' => [
        'create' => Gate::allows('create', Customer::class),
        'update' => Gate::allows('updateAny', Customer::class),
        'delete' => Gate::allows('deleteAny', Customer::class),
        'createMediaItem' => Gate::allows('create', MediaItem::class),
    ],
]);
```

**Do**
- Write `can` inline in the controller, as one `Gate::allows()` per key. *Check `can-props`.*
- Name each key after the ability it asks, and drop the `Any` suffix: `update` asks `update` or `updateAny`. When the ability belongs to another model, add the model's name: `createMediaItem`. *Check `can-props`.*
- Ask the row ability with the model on a show page, and the `{ability}Any` with the class on a list page. *Review only.*
- Read `can.{key}` on the page to show or hide a button. Never decide from the user's role or id in TypeScript. *Review only.*

**Why:** a key that asks a different ability (`update` answered by `create`) shows the button to a user the action then refuses. When each key repeats its ability, a reader sees that drift on one line.

## Self-service routes

The starter kit's settings pages (`App\Http\Controllers\Settings`, `App\Http\Requests\Settings`) act on the signed-in user's own account. They have no model to authorize against, so the checks skip them, and they may read `$request->user()`. *Review only:* they never touch any row but the user's own.

## Kit files

These live at fixed paths. Copy them into a new project as they are. *Check `kit-files`.*
- `app/Console/Commands/MakePolicyCommand.php`, which takes over Laravel's `make:policy`
- `stubs/policy.stub` and `stubs/policy-test.stub`

## Scaffold, never hand-write

Run `php artisan make:policy {Model}`. It reads the model from the name (or `--model=`) and writes:
- `app/Policies/{Model}Policy.php`, with `viewAny`, `view`, `create`, `update` and `delete` all denying
- `tests/Feature/Policies/{Model}PolicyTest.php`, with a todo for each ability allowed and denied

Then add the `#[UsePolicy]` the command names to the model, write the abilities, delete the ones nothing asks (the `unused-ability` check names them), and fill in the tests.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "authorization"`, exactly as stack.md describes. Only the user may add an entry.
