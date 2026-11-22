# Structure

The structure of the project is written down in `.kit/structure/`, one manifest per context and one per HTTP resource, and the code follows it:

```
.kit/structure/{Context}.json          what each context is built from
.kit/structure/http/{Resource}.json    what each HTTP resource is built from
  ⇄ StructureReader                    reads the same pieces back from app/
  → check `manifest`                   the two match, both ways
  ← php artisan kit:import             writes the manifest of the code as it stands
  → php artisan kit:plan / kit:apply   builds what the manifest lists and the code lacks
  ⇄ /kit/structure                     draws it and changes it, on a local environment only
```

The manifest is the source of truth for **structure**: which contexts there are, with each one's aggregates, domain services, ports and use cases, the enums and value objects their aggregates speak in with where each status may go next, the exceptions that refuse or reject with where each one lives, the state of each entity with the behaviours and assertions that change it and what each one throws, the table each entity is stored in and the tables no entity maps to, and which HTTP resources there are, with each one's controller, actions, policy and pages. It is not the truth for behaviour. The body of an entity's method, the rules inside a value object, the methods of an enum other than `transitions()`, the body of a handler and the fields of a form are still written in PHP and TypeScript.

Enforced by the package's `tests/Architecture/StructureManifestTest.php` (`php artisan test --testsuite=Architecture`). The spec in `structureManifestSpec()` is the machine-checked copy of this file: change the two together. The reader and the files are proven in the package's `tests/Feature/Console/Commands/Structure/`, which the project runs as its `Kit` testsuite.

