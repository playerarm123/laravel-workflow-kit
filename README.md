# playerarm123/laravel-workflow-kit

[ภาษาไทย](README.th.md)

The rules, generators and checks that keep every Laravel project built the same way. One dev dependency ships:

- **Guidelines and skills** for AI agents and people, written into `CLAUDE.md` / `AGENTS.md` by [Laravel Boost](https://github.com/laravel/boost).
- **Generators** (`make:*`) that write each piece the way the guidelines want it.
- **The structure manifest** (`kit:import`, `kit:plan`, `kit:apply`, `kit:retire`) and a local screen to design with it.
- **Checks** that hold the code to the rules: the `Architecture` test suite, the ESLint rules and a PHPStan rule.
- **The kit's project files** (base classes, list and form hooks, the audit log page, …), written by `kit:install` and kept identical to the kit's copy.

The rules themselves live in [`resources/boost/guidelines`](resources/boost/guidelines). This file only says how to install the kit and how to use it day to day.

## Requirements

| | |
|---|---|
| PHP | 8.4 |
| Framework | Laravel 13, Inertia 3 with React 19, Wayfinder |
| Tests and analysis | Pest 5, Larastan 3, ESLint 9, Prettier 3 |
| Database | PostgreSQL, MySQL or MariaDB, the same in `.env.example` and `phpunit.xml` (never sqlite) |
| Agents | Laravel Boost 2, to receive the guidelines and skills |

The full locked stack is in [`stack.md`](resources/boost/guidelines/stack.md), and the Architecture suite checks it.

## Install

### 1. Require the package

```bash
composer require --dev playerarm123/laravel-workflow-kit:^0.1
```

Laravel discovers `WorkflowKitServiceProvider` on its own. It registers the `kit:*` and `make:*` commands, and takes over `make:controller`, `make:enum` and `make:policy`.

### 2. Hand the guidelines to Boost

Add the package to `boost.json`, then let Boost write the guidelines and the `list-page` / `create-page` skills:

```json
"packages": ["playerarm123/laravel-workflow-kit"]
```

```bash
php artisan boost:update
```

### 3. Write the kit's files

```bash
php artisan kit:install
```

It copies the kit's project files from `resources/kit` to their fixed paths:
- `resources/kit/files/` holds files the project keeps exactly as the kit ships them: `app/Domain/Shared/AggregateRoot.php`, `app/Http/FlashToast.php`, `resources/js/hooks/use-data-table.tsx`, the audit log page, the migration and model stubs, and more. The `kit-files` checks fail when one is missing or differs.
- `resources/kit/scaffold/` holds files written once and then owned by the project. Today that is `app/Application/Auth/UserContext.php`, which you extend with your roles.

A file that differs is left alone and listed. `kit:install --force` puts the kit's copy back. A scaffold is never overwritten.

### 4. Wire it in

`kit:install` prints these steps after it writes anything. Until each one is done, a check names it.

**Providers.** In `bootstrap/providers.php`:

```php
App\Providers\KitServiceProvider::class,   // the local-only /kit/structure and /kit/docs screens
App\Infra\Audit\AuditServiceProvider::class,
```

**Exceptions** ([exceptions.md](resources/boost/guidelines/exceptions.md)). Call it in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    ExceptionResponses::register($exceptions);
})
```

Then in a provider's `boot()`:

```php
Inertia::handleExceptionsUsing(ExceptionResponses::respond(...));
```

**Ports the project implements** ([handlers.md](resources/boost/guidelines/handlers.md)):
- Bind `App\Domain\Shared\Ports\IdGenerator` to an adapter in a provider's `$bindings`, for example a UUIDv7 generator.
- Bind `App\Application\Auth\UserContext` per request in a middleware. In the same middleware, call `Context::add('actor_id', $user?->getAuthIdentifier())` so the audit log and every log line carry the actor.

**Shared props and toasts** ([list-pages.md](resources/boost/guidelines/list-pages.md), [form-pages.md](resources/boost/guidelines/form-pages.md)):
- Share `locale`, `timezone`, `currency` and `translations` from `HandleInertiaRequests::share()`.
- Render `<FlashToast />` beside sonner's `<Toaster />` in `app.tsx`.

**Tests.** In `phpunit.xml`:

```xml
<testsuite name="Architecture">
    <directory>vendor/playerarm123/laravel-workflow-kit/tests/Architecture</directory>
</testsuite>
```

**ESLint.** In `eslint.config.js`:

```js
import actions from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/actions.js';
import dates from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/dates.js';
import formPages from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/form-pages.js';
import listPages from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/list-pages.js';
import numbers from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/numbers.js';

export default [
    // …your config
    ...listPages,
    ...formPages,
    ...actions,
    ...dates,
    ...numbers,
    { ignores: ['**/tests/ESLint/Fixtures/**'] },
];
```

**PHPStan.** In `phpstan.neon`:

```neon
includes:
    - vendor/playerarm123/laravel-workflow-kit/tests/PHPStan/write-path.php
