# Exceptions

An exception names a reason. A response is chosen for it in one of two places:
- the controller that can tell the user what to do next
- `ExceptionResponses`, for everything else

```
Domain / Application throw {Specific}Exception
  ├─ web controller catches the one the user can act on → FlashToast::error(__('…')) → back() / to_route()
  ├─ API controller catches the one the user can act on → ApiError::refused('snake_code', __('…'))   409
  └─ nobody catches it → ExceptionResponses
       EntityNotFoundException                         → 404, not reported
       a JSON request                                  → ApiError: {message, code}
       403 on an Inertia visit                         → error toast, back()
       404 on an Inertia write                         → toast common.not_found, back()
       419 on an Inertia visit                         → toast common.page_expired, back()
       403 / 404 / 500 / 503 while app.debug is false  → the `error` page
       anything else                                   → Laravel's own response, a 500 reported with its context
```

Enforced by the package's `tests/Architecture/ExceptionsTest.php` (`php artisan test --testsuite=Architecture`). The spec in `exceptionsSpec()` is the machine-checked copy of this file: change the two together. Every branch of the diagram is proven in `tests/Feature/Http/ExceptionResponsesTest.php`.

## Four kinds

The base class says what the caller does with an exception.

| Kind | Base | Means | Answered by | Reported |
|---|---|---|---|---|
| Refusal | `{Context}DomainException` (a subclass of `DomainException`), or `ApplicationException` | a business rule says no, and the user can act on it | the controller that catches it, by name | no. One that slips through is a 500 and is reported, because a catch is missing. |
| Not found | `EntityNotFoundException` | the row is gone, or never was | `ExceptionResponses` (404). A controller catches it only to say something more specific. | no |
| Invalid value | `DomainValueException` | the caller's bug: the FormRequest should have stopped the value | nobody: a 500 | yes |
| Failure | `RepositoryException`, `ListQueryException`, `AuditLogException` | the system failed | nobody: a 500 | yes, with its context |

**Do** root every refusal in an aggregate's `Exceptions/` folder in its context's `{Context}DomainException`, never in `DomainException` itself. A domain service's own exception is the one refusal that extends `DomainException` directly. *Check `refusal-base`.*

**Don't** catch an invalid value or a failure in an entry point (`uncatchable` in the spec). *Check `catch-kind`.*

**Why:** each kind needs a different reaction. A refusal is an answer for the user. A not found is a stale link. An invalid value or a failure is a bug that must reach the log. A catch that turns a bug into a polite toast hides it from everyone who could fix it.

## Context

An exception's `context()` is written for the log. Laravel adds it to the log entry when it reports the exception. The user never sees it.

**Do**
- Build the context once, in the exception's named constructor, and pass it to the constructor's `context:`. Only the bases (`DomainException`, `ApplicationException`) define `context()`. *Check `context-override`.*
- Carry ids, enum values, amounts and codes. Anything that names or reaches a person stays out: names, usernames, emails, phones, passwords, notes, addresses, account numbers, tokens and secrets. The policy is the one `{X}LogPayload` follows (repositories.md). *Check `context-keys`.*
- Pass the caught exception on as `previous` when a catch throws a new one. *Check `previous`.*
- Add the actor once per request, in the middleware that binds `UserContext`: `Context::add('actor_id', $user?->getAuthIdentifier())`. Laravel attaches it to every log entry and every queued job, so no exception carries the actor (handlers.md). *Check `request-context`.*

**Don't** read `getMessage()` or the context in `app/Http`, outside the kit. A controller builds its message from a typed getter on the exception (`entity()`, `usages()`) and a translation key. *Check `message-leak`.*

**Why:** a `context()` override that reads a property the named constructor did not set throws while the handler reports. The original exception is then lost, and that is the worst way to fail. Personal data in a log is a leak that outlives the bug it was logged for. An exception without its `previous` loses the trace that says where the failure started.

## Kit files

These live at fixed paths. `php artisan kit:install` writes them from the kit, and *check `kit-files`* fails when one is missing or differs from the kit's copy, so a change to one is a change to the kit.
- `app/Http/ExceptionResponses.php`. `ExceptionResponses::register($exceptions)` is called inside `withExceptions()` in `bootstrap/app.php`. A provider calls `Inertia::handleExceptionsUsing(ExceptionResponses::respond(...))`. Laravel keeps a single `respondUsing` slot, and Inertia's hook needs the built handler, so the two halves cannot share one place.
- `app/Http/ApiError.php` and `app/Http/FlashToast.php`
- `app/Domain/Shared/DomainException.php`, `app/Domain/Shared/Exceptions/{DomainValueException,EntityNotFoundException,RepositoryException}.php` and `app/Application/ApplicationException.php`
- `resources/js/pages/error.tsx`, rendered with no layout (`app.tsx` returns `null` for `error`)
- `tests/Feature/Http/ExceptionResponsesTest.php`

Every `lang/*.json` carries the keys the kit speaks: `common.forbidden`, `common.not_found`, `common.page_expired`, `common.unauthenticated`, `common.method_not_allowed`, `common.too_many_requests`, `common.server_error`, `common.service_unavailable`, `common.toast_error_title`, `errors.title_{403,404,500,503}`, `errors.description_{403,404,500,503}` and `errors.back_home`. *Check `lang-keys`.*

## Scaffold, never hand-write

