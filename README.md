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

- PHP 8.4, Composer, Node 22.
- A PostgreSQL, MySQL or MariaDB server. The kit never runs on sqlite: tests run on the engine production runs.
- A project made from Laravel's React starter kit (Laravel 13, Inertia 3, React 19). `kit:setup` brings it to the rest of the locked stack, which [`stack.md`](resources/boost/guidelines/stack.md) lists and the Architecture suite checks.

## Install

On a new project, five commands:

```bash
laravel new my-app --react          # any test framework and package manager
cd my-app
composer require --dev playerarm123/laravel-workflow-kit
php artisan kit:setup               # asks which database: pgsql, mysql or mariadb
php artisan migrate:fresh           # once the database .env names exists
php artisan test --testsuite=Architecture
```

`kit:setup` takes a few minutes, because it installs packages. Then finish the three things only you can do:

1. `php artisan boost:install`, and add `"playerarm123/laravel-workflow-kit"` to `"packages"` in `boost.json`, so your agent gets the guidelines and the `list-page` / `create-page` skills.
2. `npx playwright install chromium`, for the Browser tests.
3. Decide who may read the audit log: `app/Policies/AuditEntryPolicy.php` lets every user with a verified email in until you change it, and `auditLogReader()` / `auditLogOutsider()` in `tests/Pest.php` change with it.

### What `kit:setup` does

It changes only what is not in shape yet, so you can run it again at any time; a second run changes nothing. A file it cannot read the way it expects is left alone and listed under *Left to do by hand*.

| Step | What it changes |
|---|---|
| Composer packages | `php` to `^8.4`; PHPUnit out, Pest 5 (with the Laravel and Browser plugins) in; Boost, Nightwatch, spatie/laravel-data and the s3 driver; then `composer update` |
| JavaScript packages | the `@radix-ui/*` packages for `radix-ui` (every import rewritten); ESLint, Prettier, Playwright, the table, date and diagram libraries; `lint` and `format` scripts; then your package manager's install |
| Kit files | every file `kit:install` writes, plus files written once and yours from then on: the UUIDv7 `IdGenerator` and its provider, the `InitializeUserContext` middleware, `AuditEntryPolicy`, `tests/Pest.php`, `eslint.config.js`, `.prettierrc`, the `SharedProps` type, the four shadcn components the kit's pages use, tests for the starter kit's middleware, and an empty `rule-overrides.json`. The `ExampleTest`s go. |
| Config | the database in `.env`, `.env.example` and `phpunit.xml`; `NIGHTWATCH_TOKEN`; the Architecture suite; the PHPStan write-path rule; `app.currency`; the structure screen's Vite entry |
| Wiring | the providers, `ExceptionResponses`, `InitializeUserContext`, the props every page shares (`locale`, `timezone`, `currency`, `translations`) in PHP and TypeScript, and `<FlashToast />` |
| Users on uuids | the users table, its sessions and passkeys, `User`, `UserFactory` and `CreateNewUser` move to uuid keys. This edits the migrations in place, which is safe only before the first deploy. |
| Audit log page | its route, the `// kit:routes` marker, and every word the kit's pages speak in every `lang/*.json` |

Finally it runs `wayfinder:generate`, `lint` (ESLint with `--fix`) and `kit:import`.

`--database=pgsql` skips the question. `--skip-dependencies` edits `composer.json` and `package.json` but runs no install.

### On an existing project

`kit:setup` is written for a project fresh from the starter kit. On an older one, commit first, run it, and read the diff: every edit is small and named in the table above, and what it cannot place is listed for you. The steps by hand are in [the guidelines' Kit files sections](resources/boost/guidelines), and each failing check names the one it wants.

### When a check fails

Every failure reads `[rule:check] subject: message — see rule.md in the workflow kit's guidelines`. The ones a new project meets most:

| Failure | Fix |
|---|---|
| `[stack:major] x: is declared but missing from composer.lock` (or the JS lockfile) | run `composer update` or your package manager's install |
| `[stack:required] x: is required but not declared` | run `php artisan kit:setup` again, or require the package |
| `[stack:database] .env.example DB_CONNECTION: is "sqlite"` | `php artisan kit:setup --database=pgsql` |
| `[…:kit-files] x: differs from the kit` | `php artisan kit:install --force` takes the kit's copy |
| `[testing:mirror] X: has no tests/…Test.php` | write that test at that path (testing.md) |
| `[structure:in-json] .kit/structure/X.json: is missing` | `php artisan kit:import` |
| `[audit-log:labels] …` | label the event, subject or key in every `lang/*.json` (audit-log.md) |

A rule that cannot hold in one place is recorded by the project's owner in `rule-overrides.json` ([stack.md](resources/boost/guidelines/stack.md)). An agent never adds one.

## Quick usage

### Design, then build

```bash
php artisan kit:import        # write .kit/structure/*.json from the code as it stands
                              # design: edit the JSON, or open /kit/structure locally
php artisan kit:plan          # the steps: done, ready, or waiting with the reason
php artisan kit:apply         # run every ready step: generators, binding and route lines
```

`kit:apply` stops where only a person can go on: a Command's fields, a Row's keys, a method's body. Fill those in and run it again. It picks up where it stopped.

The design screen has a step-by-step guide with screenshots: [docs/structure-screen.md](docs/structure-screen.md) (also at `/kit/docs/guides/structure-screen` in the app).

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
| `kit:setup [--database=] [--skip-dependencies]` | Set up a project made from the React starter kit, in one run |
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

The kit's CI also builds a project with `laravel/react-starter-kit`, installs the kit from the archive Packagist would serve, runs `kit:setup` and holds it to the Architecture, Unit and Feature suites, PHPStan and ESLint (the `starter-kit` job).

## License

MIT. See [LICENSE](LICENSE).

## Read more

- Every guideline: [`resources/boost/guidelines`](resources/boost/guidelines). On a local environment, `/kit/docs` renders them with the console's commands.
- The design screen: `/kit/structure`, local only. How to use it: [docs/structure-screen.md](docs/structure-screen.md). The rules it follows: [structure.md](resources/boost/guidelines/structure.md).