## Kit files

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`), and a stub there is replaced by a file of the same name in the project's `stubs/`. `php artisan kit:install` writes the rest from the kit, and the check fails when one is missing or differs from the kit's copy.
- *package:* `src/Console/Commands/Structure/StructureReader.php`, which reads the code without booting the app, with `Structure/StructureTables.php`, which reads the tables the migrations build from their source, with no database
- *package:* `src/Console/Commands/Structure/StructureFiles.php`, which reads, writes and validates the manifests
- *package:* `src/Console/Commands/Structure/StructureComparer.php`, the one comparison the check and `kit:plan` report
- *package:* `src/Console/Commands/Structure/StructurePlanner.php`, `StructureMarkers.php` and `PlansStructure.php`, which plan and apply
- *package:* `src/Console/Commands/KitImportCommand.php` (`kit:import`)
- *package:* `src/Console/Commands/KitInstallCommand.php` (`kit:install`) with `Structure/KitInstaller.php`, which writes the kit's files from `resources/kit`: `files/` the project keeps as the kit ships them (`--force` puts a changed one back), `scaffold/` written once and owned by the project
- *package:* `src/Console/Commands/KitSetupCommand.php` (`kit:setup`) with `Setup/`, which sets up a project made from Laravel's React starter kit: the stack's packages, the kit's files, the config and wiring, and the files in `resources/kit/setup` it writes once
- *package:* `src/Console/Commands/KitUiAddCommand.php` (`kit:ui-add`) with `Setup/UiComponents.php`, which adds shadcn components through the project's own CLI and settles their packages and files the way stack.md wants them
- *package:* `src/Console/Commands/KitPlanCommand.php` (`kit:plan`), `KitApplyCommand.php` (`kit:apply`) and `KitRetireCommand.php` (`kit:retire`), with `Structure/StructureSwapper.php`, which points code at a replacement
- *package:* `src/Console/Commands/Structure/StructureGraph.php`, which builds what the screen draws, with `StructureEditor.php` and `StructureResourceEditor.php`, which write what it changes in a context and in an HTTP resource, and `StructureTableDesign.php`, which checks a table the editor designs and proposes one from an entity's state
- `app/Providers/KitServiceProvider.php`, listed in `bootstrap/providers.php`, with `resources/views/kit/structure.blade.php` and the Vite entry `resources/js/kit/structure.tsx`, listed in `vite.config.ts`, and the screen it draws, in the rest of `resources/js/kit/`
- *package:* `src/Console/Commands/MakeEnumCommand.php`, which takes over Laravel's `make:enum`, and `src/Console/Commands/MakeValueObjectCommand.php` (`make:value-object`), with `stubs/value-object.stub` and `stubs/value-object-test.stub`
- *package:* `src/Console/Commands/MakeTableMigrationCommand.php` (`make:table-migration`) and `src/Console/Commands/MakeTableModelCommand.php` (`make:table-model`), which write a designed table's migration, model and factory through `Structure/StructureTableWriter.php`
- *package:* `src/Console/Commands/MakeRepositoryMappingCommand.php` (`make:repository-mapping`), which writes a repository's mapping from the designed tables through `Structure/StructureRepositoryMapping.php`
- *package:* `src/Console/Commands/MakeEntityMethodCommand.php` (`make:entity-method`), which adds a behaviour or an assertion to an entity, and `src/Console/Commands/MakeEntityStateCommand.php` (`make:entity-state`), which adds a property and its getter, sharing `src/Console/Commands/Concerns/EditsEntityClass.php` and, with `make:value-object`, `src/Console/Commands/Concerns/ResolvesManifestTypes.php`
- *package:* `src/Console/Commands/Structure/StructureCommands.php`, the commands the screen's Run menu runs
- *package:* `src/Console/Commands/Structure/KitDocs.php`, with the project's `resources/views/kit/docs.blade.php`, the kit's docs screen at `/kit/docs`: every guideline read from the workflow kit as it stands, and the `make:*` and `kit:*` commands the console has

## The manifest

```json
{
    "context": "Shipping",
    "aggregates": {
        "Crate": { "children": ["Lid"], "repository": true }
    },
    "services": {
        "PackCrate": { "shape": "creates", "creates": "Crate", "repositories": ["Crate"], "exception": false }
    },
    "applicationServices": {
        "ResolveShipper": { "shape": "data", "repositories": ["Billing/Account"], "actor": true, "exception": false }
    },
    "ports": {
        "Scale": { "layer": "domain", "adapter": "Infra/Shipping/DigitalScale" }
    },
    "useCases": {
        "CreateCrate": { "shape": "command", "returns": "string", "creates": true, "query": false, "repositories": ["Crate", "Billing/Invoice"] },
        "ListCrates": { "shape": "command-result", "returns": "result", "creates": false, "query": true, "repositories": [] }
    },
    "enums": {
        "CrateGrade": { "aggregate": "Crate", "backing": "string", "cases": { "Top": "top", "Low": "low" }, "transitions": null },
        "CrateStatus": {
            "aggregate": "Crate", "backing": "string", "cases": { "Open": "open", "Sealed": "sealed" },
            "transitions": { "Open": ["Sealed"], "Sealed": [] }
        }
    },
    "valueObjects": {
        "CrateLabel": { "aggregate": "Crate", "fields": { "grade": "CrateGrade", "note": "?string", "price": "Shared/Money" } }
    },
    "exceptions": {
        "CrateSealedException": { "kind": "refusal", "aggregate": "Crate", "useCase": null },
        "CrateLabelInvalidException": { "kind": "value", "aggregate": "Crate", "useCase": null },
        "CrateQuotaReachedException": { "kind": "application", "aggregate": null, "useCase": "CreateCrate" }
    },
    "entities": {
        "Crate": {
            "aggregate": "Crate",
            "state": { "status": "CrateStatus", "label": "?CrateLabel" },
            "behaviours": {
                "seal": { "params": { "label": "CrateLabel", "tags": "...string" }, "throws": ["CrateSealedException", "Shared/InvalidMoneyException"] }
            },
            "assertions": {
                "assertIsOpen": { "params": {}, "throws": ["CrateSealedException"] }
            },
            "table": {
                "name": "crates",
                "model": "Crate",
                "columns": {
                    "status": { "type": "string", "length": 20, "enum": "CrateStatus" },
                    "label": { "type": "jsonb", "nullable": true, "comment": "null means not labelled yet" }
                },
                "foreignKeys": {
                    "shipper_id": { "table": "shippers", "onDelete": "restrict", "index": true }
                },
                "indexes": [],
                "timestamps": true,
                "softDeletes": false
            }
        }
    },
    "tables": {
        "crate_tags": {
            "model": null,
            "columns": { "tag": { "type": "string", "length": 30 } },
            "foreignKeys": { "crate_id": { "table": "crates", "onDelete": "cascade" } },
            "indexes": [{ "columns": ["crate_id", "tag"], "unique": true }],
            "timestamps": false,
            "softDeletes": false
        }
    }
}
```

Each value is read off the code:
- `aggregates`: a folder `{Context}/{Aggregate}/` whose `{Aggregate}Entity` extends `AggregateRoot` (layers.md). `children` are the classes in its `Entities/`, and `repository` says whether `{Aggregate}Repository` exists.
- `services`: `{Context}/Services/{Name}/{Name}Service`. Its `shape` is one of the three `handle()` shapes in layers.md: `creates`, `data` or `plain`. `creates` names the aggregate a `creates` service builds, and is `null` for the other shapes. `exception` says whether `{Name}Exception` sits beside it (`make:domain-service --exception`).
- `applicationServices`: `Application/{Context}/Services/{Name}/{Name}Service`. Its `shape` is one of the two `handle()` shapes in layers.md: `data` or `plain`. `repositories` lists the repositories it injects, as `Aggregate` in its own context and `Context/Aggregate` outside it, `actor` says whether it injects `UserContext`, and `exception` whether `{Name}Exception` sits beside it (`make:application-service --exception`). A manifest written before the section existed reads it as `{}`.
- `ports`: an interface in `Domain/{Context}/Ports/` (`layer: domain`), or at the root of `Application/{Context}/` (`layer: application`). `adapter` is what a provider's `$bindings` binds it to, as a path under `App`, or `null`.
- `useCases`: a handler under `Application/{Context}/UseCases/`. Its `shape` is one of the three in handlers.md: `command-result`, `command` or `plain`. The other keys are:
  - `returns`: what the handler returns;
  - `creates`: whether it injects `IdGenerator`;
  - `query`: whether a `{Name}Query` port sits beside it (list-queries.md);
  - `repositories`: the repositories it injects, as `Aggregate` in its own context and `Context/Aggregate` outside it.
- `enums`: a backed or pure enum in `{Context}/{Aggregate}/Enums/`. `backing` is `string`, `int` or `null`, and `cases` maps each case to its value (`null` for a pure enum), in the order the enum declares them. `transitions` is `null` unless the enum uses `HasTransitions` (states.md). Then it maps every case to the cases its `transitions()` lets it become, `[]` for a final one, both in the order of the cases, whatever order the `match` lists them in.
- `valueObjects`: a concrete class in `{Context}/{Aggregate}/ValueObjects/`. `fields` maps each constructor parameter to its type, in constructor order. A type is written the way the generators read it back:
  - a builtin as PHP spells it (`string`, `?int`, `string|int`);
  - a class of the same aggregate by its name (`CrateGrade`);
  - a class of the shared kernel as `Shared/{Name}`;
  - a class of another aggregate as `{Context}/{Aggregate}/{Name}`;
  - any other class by its full name (`DateTimeImmutable`).
- `exceptions`: each exception the context's code may throw by name, keyed by its class name, with its `kind` (exceptions.md):
  - `refusal`: a class in `{Context}/{Aggregate}/Exceptions/` that extends `{Context}DomainException`, with `aggregate` naming the aggregate;
  - `value`: an invalid value, a class there that extends `DomainValueException`; in the shared kernel, one in `Shared/Exceptions/`, with `aggregate` null;
  - `application`: a use case's refusal, a class in `Application/{Context}/` that extends `ApplicationException`. `useCase` names the use case whose folder holds it, or is `null` for one at the context's root.

  `useCase` is `null` for the other kinds. Left out: a repository's `{Aggregate}NotFoundException` and `{Aggregate}RepositoryException`, which `repository` owns, the context's base `{Context}DomainException`, a domain or application service's exception, which its `exception` key owns, and the kit's own exceptions in the shared kernel.
- `entities`: an aggregate's root or one of its children, by its name without `Entity`, with its state and the public methods it declares itself. `aggregate` names the aggregate that holds it. An entity that holds no state, declares neither kind of method and maps to no table is left out.
  - `state`: each parameter of its constructor but `id`, which every entity has, with its type written as a value object's field is, in the constructor's order. Each one is a property the constructor promotes, and the entity answers it with a getter of the same name that takes nothing and returns its type (`status(): CrateStatus`). `matches` names a property with no such getter.
  - `behaviours`: the methods that change the entity: not static, returning `void`, and not named `assert…`.
  - `assertions`: the methods named `assert…`.
  - Getters and every other method that returns something, `create()`/`reconstitute()` and what a base class or an interface declares are left out.
  - Each method keeps its `params` in order, each type written as a value object's field is, with `...` before a variadic one.
  - `throws` lists every exception the method's body throws (`throw X::…()` or `throw new X`), and every one thrown by a method of the same class it calls through `$this->`, `self::` or `static::`, however deep. A rethrow (`throw $e`) adds nothing. An exception is named as a type is: by its name in its own aggregate, `Shared/{Name}`, or `{Context}/{Aggregate}/{Name}`.
  - `table`: the table the entity is stored in, left out while it has none. See `tables` below for its keys; an entity's table also carries its `name`, and its `model` is never null.
- `tables`: the tables no entity maps to, such as a join table or a projection a list reads, keyed by table name. The tables are read from the source of `database/migrations` (`StructureTables`), replayed in the order the files run, so the check needs no database; Laravel's own migrations (`0001_01_01_*` and the ones its packages publish) and the kit's `audit_entries` are left out. A table is placed under the entity whose model maps to it: the model a root's repository reads through (`newQuery()`, or its `@extends`), otherwise the model of the entity's own name. Any other table goes in the `tables` of the context its first foreign key leads to, through as many such tables as it takes, or of the only context there is; `in-json` names one that leads to none while there are more. A table's name is unique across every manifest, in either place (*check `files`*). Each table has:
  - `model`: the class of `app/Models` that maps to it, or `null` in `tables` for one no model maps to;
  - `columns`: every column but the uuid key, `timestamps()` and `softDeletes()`, in the order the migrations build them. `type` is the Blueprint method that builds it (`string`, `unsignedBigInteger`, `jsonb`), with its `length` (or `precision` and `scale`), and `enum` names the enum the model casts it to, written as a state's type is. `nullable`, `unique` and `index` are there only when true, `default` when the migration sets one, and `comment` holds the comment written above the column (or its `->comment()`);
  - `foreignKeys`: each `foreignUuid('{x}_id')->constrained('{table}')`, with the parent `table`, the stem of its delete behaviour (`cascade`, `restrict`, `null` or `noAction`, left out while none is chosen), and the flags and comment a column has;
  - `indexes`: the indexes that span more than one column, or one the table does not list (`created_at`), each with its `columns` in order, whether it is `unique`, and its `comment`. An index on one listed column is that column's `index` or `unique`, whichever way the migration writes it;
  - `timestamps` and `softDeletes`.

  A polymorphic reference (a `{x}_id` uuid beside a `{x}_type` string, with no foreign key) is written by hand (migrations.md), so neither column is listed. A manifest written before tables were designed reads the tables the code builds, as one written before exceptions reads its exceptions. Only the shape of a table is checked here; whether a foreign key has the right delete behaviour and an index it leads, or a nullable column says why, is migrations.md's.
- `replaces`, on a port or a use case and only while a replacement is under way: the adapter it is moving away from, or the use case it stands in for. The code never says it, so the reader leaves it out and the comparison skips it.
- `description`, on any entry and at the top of the manifest beside `context`: what the piece, or the context as a whole, is for, in the team's own words, at most 1000 characters. Like `replaces`, only the manifest holds it: the reader leaves it out, the comparison skips it, a sync keeps it, and a file leaves it out while it is empty. An entity whose entry holds a description and nothing else keeps its entry. The structure screen draws it on the card.

## The HTTP layer

An HTTP resource is named after its controller:
- `{Model}Controller` is the resource `{Model}`;
- an action controller (actions.md), `{Model}{Verb}Controller` or `{Model}Bulk{Verb}Controller`, joins the resource of the longest model name its stem starts with;
- a controller named after no model (`ReportController`) is a resource of its own, with `model: null`.

It has a file of its own, because an entry point calls into more than one context: an action of `Agent` may call a use case of `Credit`.

```json
{
    "resource": "Crate",
    "model": "Crate",
    "controller": {
        "create": [],
        "index": ["Shipping/ListCrates"],
        "store": ["Shipping/CreateCrate"]
    },
    "actions": {
        "Ship": { "row": true, "bulk": true, "useCases": ["Shipping/ShipCrate"] }
    },
    "policy": ["create", "ship", "shipAny", "viewAny"],
    "pages": {
        "crates/create": "form",
        "crates/index": "table"
    },
    "menu": { "title": "crates.title", "icon": "package" }
}
```

- `controller`: each public method of `{Resource}Controller`, a resource method or another such as `picker`, with the use cases it injects as `Context/UseCase`.
- `actions`: each verb, whether it has a row controller and a bulk one, and the use cases they inject.
- `policy`: the abilities of the policy the model names through `#[UsePolicy]`, `before()` aside, or `null`.
- `pages`: each page a controller method renders by a literal name, as its path under `resources/js/pages`, with its kind:
  - `table` when it calls `useDataTable(`;
  - `grid` when it renders `<InfiniteScroll`;
  - `form` when it imports a form from `@/components/*/form`;
  - `dialog` for a create or edit form that opens in a dialog instead (form-pages.md, "Form dialogs"), kept at the path its page would have (`crates/edit`): its `create` or `edit` returns `JsonResponse`, and `resources/js/components/{model}/form-dialog.tsx` exports `Create{Model}Dialog` or `Edit{Model}Dialog`. `kit:plan` waits on a controller whose `create` or `edit` still renders a page, since `make:controller` leaves it as it is;
  - `page` for anything else.