| Kind | Command |
|---|---|
| Refusal of an aggregate | `php artisan make:domain-exception {Name} --domain={Context}/{Aggregate} --kind=refusal` (writes `{Context}DomainException` too, the first time) |
| Invalid value | `php artisan make:domain-exception {Name} --domain={Context}/{Aggregate} --kind=value`, or `--domain=Shared` for a shared value object |
| Refusal of a use case | `php artisan make:domain-exception {Name} --domain={Context} --kind=application [--use-case={UseCase}]` |
| Refusal of a domain service | `php artisan make:domain-service {Name} --domain={Context} --exception` |
| Not found, failure | `php artisan make:eloquent-repository` writes both |

A refusal, an invalid value or a use case's refusal can be designed on `/kit/structure` instead, and a domain service's exception with the service, so `kit:apply` runs the command above (structure.md).

**Don't** run Laravel's `make:exception`. It writes to `app/Exceptions` with `render()` and `report()`. An exception carries a reason and leaves the answer to its catcher and `ExceptionResponses`. *Check `home`.*

## Refusals in a controller

```php
try {
    $debitWallet($request->toCommand());
} catch (InsufficientWalletBalanceException) {
    Inertia::flash(FlashToast::KEY, FlashToast::error(__('agents.debit_insufficient')));

    return back();
}
```

**Do**
- Catch the concrete exception the user can act on, by name, and pick a translated message for it. Fill its parameters from the route's models or the exception's typed getters.
- Answer a web request with `FlashToast::error()` and `back()` or `to_route()`, and an API request with `ApiError::refused('{code}', __('…'))`.

**Don't**
- Catch `Throwable`, `Exception`, `Error`, `RuntimeException` or `LogicException`, an abstract class, or a class with subclasses in `app/`. Each of those catches reasons you did not mean to answer. *Check `broad-catch`.*
- Catch `EntityNotFoundException` only to answer 404. `ExceptionResponses` already does that. *Review only.*
- Call `abort()`, `abort_if()` or `abort_unless()` anywhere in `app/`. A refusal is a policy denial (403) or a domain exception. *Check `abort`.*

**Why:** a broad catch answers every reason with one message, and a new reason added later is answered with it too. A named catch says which refusals this action expects. Anything else stays loud.

## Inside the core

**Do** end every catch in `app/Domain` and `app/Application` with `throw`. Either clean up and rethrow (delete the files a failed write left), or translate a library's exception into the domain's own exception. *Check `swallow`.*

**Do** throw only subclasses of `DomainException` from `app/Domain`. `throw $e` inside a catch is a rethrow and counts as one. The one other throw allowed is the placeholder `make:domain-service` writes into a new service, `throw new LogicException('{Name}Service::handle() is not implemented yet.')`, until a person writes `handle()` (layers.md). *Check `domain-throws`.*

**Why:** a handler that swallows an exception reports success for work that did not happen. A raw `InvalidArgumentException` from a value object cannot be told apart from PHP's own, so no caller can catch it by reason.

## Console commands and queued work

- A console command catches an exception by name, as a controller does (*check `broad-catch`*). It prints `$this->error(…)` and returns `self::FAILURE`. An operator reads the output, so the exception's message may be shown there.
- A job or a listener ends every catch with `throw`, `$this->fail($e)` or `$this->release()`. The queue then retries it or records it as failed. *Check `swallow`.*

## The error page

While `app.debug` is false, `ExceptionResponses` renders `resources/js/pages/error.tsx` for 403, 404, 500 and 503. It renders with `withSharedData()`, so `t()` reads the shared translations. While debug is on, Laravel's own debug page stays.

## JSON responses

A request wants JSON when it is not an Inertia visit and either its path is under `api/` or it asks for JSON (`ExceptionResponses::wantsJson()`, the same test `shouldRenderJsonWhen` uses). An Inertia visit never gets JSON.

The body is Laravel's own shape with a stable `code` added. Validation keeps `errors`. Headers Laravel set (`Retry-After`, `WWW-Authenticate`) stay. While debug is on, Laravel's `exception`, `file`, `line` and `trace` stay too.

| Status | `code` | `message` |
|---|---|---|
| 401 | `unauthenticated` | `common.unauthenticated` |
| 403 | `forbidden` | the policy's deny message, or `common.forbidden` |
| 404 | `not_found` | `common.not_found`, always. Laravel's own text names the model class. |
| 405 | `method_not_allowed` | `common.method_not_allowed` |
| 409 | the controller's code | the controller's message (`ApiError::refused()`) |
| 419 | `page_expired` | `common.page_expired` |
| 422 | `validation_failed` | the validation summary, plus `errors` |
| 429 | `too_many_requests` | `common.too_many_requests` |
| 503 | `service_unavailable` | `common.service_unavailable` |
| any other 5xx | `server_error` | `common.server_error`, never the exception's message |
| any other 4xx | `http_{status}` | the status text |

**Do** answer a refusal in an API controller with `ApiError::refused()`. Its first argument is a snake_case string literal, because a client branches on it. *Check `api-code`.*

**Don't** build a JSON error by hand in `app/Http`: no `response()->json(…, 4xx|5xx)`, no `new JsonResponse(…, 4xx|5xx)`, and no JSON response inside a catch. *Check `api-error`.*

**Why:** a client reads one shape from every endpoint and branches on `code`, never on a sentence that changes with the locale. The message is always written for a person, so nothing written for the log leaks.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "exceptions"`, exactly as stack.md describes. Only the user may add an entry.