```

**The audit log page** ([audit-log.md](resources/boost/guidelines/audit-log.md)). The kit ships the page; the project decides who may open it:
- `php artisan make:policy AuditEntry`, keeping only `viewAny`.
- `Route::resource('audit-entries', AuditEntryController::class)->only(['index'])`.
- `auditLogReader()` and `auditLogOutsider()` in `tests/Pest.php`.
- The page's keys in every `lang/*.json`.

**Markers for `kit:apply`.** Put `// kit:bindings` as the last entry of one provider's `$bindings`, and `// kit:routes` as the last line of the route group new pages belong in.

### 5. Check it

```bash
php artisan test --testsuite=Architecture
```

Every failure reads `[rule:check] subject: message — see rule.md in the workflow kit's guidelines`. Fix what it names and run it again until it passes.

## Quick usage

### Design, then build

```bash
php artisan kit:import        # write .kit/structure/*.json from the code as it stands
                              # design: edit the JSON, or open /kit/structure locally
php artisan kit:plan          # the steps: done, ready, or waiting with the reason
php artisan kit:apply         # run every ready step: generators, binding and route lines
```

`kit:apply` stops where only a person can go on: a Command's fields, a Row's keys, a method's body. Fill those in and run it again. It picks up where it stopped.

To change something already built, mark the new piece with `replaces` on the screen or in the manifest. `kit:apply` builds it and swaps it in, then `php artisan kit:retire` removes the old one once the suites pass ([structure.md](resources/boost/guidelines/structure.md)).

### Or scaffold one piece

A row action with its bulk twin ([actions.md](resources/boost/guidelines/actions.md)):

```bash
php artisan make:use-case CancelLotteryDraw --domain=LotteryDraw --command --repo=LotteryDraw
# fill in the Command: ids, plus the action's own fields; the handler returns int
php artisan make:action Cancel --model=LotteryDraw --domain=LotteryDraw
php artisan make:action Cancel --model=LotteryDraw --domain=LotteryDraw --bulk
```

A list page ([list-queries.md](resources/boost/guidelines/list-queries.md), [list-pages.md](resources/boost/guidelines/list-pages.md)):

```bash
php artisan make:use-case ListLotteryTypes --domain=LotteryDefinition --command --result --query
# fill in the Criteria, the Row's toArray() and the adapter
php artisan make:controller LotteryType --domain=LotteryDefinition --only=index,destroy
php artisan make:list-page ListLotteryTypes --domain=LotteryDefinition
```

Each generator writes the test beside what it writes, with a `->todo()` per case, and prints what it could not do: the route line, a missing policy ability, missing translation keys.

### Commands

| Command | What it does |
|---|---|
| `kit:install [--force]` | Write the kit's files into the project |
| `kit:import [--context=] [--resource=] [--force]` | Write the structure manifest from the code |
| `kit:plan [--context=] [--resource=]` | Show the steps that build the manifest |
| `kit:apply [--context=] [--resource=]` | Run those steps |
| `kit:retire [--context=]` | Remove what a finished replacement took over |
| `make:entity {name} --domain= [--child]` | An aggregate root or child entity, and its Unit test |
| `make:entity-method {entity} {method} --domain= [--param=] [--throws=]` | A behaviour or an assertion on an entity |
| `make:enum {name} --domain= --string --case= [--transition=]` | A domain enum, with its transitions when it is a status |
| `make:value-object {name} --domain= [--field=]` | A value object and its test |
| `make:domain-exception {name} --domain= --kind=refusal\|value\|application` | An exception on the right base |
| `make:domain-service {name} --domain= --creates=\|--data\|--plain` | A domain service in one of its three shapes |
| `make:port {name} --domain=\|--application= [--adapter= --infra=]` | A port, and later its adapter and the adapter's test |
| `make:eloquent-repository {name} --domain=` | A repository, its exceptions, log payload and contract test |
| `make:use-case {name} --domain= [--command] [--result] [--repo=] [--creates] [--query]` | A use case: handler, Command, Result, and for a list its query port and adapter |
| `make:controller {name} --domain= --only= [--grid]` | Resource controller methods in the kit's shape, one test per action |
| `make:action {verb} --model= --domain= [--bulk] [--use-case=]` | An action's controller, request and test |
| `make:form-request {name} --domain=` | Store/Update requests and the form values |
| `make:form-page {name}` | The form component, create/edit pages and TypeScript type |
| `make:list-page {name} --domain= [--grid] [--types-only]` | A list page, its toolbar, TypeScript types and Browser test |
| `make:policy {model}` | A policy and its test |

Run `php artisan help <command>` for every option.

### Checks

```bash
php artisan test --testsuite=Architecture   # the rules, read off the code
vendor/bin/phpstan analyse                  # write-path: only App\Infra writes to the database
npx eslint .                                # the page rules: one table, one way to the server, dates, numbers
```

When a rule cannot hold in one place, the project's owner records an approved exception in `rule-overrides.json` ([stack.md](resources/boost/guidelines/stack.md)). An agent never adds one.

### Updating the kit

```bash
composer update playerarm123/laravel-workflow-kit
php artisan boost:update          # new guidelines and skills
php artisan kit:install           # new kit files; it lists the ones that differ
php artisan kit:install --force   # take the kit's copy of those
php artisan test --testsuite=Architecture
```

## Contributing

The kit tests itself on a small Laravel app in `workbench/`, through [Orchestra Testbench](https://github.com/orchestral/testbench). You need PHP 8.4, Node 22 and a PostgreSQL database named `workflow_kit_test` on `127.0.0.1`, user `postgres`.

```bash
composer install
(cd workbench && npm ci)        # ESLint and Prettier, for the generators' lint tests
composer test                   # the Feature suite: generators and structure tooling
composer lint                   # Pint
composer analyse                # PHPStan
```

A change to a rule changes its guideline in `resources/boost/guidelines` and its spec in `tests/Architecture` together. A change to a kit file changes its copy in `resources/kit`; run `php vendor/bin/testbench kit:install --force` to put it into the workbench.

## License

MIT. See [LICENSE](LICENSE).

## Read more

- Every guideline: [`resources/boost/guidelines`](resources/boost/guidelines). On a local environment, `/kit/docs` renders them with the console's commands.
- The design screen: `/kit/structure`, local only ([structure.md](resources/boost/guidelines/structure.md)).