- `description`, beside `resource`: what the resource is for, in the team's own words, kept as a context's is. It is not the menu entry's `description`, which is a translation key the app shows.
- `menu`: the entry in the main menu of the resource's page at `{folder}/index` (its table or grid, else its plain page, such as a board), its title's translation key and its lucide icon, and when it has them the key of the `group` it sits in, the translation key of its `description` and the policy's `ability` it asks in place of `viewAny`, read from `app/Http/Navigation.php`; `null` keeps the list page out of the menu on purpose; left out, the manifest says nothing yet (list-pages.md, "Menu"). For example `"menu": { "title": "invoices.title", "icon": "wallet", "group": "finance" }`.

The starter kit's controllers (`App\Http\Controllers\Settings`) and the kit's own audit log page (`AuditEntry`) have no resource manifest.

## The shared kernel

`.kit/structure/Shared.json` lists the enums and value objects of `app/Domain/Shared/{Enums,ValueObjects}`, which every context may use (layers.md), and the invalid values of `app/Domain/Shared/Exceptions` that guard them. Its other sections stay empty, because the rest of the shared kernel is the kit's own: its base classes, `IdGenerator` and `Clock`. The kit's `Money` and `Percent` (numbers.md) and `CalendarDate`, `CalendarPeriod`, `TimeOfDay`, `Duration` and the enum `Weekday` (dates.md) are left out too, with their exceptions and the kit's own `DomainValueException`, `EntityNotFoundException` and `RepositoryException`. An entry of the shared kernel has `"aggregate": null`; an entry of any other context names its aggregate.

