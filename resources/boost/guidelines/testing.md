# Testing

Every class that carries logic, and every page, has one test of its own, at a path read from its own path:

```
app/{path}/{Class}.php              →  tests/{Unit|Feature}/{path}/{Class}Test.php
resources/js/pages/{path}/{page}.tsx  →  tests/Browser/{Path}/{Page}Test.php
```

When a test turns red, its path names the class that broke and its layer.

Enforced by the package's `tests/Architecture/TestingTest.php` (`php artisan test --testsuite=Architecture`). The spec in `testingSpec()` is the machine-checked copy of this file: change the two together. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Which test, where

| Subject | Suite | Test |
|---|---|---|
| Entity (`*Entity`, root or child) | Unit | mirror |
| Value object (`ValueObjects/*`) | Unit | mirror |
| Domain service that injects a repository, directly or through another service | Feature | mirror |
| Domain service that only computes | Unit | mirror |
| Use-case handler | Feature | mirror |
| Controller action | Feature | `tests/Feature/Http/Controllers/{Name}Controller/{Action}Test.php`, one file per public method (`StoreTest.php`) |
| Invokable controller (`__invoke`) | Feature | mirror: `tests/Feature/Http/Controllers/{Name}ControllerTest.php` |
| Console command | Feature | mirror: `tests/Feature/Console/Commands/{Name}CommandTest.php` |
| Middleware | Feature | mirror: `tests/Feature/Http/Middleware/{Name}Test.php` |
| Page (`resources/js/pages/**/*.tsx`) | Browser | mirror, each kebab-case segment in StudlyCase: `audit-entries/index.tsx` → `tests/Browser/AuditEntries/IndexTest.php` |

Other rules already check the tests of these:
- repositories (repositories.md)
- list query adapters (list-queries.md)
- policies (authorization.md)

The starter kit's own controllers (`App\Http\Controllers\Settings`) keep the tests the kit ships in `tests/Feature/Settings`. The kit's other tests (`tests/Feature/Auth`, `tests/Feature/DashboardTest.php`) stay where it ships them too. The starter kit's pages (`auth/`, `settings/`, `dashboard.tsx`, `welcome.tsx`) need no Browser test, and neither does the kit's `error.tsx`, which `ExceptionResponsesTest` covers (exceptions.md).

**Do**
- Give every subject in the table its test at exactly that path. *Check `mirror`.*
- Test a FormRequest's rules through its controller's `Store`/`Update` test: one case per rule, as a dataset of `[overrides, field]` that asserts `assertInvalid([$field])`. *Review only.*
- Test what has no test of its own through the subject that uses it:
  - enums
  - Commands, Results and Data
  - FormRequests and FormValues
  - log payloads
  - models and factories
  - providers
  - ports
- Give every public method a case that succeeds and a case that fails or refuses. *Review only.*

**Don't** keep a test that mirrors nothing. Every `*Test.php` under `tests/Unit`, `tests/Feature` and `tests/Browser` sits at the mirror of a subject. *Check `stray`.* A subject is one of these:
- a file in `app/`, as above, whether or not the table requires its test
- a seeder or a factory: `database/seeders/{Name}.php` → `tests/Feature/Database/Seeders/{Name}Test.php`, and `database/factories/` the same way into `Factories`
- a config file: `config/{name}.php` → `tests/Feature/Config/{Name}Test.php`
- a page: `resources/js/pages/{path}.tsx` → `tests/Browser/{Path}Test.php`, as above
- the migrations as a whole: `database/migrations/` → `tests/Feature/Database/MigrationsTest.php` (migrations.md)
- the models as a whole: `app/Models/` → `tests/Feature/ModelsTest.php` (models.md)
- a controller action, as above

A second file about one subject is merged into its mirror, as its own `describe()`. Delete the starter kit's `ExampleTest`s.

**Why:** when every subject's test sits where its path says, nobody has to search for it. A class with no test then shows up as a missing file, and a moved class whose test was left behind fails the check. A test whose path names no subject is the same problem the other way round: nobody finds it when the subject changes, and nothing notices when the subject is deleted.

**Don't** name real code with `Sampling` anywhere in a file or folder name, or with `sampling-` at the start of one. Those names are reserved for the files the generators' tests write into `app/`, `resources/js/` and `tests/` and delete again. Every check skips them (`ruleIsScratch()`), so a check running in another `--parallel` process never reads a fixture as project code. Checks list files only through `ruleSourceFiles()` or `ruleGlob()`, which both skip them. *Review only.*

