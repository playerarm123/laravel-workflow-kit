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

The manifest is the source of truth for **structure**: which contexts there are, with each one's aggregates, domain services, ports and use cases, the enums and value objects their aggregates speak in with where each status may go next, the behaviours and assertions of each entity with what each one throws, and which HTTP resources there are, with each one's controller, actions, policy and pages. It is not the truth for behaviour. The body of an entity's method, the rules inside a value object, the methods of an enum other than `transitions()`, the body of a handler and the fields of a form are still written in PHP and TypeScript.

Enforced by the package's `tests/Architecture/StructureManifestTest.php` (`php artisan test --testsuite=Architecture`). The spec in `structureManifestSpec()` is the machine-checked copy of this file: change the two together. The reader and the files are proven in the package's `tests/Feature/Console/Commands/Structure/`, which the project runs as its `Kit` testsuite.

## Kit files

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`), and a stub there is replaced by a file of the same name in the project's `stubs/`. `php artisan kit:install` writes the rest from the kit, and the check fails when one is missing or differs from the kit's copy.
- *package:* `src/Console/Commands/Structure/StructureReader.php`, which reads the code without booting the app
- *package:* `src/Console/Commands/Structure/StructureFiles.php`, which reads, writes and validates the manifests
- *package:* `src/Console/Commands/Structure/StructureComparer.php`, the one comparison the check and `kit:plan` report
- *package:* `src/Console/Commands/Structure/StructurePlanner.php`, `StructureMarkers.php` and `PlansStructure.php`, which plan and apply
- *package:* `src/Console/Commands/KitImportCommand.php` (`kit:import`) and its test
- *package:* `src/Console/Commands/KitInstallCommand.php` (`kit:install`) with `Structure/KitInstaller.php`, which writes the kit's files from `resources/kit`: `files/` the project keeps as the kit ships them (`--force` puts a changed one back), `scaffold/` written once and owned by the project
- *package:* `src/Console/Commands/KitPlanCommand.php` (`kit:plan`), `KitApplyCommand.php` (`kit:apply`) and `KitRetireCommand.php` (`kit:retire`), with `Structure/StructureSwapper.php`, which points code at a replacement
- *package:* `src/Console/Commands/Structure/StructureGraph.php`, which builds what the screen draws, with `StructureEditor.php` and `StructureResourceEditor.php`, which write what it changes in a context and in an HTTP resource
- `app/Providers/KitServiceProvider.php`, listed in `bootstrap/providers.php`, with `resources/views/kit/structure.blade.php` and the Vite entry `resources/js/kit/structure.tsx`, listed in `vite.config.ts`
- *package:* `src/Console/Commands/MakeEnumCommand.php`, which takes over Laravel's `make:enum`, and `src/Console/Commands/MakeValueObjectCommand.php` (`make:value-object`), with `stubs/value-object.stub` and `stubs/value-object-test.stub`
- *package:* `src/Console/Commands/MakeEntityMethodCommand.php` (`make:entity-method`), which adds a behaviour or an assertion to an entity, with `src/Console/Commands/Concerns/ResolvesManifestTypes.php`, which it shares with `make:value-object`
- *package:* `src/Console/Commands/Structure/KitDocs.php`, with the project's `resources/views/kit/docs.blade.php`, the kit's docs screen at `/kit/docs`: every guideline read from the workflow kit as it stands, and the `make:*` and `kit:*` commands the console has

## The manifest

```json
{
    "context": "Shipping",
    "aggregates": {
        "Crate": { "children": ["Lid"], "repository": true }
    },
    "services": {
        "PackCrate": { "shape": "creates", "creates": "Crate", "repositories": ["Crate"] }
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
    "entities": {
        "Crate": {
            "aggregate": "Crate",
            "behaviours": {
                "seal": { "params": { "label": "CrateLabel", "tags": "...string" }, "throws": ["CrateSealedException", "Shared/InvalidMoneyException"] }
            },
            "assertions": {
                "assertIsOpen": { "params": {}, "throws": ["CrateSealedException"] }
            }
        }
    }
}
```

Each value is read off the code:
- `aggregates`: a folder `{Context}/{Aggregate}/` whose `{Aggregate}Entity` extends `AggregateRoot` (layers.md). `children` are the classes in its `Entities/`, and `repository` says whether `{Aggregate}Repository` exists.
- `services`: `{Context}/Services/{Name}/{Name}Service`. Its `shape` is one of the three `handle()` shapes in layers.md: `creates`, `data` or `plain`. `creates` names the aggregate a `creates` service builds, and is `null` for the other shapes.
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
- `entities`: an aggregate's root or one of its children, by its name without `Entity`, with the public methods it declares itself. `aggregate` names the aggregate that holds it. An entity that declares neither kind of method is left out.
  - `behaviours`: the methods that change the entity: not static, returning `void`, and not named `assert…`.
  - `assertions`: the methods named `assert…`.
  - Getters, `has…`/`can…`, `create()`/`reconstitute()` and what a base class or an interface declares are left out.
  - Each method keeps its `params` in order, each type written as a value object's field is, with `...` before a variadic one.
  - `throws` lists every exception the method's body throws (`throw X::…()` or `throw new X`), and every one thrown by a method of the same class it calls through `$this->`, `self::` or `static::`, however deep. A rethrow (`throw $e`) adds nothing. An exception is named as a type is: by its name in its own aggregate, `Shared/{Name}`, or `{Context}/{Aggregate}/{Name}`.
- `replaces`, on a port or a use case and only while a replacement is under way: the adapter it is moving away from, or the use case it stands in for. The code never says it, so the reader leaves it out and the comparison skips it.

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
    }
}
```

- `controller`: each public method of `{Resource}Controller`, a resource method or another such as `picker`, with the use cases it injects as `Context/UseCase`.
- `actions`: each verb, whether it has a row controller and a bulk one, and the use cases they inject.
- `policy`: the abilities of the policy the model names through `#[UsePolicy]`, `before()` aside, or `null`.
- `pages`: each page a controller method renders by a literal name, as its path under `resources/js/pages`, with its kind:
  - `table` when it calls `useDataTable(`;
  - `grid` when it renders `<InfiniteScroll`;
  - `form` when it imports a form from `@/components/*/form`;
  - `page` for anything else.

The starter kit's controllers (`App\Http\Controllers\Settings`) and the kit's own audit log page (`AuditEntry`) have no resource manifest.

## The shared kernel

`.kit/structure/Shared.json` lists the enums and value objects of `app/Domain/Shared/{Enums,ValueObjects}`, which every context may use (layers.md). Its other sections stay empty, because the rest of the shared kernel is the kit's own: its base classes and `IdGenerator`. The kit's `Money` and `Percent` are left out too (numbers.md). An entry of the shared kernel has `"aggregate": null`; an entry of any other context names its aggregate.

**Do**
- Keep one manifest for every context under `app/Domain` or `app/Application`, and one for the shared kernel. The kit's other folders (`Audit`, `Auth`, `Concerns`) have none. *Check `in-json`.*
- Keep one manifest for every HTTP resource. *Check `in-json`.*
- List every aggregate, enum, value object, domain service, port and use case, and every controller method, action, policy ability and page, the code holds. *Check `in-json`.*
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

Each step is a `make:*` generator or one line written into a project file. They run in this order, because each one reads what the ones before it wrote:

| Step | Runs | Waits for |
|---|---|---|
| aggregate, child, repository | `make:entity`, `make:entity --child`, `make:eloquent-repository` | the root, for a child or a repository |
| enum, value object | `make:enum --case=…` (a status adds `--transitions --transition=Case:Next,Next`), `make:value-object --field=…` | for a value object, every enum and class its fields name |
| exception, method | `make:domain-exception --kind=refusal`, `make:entity-method --param=… --throws=…` | for a method, its entity, every class its parameters name and every exception it throws |
| port, service, use case | `make:port`, `make:domain-service`, `make:use-case` | nothing |
| binding | a line above `// kit:bindings` | the port and its adapter |
| model, policy | `make:model --factory`, `make:policy` | nothing |
| controller | `make:controller --only=…` | the use cases its methods call |
| action | `make:action` | the fields of its use case's Command |
| form request, form pages | `make:form-request`, `make:form-page` | the fields of the create Command, then `{Model}FormValues` |
| list page | `make:list-page` | a key other than `id` in its Row's `toArray()` |
| route | a line above `// kit:routes` | the controller |

An exception step builds only an exception of the method's own aggregate whose name ends with `Exception`. One of the shared kernel or of another aggregate is built by hand, and the method waits for it. `make:entity-method` writes the method with `@throws` and an empty body, and gives the entity's Unit test a `describe()` for it with a todo for the case that passes and one per exception. The `throw` lines are written by hand, and `matches` names each one the body still lacks.

A step that waits for code a person writes (a Command's fields, a Row's keys) is named with its reason. Fill that code in and run `kit:apply` again: a plan is read from the code as it stands, so it picks up where the last run stopped. A step that fails is not run again in the same call.

**Do**
- Put `// kit:bindings` on its own line as the last entry of one provider's `$bindings`, and `// kit:routes` as the last line of the route group new pages belong in. `kit:apply` writes each new line above its marker, with full class names, so no `use` line changes. Without a marker, or with more than one, it prints the line for you to place. *Review only.*
- Finish by hand what no generator writes, which `kit:plan` lists last as the check's own messages: a second repository or one from another context, a handler that returns `int`, a controller method outside the resource ones, a policy ability other than the CRUD ones, `#[UsePolicy]` on the model, and a page of kind `page`. *Review only.*
- Count a route as done once a route file registers its controller, whatever uri it chose. *Review only.*

**Why:** the generators already write every piece the way the rules want it. What was left to a person was the order to run them in and the flags each one takes, which the manifest already says. The run stops where only a person can go on, and says why, so it never writes a page for a Row that has no columns.

## Reading the manifest

Open `/kit/structure` on a local environment. It draws the manifest with React Flow (stack.md):
- the overview, with one card per context and per HTTP resource, and the calls between them;
- one context: its aggregates, domain services, ports and use cases, with the repositories each one injects. Its enums and value objects show when the **Vocabulary** switch is on, each tied to the aggregate that holds it and each value object to the classes its fields name. Its entities that list methods show when the **Behaviour** switch is on, each tied to its aggregate as the root or a child, and to the classes its methods' parameters name. The shared kernel's view is its vocabulary alone, so it always shows;
- one HTTP resource: its model, policy, controller, actions and pages, with the use cases they call.

Each kind of card has a shape, a colour and an icon of its own, and a legend on the canvas names the kinds the view draws. Shapes follow DDD and hexagonal diagrams: a port is a hexagon, a model a cylinder, a page a sheet, an enum a label tag, a value object a soft-cornered card. Colours follow Event Storming: an aggregate is amber, and so is each of its entities, a use case blue, a list use case green. An enum's card lists its cases, a value object's its fields, and an entity's its methods, up to six lines. A status gets an icon of its own, and its card lists where each case may go (`Open → Sealed`, `Sealed · final`) in place of the values; the side panel lists them all, with each method's parameters and the exceptions it throws. Double-click a card to open what it names. A card of another context opens that context.

Every card carries the status `kit:plan` gives it. `done` means it is built. `ready` shows the command `kit:apply` runs, and `waiting` shows what a person writes first. `differs` shows the check's own message where the code is built another way. What the code holds and the manifest does not have a card for is listed beside the diagram as work by hand.

**Do**
- Build every node, edge and status in `StructureGraph`, where a Feature test reads it. The page only lays the graph out and draws it, because no test runs its TypeScript. *Review only.*
- Register the kit's screens in `KitServiceProvider` for the `local` environment only. They read the developer's files and answer to no policy, so they never reach a deployed app. *Review only.*

**Why:** a manifest of a few hundred lines is read faster as a picture, and a status on every card tells a reviewer what is designed and not yet built without running a command.

## Editing the manifest

The structure screen changes the manifests too. From the overview, create a context or an HTTP resource. Open a context to add an aggregate, a domain service, a port, a use case, an enum, a value object or a method of an entity, and the shared kernel to add an enum or a value object. Open a resource to add a controller method, an action or a page, or to set its model and the abilities of its policy. Select a card to change or remove what it shows. Every change is saved at once, in canonical form, and git keeps what was there before.

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
  - a page of a resource with a model sits where its generator writes it: a list page at `{names}/index`, named after the index's `List{Names}` use case, and a form page at `{models}/create` or `{models}/edit`;
  - a list page needs `index`, and a form page `store` or `update`; a policy needs a model to attach to;
  - an enum or a value object belongs to an aggregate of its own context, or to none in the shared kernel, which holds nothing else; an enum's cases are TitleCase with values no two share, and a value object's fields are camelCase;
  - an enum that lists where its cases may go is a status, named `*Status`, and lets at least one case become another (states.md). Each case goes only to other cases of the enum, and a case the form leaves out is final;
  - a field's type is a builtin, a class a manifest designs (an enum, a value object, an entity) or a class the code already has, so a value object can name an enum that is only designed;
  - a method belongs to a root or a child of an aggregate of its own context, and its group follows from its name: an `assert…` method is an assertion, any other a behaviour. Its name and its parameters are camelCase, each parameter's type is one a field could name, and only the last is variadic;
  - an exception a method throws ends with `Exception`. One of the entity's own aggregate is named bare, and `kit:apply` builds it when it is missing; one of the shared kernel or of another aggregate must exist already, because nothing builds it;
- that the manifest did not change on disk since the page loaded.

A refused change comes back under the field that holds it, and nothing is written.

**Do**
- Change through the screen only what the code does not have yet. A piece the code already has is locked there, because changing it in the manifest alone would turn `matches` or `in-code` red at once. Changing what is built is a replacement. *Review only.*
- Change an entity one method at a time. A built entity takes new methods, but a method the code already has is locked, and the entity's entry goes with its last method. The editor keeps an aggregate, and a child, while the manifest lists methods for it. *Review only.*
- Keep an aggregate's name, and its repository, while a service or a use case in any context injects it, or while it holds an enum or a value object. The editor refuses both, and lists who still needs it. *Review only.*
- Keep an enum's or a value object's name while a value object's field names it. *Review only.*
- Keep `index` while a list page needs it, and the last of `store` and `update` while a form page posts to it. *Review only.*

**Why:** a design written straight into the manifest is checked against the rules before any code exists, so `kit:apply` never builds a piece the architecture checks would refuse.

## Replacing what is built

A built adapter, use case or domain service changes by replacement. The new one is built beside the old, the code that names the old one is pointed at the new, and the old one goes once the tests pass:

```
Replace on a built card    adapter: the new one, replaces: the old one   |  a new use case, replaces: the old one,
                                                                           every resource calling the new one
                                                                        |  a new domain service, replaces: the old one
php artisan kit:apply      builds the new piece, then swaps it in and runs Pint on what it changed:
                             an adapter in the provider that binds its port,
                             a use case's Handler, Command and Result in app/Http and tests/Feature/Http,
                             a domain service's classes everywhere under app/ and tests/
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

**Do**
- Replace a use case, a domain service or an adapter through the screen, or by writing the new entry with `replaces` into the manifest. Cancel a replacement that is not retired from the same card. *Review only.*
- Point what `kit:retire` lists at the new piece by hand, then run it again. *Review only.*

**Don't** replace an aggregate this way yet. It reaches the model, the table and every layer that names its vocabulary. *Review only.*

**Why:** a piece in use is changed without a moment where nothing works: the old one keeps running until the new one is built, wired in and proven by the same tests, and only then does it go.

## Scaffold, never hand-write

Run `php artisan kit:import` to write the manifest of every context and HTTP resource that has none, or name them with `--context={Context}` and `--resource={Resource}`. An existing manifest is kept, because it may hold design that is not built yet. `--force` reads it back from the code, and anything designed but not built is lost.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "structure"`, exactly as stack.md describes. The `subject` is the class for `in-json` (the controller of an HTTP resource, or its model when it has none), and the manifest's path from the project root for `files`, `in-code` and `matches`. Only the user may add an entry.