**Do**
- Keep one manifest for every context under `app/Domain` or `app/Application`, and one for the shared kernel. The kit's other folders (`Audit`, `Auth`, `Concerns`) have none. *Check `in-json`.*
- Keep one manifest for every HTTP resource. *Check `in-json`.*
- List every aggregate, enum, value object, domain service, port and use case, every table the migrations build, and every controller method, action, policy ability and page, the code holds. *Check `in-json`.*
- Give each enum and each value object a name no other one of its kind has in the same context. A section is keyed by name, so a second one would hide the first. *Check `matches`.*
- Build everything the manifest lists. A piece written into the manifest before its code is a piece still to build, so the check stays red until it exists. *Check `in-code`.*
- Describe each piece the way the code is built: a use case's shape, what it returns and the repositories it injects, an enum's cases and a value object's fields in their order, where each case of a status may go, and the use cases a controller method or an action calls and the kind of a page. *Check `matches`.*
- Keep each file in the canonical shape: only the keys above, StudlyCase names, a `context` that matches the file name, cases that fit the enum's backing, transitions that list every case once and lead only to other cases, and an `aggregate` only outside the shared kernel. *Check `files`.*

**Don't**
- Edit the reader's output by hand to make a check pass. Change the code or the manifest so they agree. *Review only.*
- Name a context or a class with `Sampling`. The generator tests own that name, and every check skips it (testing.md). *Review only.*

**Why:** a diagram, a plan or a document about a project's structure is only worth reading while it is true. One that nothing checks drifts from the code within weeks. Because the manifest is checked against the code both ways, it can be trusted as the plan the code follows. The check reads the code with the same reader `kit:import` writes with, so the two never disagree about what the code holds.

## Building from the manifest

Design a piece in the manifest first, then let the kit build it:

```
php artisan kit:plan     the steps, each done, ready or waiting with its reason; writes nothing
php artisan kit:apply    runs every ready step, plans again, and repeats until none is ready
```

The structure screen's **Run** menu runs the same commands, and `kit:import --sync`, `kit:retire` and Wayfinder's route generator, as `php artisan …` in a process of their own (`StructureCommands`), narrowed to the context or HTTP resource open or on everything. It shows what they print, then draws the graph again from a fresh request, because a run that writes classes leaves the process it ran in holding them as they were.

Each step is a `make:*` generator or one line written into a project file. They run in this order, because each one reads what the ones before it wrote:

| Step | Runs | Waits for |
|---|---|---|
| aggregate, child, repository | `make:entity`, `make:entity --child`, `make:eloquent-repository` | the root, for a child or a repository |
| enum, value object | `make:enum --case=…` (a status adds `--transitions --transition=Case:Next,Next`), `make:value-object --field=…` | for a value object, every enum and class its fields name |
| exception, state, method | `make:domain-exception --kind=refusal\|value` (the shared kernel's first, with `--domain=Shared`), `make:entity-state --field=…`, `make:entity-method --param=… --throws=…` | for an exception, its aggregate's root; for a property, its entity, the classes its type names and every property listed before it; for a method, its entity, every class its parameters name and every exception it throws |
| use case refusal | `make:domain-exception --kind=application [--use-case=…]` | the use case it names |
| port, service, application service, use case | `make:port`, `make:domain-service`, `make:application-service`, `make:use-case` | nothing |
| binding | a line above `// kit:bindings` | the port and its adapter |
| migration, model | `make:table-migration`, `make:table-model` for each table a context designs | for a migration, every table its foreign keys point at; for a model, every enum its columns name |
| mapping | `make:repository-mapping` for each repository whose root's table a context designs | the repository, the models, every property of the root's and the children's state, and a property or a column that does not map plainly, which a person maps |
| model, policy | `make:model --factory` (`make:table-model` when a context designs the model's table), `make:policy` | nothing |
| controller | `make:controller --only=…` (`--dialog=create,edit` names the forms of kind `dialog`) | the use cases its methods call |
| action | `make:action` | the fields of its use case's Command |
| form request, form pages | `make:form-request`, `make:form-page` (`--dialog=…` as for the controller) | the fields of the create Command, then `{Model}FormValues` |
| list page | `make:list-page` | a key other than `id` in its Row's `toArray()` |
| route | a line above `// kit:routes` | the controller |
| menu | a line above `// kit:menu` in `app/Http/Navigation.php` | the index route, the page at `{folder}/index`, the policy's `viewAny` (or the `ability` the entry names) and the `group` it names |

An exception step builds each exception `exceptions` designs, of its own kind and in its own home, and a domain or application service's step passes `--exception` when its `exception` is true. An application service's step passes the first repository of its own context as `--repo`, or the first of another when it has none of its own, and `--actor` when `actor` is true; `matches` names any other repository the manifest lists and the constructor lacks until it is added by hand. A name a method throws that no manifest designs is built as a refusal of the method's own aggregate when it is named bare. One of the shared kernel or of another aggregate is built from the manifest that designs it, and the method waits for it; one no manifest designs is built by hand. `make:entity-method` writes the method with `@throws` and an empty body, and gives the entity's Unit test a `describe()` for it with a todo for the case that passes and one per exception. The `throw` lines are written by hand, and `matches` names each one the body still lacks.

`make:entity-state` adds one property per step, in the order `state` lists them: a private property the constructor promotes, a parameter of `reconstitute()` passed on to `new self(…)`, and a getter at the end of the Getter Section. It leaves `create()` alone, because what a new entity starts with is a rule a person decides, and says so: until `create()` passes the property, PHPStan and the entity's test name the gap. The repository's `toModel()` and `toEntity()` are written by the mapping step once the entity's table is designed (below), and by hand otherwise (repositories.md). The entity's Unit test gets a todo per property in its `reconstitute()` cases.

A table a context designs, under an entity's `table` or under `tables`, gets two steps:
- `make:table-migration {table}` writes the migration that creates it, in the shape migrations.md gives it: the uuid key, the foreign keys first and then the columns, each in the order the design lists them, the timestamps, the soft deletes, then the indexes, and a `down()` that drops the table. Once the migrations build the table, the step is done while they build everything the design lists. When the design gains a column, a foreign key, an index, timestamps or soft deletes, the step writes a new migration that adds just that, with a `down()` that drops it again: a built table is never edited in place. Each migration is dated after every migration already there, so one written in the same run as the table it points at runs after it.
- `make:table-model {table}` writes the model the table names, with its factory, in the shape models.md gives them: `#[Fillable]` with every column but `id`, a `@property` per column typed as its cast returns, `casts()` with each enum, integer, boolean, decimal, date and json column, `SoftDeletes`, `#[Table]` when the model's name does not lead to the table and `#[WithoutTimestamps]` when the table has none, a `belongsTo` per foreign key whose parent has a model and a `hasMany` per designed table whose foreign key points here. The factory fills `id`, the parent's factory for a foreign key, null for a nullable column, the first case of an enum and a fitting fake value for the rest. A factory already there is kept. The step is done once the model exists.

A column, foreign key or index the design describes another way than the migrations built it, or no longer lists, is not changed by a step, because changing it is a decision about the rows already stored: write that migration by hand, and the check names what still differs. The repository step passes `--model` when the root's `table` names a model other than the aggregate.

An aggregate whose root's table is designed, with `repository` true, gets one more step: `make:repository-mapping {Aggregate} --domain={Context}/{Aggregate}` writes the repository's mapping in the shape repositories.md gives it. Each property of the state maps to the column of its snake_case name (`recipientLabel` to `recipient_label`):
- a builtin that fits the column, the enum the model casts the column to, and a value object the model casts its column to, as they are;
- a backed enum the model does not cast by its `value`, and back with `from()`;
- a `DateTimeImmutable` in a date-time column through `Carbon`, and back with `toImmutable()`;
- `Money`, `Percent`, `Duration` and `TimeOfDay` by the integer they hold, and `CalendarDate` in a date column (numbers.md, dates.md).

The property named after a child in the plural (`lids: array` for `Lid`) holds the children. The step overrides `newQuery()` to eager load each child table through the root model's relation, adds a `write()` that calls `syncChildren()` with the child table's foreign key to the root, and adds a private `to{Child}Model()` / `to{Child}Entity()` pair per child. The step waits for the repository, the models and every property of the state, and names what does not map plainly: a property with no column or a type that does not fit it, a column no property maps to (the timestamps aside), or a child with no table, no foreign key to the root or soft deletes. Map that by hand. The step is done once the two todos `make:eloquent-repository` leaves in `toModel()` and `toEntity()` are gone, so a mapping written by hand is never touched, nor one a child is added to later.

A list query `make:use-case` writes for a model whose table a context designs names that table and its columns in its todos: a commented `SEARCHABLE_COLUMNS` entry per text column, the commented filter on its first enum column, and a commented argument of the Row per column (list-queries.md).

A step that waits for code a person writes (a Command's fields, a Row's keys) is named with its reason. Fill that code in and run `kit:apply` again: a plan is read from the code as it stands, so it picks up where the last run stopped. A step that fails is not run again in the same call.

**Do**
- Put `// kit:bindings` on its own line as the last entry of one provider's `$bindings`, `// kit:routes` as the last line of the route group new pages belong in, and `// kit:menu` as the last line of the main menu's list in `app/Http/Navigation.php` (list-pages.md). `kit:apply` writes each new line above its marker with full class names, then runs Pint on that file, which imports them, so the file passes the project's Pint as it stands. Without a marker, or with more than one, it prints the line for you to place. *Review only.*
- Finish by hand what no generator writes, which `kit:plan` lists last as the check's own messages: a second repository or one from another context, a handler that returns `int`, a controller method outside the resource ones, a policy ability other than the CRUD ones, `#[UsePolicy]` on the model, a page of kind `page`, and the lines that open a dialog from the list page, which `make:form-page` prints. *Review only.*
- Count a route as done once a route file registers its controller, whatever uri it chose. A `Route::resource()` counts only once it registers every controller method the manifest lists: `kit:apply` widens its `only([...])` to cover a method added later, and leaves one an `except([...])` holds back for you to change by hand. *Review only.*

**Why:** the generators already write every piece the way the rules want it. What was left to a person was the order to run them in and the flags each one takes, which the manifest already says. The run stops where only a person can go on, and says why, so it never writes a page for a Row that has no columns.

## Reading the manifest

A step-by-step guide to the screen, with screenshots, is at `/kit/docs/guides/structure-screen` (the package's `docs/structure-screen.md`).

Open `/kit/structure` on a local environment. It draws the manifest with React Flow (stack.md):
- the overview, with one card per context and per HTTP resource, and the calls between them;
- one context: its aggregates, domain services, ports and use cases, with the repositories each one injects. Its enums and value objects show once the legend shows their kinds, each tied to the aggregate that holds it and each value object to the classes its fields name. Its entities show once the legend shows **Entity**: the root and every child of each aggregate, each card listing its state (`status: CrateStatus`) above its methods, one with neither reading `nothing designed yet`, each tied to its aggregate as the root or a child, and to the classes its state and its methods' parameters name. Its exceptions show once the legend shows **Exception**, each tied to the aggregate that refuses or rejects with it or to the use case that refuses with it, and, with **Entity** shown too, to each method that throws it. These kinds start hidden; a click on a legend row shows or hides its kind, and the browser remembers the kinds a context hides. An aggregate, its entities and the enums, value objects and exceptions it holds sit in a frame (each node's `group`). The shared kernel's view is its vocabulary and its invalid values alone, so they always show;
- one HTTP resource: its model, policy, controller, actions and pages, with the use cases they call.

Each kind of card has a shape, a colour and an icon of its own, and a legend on the canvas names the kinds the view draws. Shapes follow DDD and hexagonal diagrams: a port is a hexagon, a model a cylinder, a page a sheet, an enum a label tag, a value object a soft-cornered card, an exception a red card with a warning sign. Colours follow Event Storming: an aggregate is amber, and so is each of its entities, a use case blue, a list use case green. An enum's card lists its cases, a value object's its fields, and an entity's its methods, up to six lines. A status gets an icon of its own, and its card lists where each case may go (`Open → Sealed`, `Sealed · final`) in place of the values; the side panel lists them all, with each method's parameters and the exceptions it throws. Double-click a card to open what it names. A card of another context opens that context.

Every card carries the status `kit:plan` gives it. `done` means it is built. `ready` shows the command `kit:apply` runs, and `waiting` shows what a person writes first. `differs` shows the check's own message where the code is built another way. What the code holds and the manifest does not have a card for is listed beside the diagram as work by hand.

The header keeps to a few menus: **New** or **Add**, grouped by layer and marked with each kind's icon, and **?** for the guide and the docs. The side panel shows the selected card's lists as small tables, with its actions as icons. A change answers with the graph, which the screen swaps in where it stands: the cards keep their places (only a new one is laid out, and one a taller card would overlap moves down), the selected card stays selected, and a card a form adds is selected and brought into view. Cards drag, resize, fold to their header and tuck away what only they lead to, and the browser keeps each view's canvas until **Reset layout**; pointing at a card dims what it is not tied to.

**Do**
- Build every node, edge and status in `StructureGraph`, where a Feature test reads it. The page only lays the graph out and draws it, because no test runs its TypeScript. *Review only.*
- Register the kit's screens in `KitServiceProvider` for the `local` environment only. They read the developer's files and answer to no policy, so they never reach a deployed app. *Review only.*

**Why:** a manifest of a few hundred lines is read faster as a picture, and a status on every card tells a reviewer what is designed and not yet built without running a command.

## Editing the manifest

The structure screen changes the manifests too. From the overview, create a context or an HTTP resource. Open a context to add an aggregate, a domain service (with or without its own exception), a port, a use case, an enum, a value object, an exception, or the state or a method of an entity, and the shared kernel to add an enum, a value object or an invalid value. Open a resource to add a controller method, an action or a page, or to set its model and the abilities of its policy. Select a card to change or remove what it shows. Every change is saved at once, in canonical form, and git keeps what was there before.

`StructureEditor` checks each change before it writes it:
- the manifest's shape, the same checks as `files`;
- the design rules a manifest alone can show:
  - a domain service injects only repositories of its own context, and a creates-shaped one names an aggregate of its own context (layers.md);
  - a use case that takes a Command returns `void`, `string` or `int`, or `result` when it hands back a Result (handlers.md);
  - a list use case is named `List{Name}` and takes a Command and returns a Result (list-queries.md);
  - a repository a piece injects exists, in its own context or the one it names;
  - an adapter is `Infra/{Folder}/{Prefix}{Port}`, the class `make:port --adapter` writes;
  - `index` reads its page through one use case that takes a Command and returns a Result, `store` and `update` write through one that takes a Command, and `destroy` deletes through one plain use case (form-pages.md, write-path.md);
  - an action calls one use case that takes a Command, shared by its row and bulk controllers, and a bulk action's returns `int` (actions.md);
  - a page of a resource with a model sits where its generator writes it: a list page at `{names}/index`, named after the index's `List{Names}` use case, and a form page at `{models}/create` or `{models}/edit`, where a dialog keeps its form's path too;
  - a list page needs `index`, and a form page `store` or `update`; a create dialog needs `create` and `store`, and an edit dialog `edit` and `update`; a dialog needs a model and opens from a page at `{models}/index`; a policy needs a model to attach to;
  - an enum or a value object belongs to an aggregate of its own context, or to none in the shared kernel, which holds nothing else; an enum's cases are TitleCase with values no two share, and a value object's fields are camelCase;
  - an enum that lists where its cases may go is a status, named `*Status`, and lets at least one case become another (states.md). Each case goes only to other cases of the enum, and a case the form leaves out is final;
  - a field's type is a builtin, a class a manifest designs (an enum, a value object, an entity) or a class the code already has, so a value object can name an enum that is only designed;
  - a method belongs to a root or a child of an aggregate of its own context, and its group follows from its name: an `assert…` method is an assertion, any other a behaviour. Its name and its parameters are camelCase, each parameter's type is one a field could name, and only the last is variadic;
  - an exception ends with `Exception`. A refusal or an invalid value belongs to an aggregate of its own context, a use case's refusal to a use case of its own context or to none, and the shared kernel holds invalid values only (exceptions.md);
  - a property of an entity's state is camelCase, never `id`, and has a type a field could name;
  - an exception a method throws ends with `Exception`. One of the entity's own aggregate is named bare, and `kit:apply` builds it when it is missing; one of the shared kernel or of another aggregate is one a manifest designs or the code already has;
  - a table (`StructureTableDesign`, migrations.md and models.md) has a snake_case name no manifest designs elsewhere and no migration builds yet. An entity's table names its model. Each column is built by a Blueprint method the kit writes, never `float()`, `double()`, `enum()` or `json()`. A decimal names its precision and scale, and a column that casts to an enum holds its values: a string as long as the longest case, or an integer for an int-backed one. A nullable column or foreign key says in its comment what null means. A foreign key is named `{x}_id`, points at a table a manifest designs, a migration builds or Laravel's own migrations build (`users`), chooses its delete behaviour (nullable when it sets null) and has an index it leads;
  - a table the migrations build keeps its name, its model once one maps to it, every column, foreign key and index they build, and its timestamps and soft deletes. It takes new ones, which the migration step adds;
- that the manifest did not change on disk since the page loaded.

A refused change comes back under the field that holds it, and nothing is written.

The editor designs a table as a whole: an entity's when the change names the entity, else one of the context's `tables`. A table the migrations do not build yet can be removed, and moving one between the two places is a remove and a save. For an entity with no table, it proposes one from the state:
- each property as the column its snake_case name leads to: a `string`, an `int` as an integer, a `bool` as a boolean, an `array` as jsonb, and a `DateTimeImmutable` as a timestamp;
- `Money` as an unsigned big integer in minor units, `Percent`, `Duration` and `TimeOfDay` as the integer they hold, and `CalendarDate` as a date;
- an enum as a string of at least 20 that fits every case, or an integer for an int-backed one, cast to the enum;
- a value object as jsonb, which the mapping step waits on until the model casts it;
- a `?T` as nullable, with a comment the editor refuses until it says what null means;
- a `{x}Id` that names another aggregate as a foreign key to that aggregate's table, with its delete behaviour still to choose;
- for a child, a foreign key to the root's table that cascades.

The property that holds a root's children is left out, and so is one that has no column of its own, such as a `float` or a pure enum. The proposal names each of those. The structure screen's table page that draws this is not built yet: until it is, write a table into the manifest by hand, or let `kit:import --sync` read it from the migrations.

**Do**
- Describe any card, built or not, from its panel's **Description**: a piece, an entity, a context or a resource. Only the manifest holds a description, so writing one never turns a check red. *Review only.*
- Change through the screen only what the code does not have yet. A piece the code already has is locked there, because changing it in the manifest alone would turn `matches` or `in-code` red at once. Changing what is built is a change to the code, which `kit:import --sync` or the card's **Sync from code** then takes into the manifest, or a replacement. *Review only.*
- Change an entity one property or one method at a time. A built entity takes new state and new methods, but a property or a method the code already has is locked, and the entity's entry goes once it lists neither. A new property goes after the others, where `make:entity-state` adds it. The editor keeps an aggregate, and a child, while the manifest lists state or methods for it. *Review only.*
- Change a built aggregate's children one at a time, the same way: it keeps its name and its repository, but takes a new child, which `kit:apply` builds with `make:entity --child`, and gives up a child the code does not have yet. Design the child's table, and `kit:apply` builds it, its model and, while the repository's mapping is not written yet, its `syncChildren()`. Otherwise the mapping is yours to write (repositories.md). *Review only.*
- Keep an aggregate's name, and its repository, while a service or a use case in any context injects it, or while it holds an enum, a value object or an exception. Keep a use case's name while a refusal of its own belongs to it. The editor refuses all of these, and lists who still needs it. *Review only.*
- Keep an enum's or a value object's name while a value object's field names it, and an exception's while a method throws it. *Review only.*
- Keep `index` while a list page needs it, the last of `store` and `update` while a form page posts to it, and the two methods a dialog loads from and posts to while it is there. *Review only.*

**Why:** a design written straight into the manifest is checked against the rules before any code exists, so `kit:apply` never builds a piece the architecture checks would refuse.

## Replacing what is built

A built adapter, use case, domain service or application service changes by replacement. The new one is built beside the old, the code that names the old one is pointed at the new, and the old one goes once the tests pass:

```
Replace on a built card    adapter: the new one, replaces: the old one   |  a new use case, replaces: the old one,
                                                                           every resource calling the new one
                                                                        |  a new domain or application service,
                                                                           replaces: the old one
php artisan kit:apply      builds the new piece, then swaps it in and runs Pint on what it changed:
                             an adapter in the provider that binds its port,
                             a use case's Handler, Command and Result in app/Http and tests/Feature/Http,
                             a service's classes everywhere under app/ and tests/
php artisan kit:retire     once nothing else names the old piece and the Architecture, Unit and
                             Feature suites pass: removes its classes and their tests, and the
                             manifest forgets what was replaced
```

The swap of a use case waits until the new Command has fields when the old one has them, so the requests that build it keep compiling. Anything else that still names the old piece (a console command, a job, another handler) keeps it from retiring, and `kit:retire` lists where.

A list use case (list-queries.md) is replaced the same way, by a list that names what it lists another way: `ListCustomers` by `ListOwnCustomers`, never by `ListCustomer`. Its Row, its sort enum and its TypeScript twins are named after what it lists, so a second name keeps both sets apart until the old one goes.
- `kit:apply` builds the new use case with its query port, its adapter and the binding line. Once its Row has keys, `make:list-page --types-only` writes its twins (`{Item}Row`, `{Item}Filters`, `{Items}Query`) beside the old ones, and the old twins stay, so the page keeps compiling.
- The swap then points the controller and its tests at the new Handler, Command and Result, as for any use case. It also renames the old twins to the new ones in `resources/js/pages` and `resources/js/components`, and runs prettier on what it renamed. It never touches `resources/js/types`.
- `kit:retire` takes away more for a list:
  - the old use case's adapter and the adapter's test;
  - the line that binds its query port, with the `use` lines nothing else needs;
  - its sort enum, unless another list still sorts by it;
  - its twins, removed from their types file. A file left with no export is removed, together with its line in `types/index.ts`.
- A twin that something else in `resources/js` still names keeps the old list from retiring, the same way the PHP code does.

A domain service (layers.md) is replaced by one with a name of its own, built by `make:domain-service` in the shape the old one has, and with `--exception` when the old one has `{Old}Exception`. A service's types reach further than its callers: a handler catches its exception, a repository takes its Data, a controller catches it by name.
- The swap points every class in the old service's folder at its counterpart in the new one, in every file under `app/` and `tests/` but the old service's own and its tests. A class named with the old prefix maps to the new prefix (`{Old}Data` to `{New}Data`), and any other class maps to the same name (`UserCredentialData`).
- It waits until the new service can stand in:
  - its `handle()` no longer throws the generator's "not implemented yet";
  - its Data has fields when the old Data has them;
  - every old class that something outside its folder still names has a counterpart.
- `kit:retire` removes the old folder and its test, Unit or Feature, once nothing names the old folder any more.

An application service (layers.md) is replaced the same way, by one `make:application-service` builds with the old one's shape, repository, actor and exception. The swap and `kit:retire` work on `app/Application/{Context}/Services/{Old}` and its tests as they do on a domain service's folder.

**Do**
- Replace a use case, a domain or application service, or an adapter through the screen, or by writing the new entry with `replaces` into the manifest. Cancel a replacement that is not retired from the same card. *Review only.*
- Point what `kit:retire` lists at the new piece by hand, then run it again. *Review only.*

**Don't** replace an aggregate this way yet. It reaches the model, the table and every layer that names its vocabulary. *Review only.*

**Why:** a piece in use is changed without a moment where nothing works: the old one keeps running until the new one is built, wired in and proven by the same tests, and only then does it go.

## Scaffold, never hand-write

Run `php artisan kit:import` to write the manifest of every context and HTTP resource that has none, or name them with `--context={Context}` and `--resource={Resource}`. An existing manifest is kept, because it may hold design that is not built yet. Once the code has changed (a method's parameters, an enum's cases, a use case's repositories), bring the manifest back in step with `--sync`: every piece the code has takes the code's shape, and what only the manifest lists stays and is listed, because it may be designed and not built, or the old name of something renamed in the code. `--sync --prune` takes that out too, `--sync --dry-run` prints the changes and writes nothing, and a piece in the middle of a replacement is left as the design says. `--force` reads the whole file back from the code, and anything designed but not built is lost, every `description` with it.

**Why:** a built piece is locked on the screen, so the code is where it changes. Merging the code into the manifest keeps both the change and the design still to build, where reading the whole file back would lose the second.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "structure"`, exactly as stack.md describes. The `subject` is the class for `in-json` (the controller of an HTTP resource, or its model when it has none), and the manifest's path from the project root for `files`, `in-code` and `matches`. Only the user may add an entry.