## Unit stays plain PHP

`tests/Unit` runs on the bare PHPUnit TestCase. `tests/Pest.php` binds `TestCase` and `RefreshDatabase` to `Feature` and `Browser` only. *Check `pest-config`.*

**Do** build the subject by hand in a Unit test: `XEntity::create(…)`, `XValue::from(…)`, `new XService`. *Check `unit-pure`.*

**Don't** reach for Laravel in `tests/Unit`:
- `app()` or a facade
- a factory, `RefreshDatabase` or `DB::`
- `$this->mock()`, `$this->get()` or `actingAs()`

When a subject needs any of these, it belongs in Feature (see the table). *Check `unit-pure`.*

**Why:** the domain must be testable without the framework (layers.md). A Unit test that boots Laravel hides a domain class that has started to depend on it. It also makes the fast suite slow.

## The real database, not doubles

Feature tests run on the engine production runs (stack.md), through the real repositories.

**Do**
- Read a handler's effect back through its repository (`app(XRepository::class)->getById(…)`), or with `assertDatabaseHas()`. *Review only.*
- Bind the actor for a handler test through `UserContext` (handlers.md), with a helper in `tests/Pest.php`. *Review only.*
- Swap a port with `app()->instance(Port::class, new class implements Port { … })` when a test must know its answer in advance (`IdGenerator`). *Review only.*
- Use `$this->mock(XRepository::class)` only to force a failure that real objects cannot produce, such as a write the database refuses. *Review only.*

**Don't** write a fake or in-memory repository: no class under `tests/` implements a `*Repository`. *Check `doubles`.*

**Why:** a fake repository passes where the real one fails: a unique index, a foreign key, a transaction that rolls back, or a column the mapping forgot. Each fake must also be kept in step with its adapter, by hand, forever.

## How each kind of test reads

*Review only.*
- **Entity, value object, domain service:** `describe('{Class}')`, then `describe('{method}')`, with `it(…)` cases. Helpers and constants are named after the subject, because Pest shares one global namespace.
- **Handler:**
  - `beforeEach` binds the actor and any port the test fixes, then resolves `app({Name}Handler::class)`.
  - Cases cover the happy path with the audit entry it records (`assertDatabaseHas('audit_entries', …)`), each refusal and a failed write.
- **Controller action:**
  - a guest is redirected to login
  - a user the policy refuses gets 403
  - each user it allows gets the page (`assertInertia`, including `can`) or the redirect and the toast (`assertInertiaFlash`)
  - a write action also covers each validation rule and each refusal the controller catches
- **Console command:** scheduled commands end with `describe('the … schedule')`, read from `app(Schedule::class)->events()`.
- **Middleware:** one request through a route that uses it, asserting what it adds or refuses.
- **Page:**
  - `beforeEach` signs in a user the page's policy allows, through a helper named after the test (Pest shares one global namespace).
  - One case visits the page and asserts `assertNoJavaScriptErrors()` and `assertNoConsoleLogs()`.
  - The other cases cover what only the browser computes, which the controller test cannot see: formatting (money, rates, dates), badges, dialogs, the toolbar keeping the query on the url, and a form that posts and shows its toast or its errors.
  - The Browser suite runs on `public/build`, so run `npm run build` first, or it tests stale JavaScript.

## Scaffold, never hand-write

The generators write each test at its mirrored path, with a `->todo()` for every case:
- `make:entity`
- `make:value-object`
- `make:domain-service`: Unit, or Feature when it is given `--repo`. The generator decides once, from `--repo` alone. When a service starts reaching a repository later, directly or through another service, move its test from `tests/Unit/...` to the same path under `tests/Feature/...` and resolve the service from the container (`app({Name}Service::class)`). *Check `mirror` names the move.*
- `make:use-case`
- `make:eloquent-repository`
- `make:policy`
- `make:port --adapter`: the adapter's Feature test, two cases per method of the port
- `make:controller`: one test per resource action, and `make:action`: one per invokable controller
- `make:list-page` (the index page's Browser test) and `make:form-page` (the create and edit pages' Browser tests)

Fill in the todos.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "testing"`, exactly as stack.md describes. The `subject` is the class, `Class::method` for a controller action, or the page's path from the project root. Only the user may add an entry.
