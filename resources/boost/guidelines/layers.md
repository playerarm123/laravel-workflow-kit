# Layers

Code is split into layers with one job each, and dependencies point inward only. When a test fails, its layer name tells you where the problem is.

Enforced by `tests/Architecture/LayersTest.php` (`php artisan test --testsuite=Architecture`). The spec in `layersSpec()` is the machine-checked copy of this file: change the two together. Contexts are discovered from the folders under `app/Domain`, so a new context is policed the moment it exists.

## The layers
| Layer | Namespace | Job |
|---|---|---|
| Domain | `App\Domain\{Context}` | Business rules in plain PHP: entities, value objects, enums, domain services, and the ports the domain needs. |
| Application | `App\Application\{Context}` | Use cases: one handler per thing a user or the system can do. |
| Infrastructure | `App\Infra` | Implements the ports: Eloquent repositories and read queries, hashing, storage, ids, external APIs. |
| Entry points | `App\Http`, `App\Console`, `App\Jobs`, `App\Listeners` | Turn a request, command, job or event into a handler call, and its result into a response. |
| Models | `App\Models` | Eloquent models: persistence shape, casts, relations, route binding (models.md). |
| Policies | `App\Policies` | Authorization against a model and the user. |

## Who may depend on whom
| Layer | May use | Never |
|---|---|---|
| Domain | Its own context and `App\Domain\Shared`. | Illuminate or any other `App\*` layer (*check `domain-framework`*); another context (*check `domain-context`*). `Shared` uses no context at all. |
| Application | Any context in the domain, other application code, `spatie/laravel-data`, and only these framework pieces: `Facades\DB` (transactions), `Support\Str`, `Pagination` and its contracts, `Support\Collection`/`LazyCollection`, `Contracts\Support\Arrayable`, `Http\UploadedFile`, `Contracts\Auth`. | Infra, Http, Models, Policies, Console, Providers (*check `application`*). |
| Infrastructure | Domain, Application, Models, and the whole framework. | Entry points (*check `infra`*). |
| Entry points | Application, Models (route binding and reads), Policies, and from the domain **only** enums, value objects and exceptions. | Infra (*check `entry-points`*). Bind an application interface in a provider instead. Never a domain repository, entity, service or port. |
| Models | Domain enums and value objects (for casts), and their policy through `#[UsePolicy]`. | Application, Infra, entry points (*check `models`*). |
| Policies | Models and domain enums. | Application, Infra, entry points (*check `policies`*). |

**Why:** the domain must be testable with no framework and no database, and every write must take one path through a handler. An entry point that reaches a repository directly skips the handler's transaction and actor, and the rules those carry.

## Domain layout
```text
app/Domain/{Context}/
├── {Aggregate}/
│   ├── {Root}Entity.php        ← aggregate root, extends AggregateRoot
│   ├── {Root}Repository.php    ← repository interface (a port), roots only
│   ├── Entities/               ← child entities, extend DomainEntity
│   ├── ValueObjects/  Enums/  Exceptions/  Definitions/
├── Services/{Name}/            ← domain services with their Data, Result and Exception
├── Ports/                      ← every other interface the infrastructure implements
└── Exceptions/                 ← the context's base exception only
app/Domain/Shared/              ← shared kernel: same folders, depends on no context
```

The base classes live at fixed paths, and every project ships them: `app/Domain/Shared/DomainEntity.php`, `AggregateRoot.php` and `DomainException.php`. *Check `base-classes`.*

**Do**
- Give every aggregate root `extends AggregateRoot`. *Check `aggregate-root`.*
- Put child entities in `Entities/` with `extends DomainEntity`. They are reached only through their root.
- Make a repository accept and return aggregate roots only. Never write a repository for a child entity. *Check `aggregate-repository`.*
- Scaffold an enum with `php artisan make:enum {Name} --domain={Context}/{Aggregate} --string --case=…` and a value object with `php artisan make:value-object {Name} --domain={Context}/{Aggregate} --field=name:Type`, or `--domain=Shared` for the shared kernel. Each lands in the aggregate's `Enums/` or `ValueObjects/`, never in `app/Enums`. *Review only.*
- Scaffold any other port with `php artisan make:port {Name} --domain={Context}` (`--domain=Shared` for the shared kernel), or `--application={Context}` for a port only a use case needs. Once its methods are written, run it again with `--adapter={Prefix} --infra={Folder}`. That writes `App\Infra\{Folder}\{Prefix}{Name}` with every method throwing until it is written, and the adapter's test. It prints the binding line for a provider. *Review only.*

## Entity, domain service or application service
Put a rule in the first place that can own it:

1. **The entity**, when the rule needs only one aggregate's state, e.g. "a suspended wallet cannot be debited". Write it as a method on the root. A status it changes declares its moves on its enum (states.md).
2. **A domain service** (`{Context}/Services/{Name}/{Name}Service::handle(…)`), when the rule spans several aggregates, or several instances of one, inside **one** context. Examples: "a wallet is opened once per owner", or moving money between two wallets.
3. **An application service**, the use-case handler (`Application/{Context}/UseCases/{Name}/{Name}Handler::__invoke(Command): Result`), for everything that is not a business rule:
   - the transaction (`DB::transaction`) and who is acting
   - minting the id of the root it creates
   - calling into several contexts
   - side effects: files, mail, notifications, audit log
   - turning input into domain types and domain results into a `Result`

**Domain service**
- **May** load and save through repositories of **its own context**, and call other domain services of that context.
- **Never** opens a transaction, touches another context, or knows who the actor is. The handler that calls it wraps the transaction.
- Lives at the context level only, never inside an aggregate folder (*check `service-location`*). Its Data, Result and Exception classes, and any other type it speaks in, sit in its folder.
- Holds no interface. An interface the infrastructure implements is a port and lives in `{Context}/Ports/` or `Shared/Ports/` (*check `ports`*). A repository interface is the one port that stays at the aggregate root.

**The shape of a domain service.** Scaffold it with `php artisan make:domain-service {Name} --domain={Context}` and one of `--creates={Aggregate}`, `--data` or `--plain`.
- `Services/{Name}/` holds exactly one `{Name}Service`, and its only public method is `handle()`. *Check `service-shape`.*
- `handle()` takes one of three shapes. Use one of them, never a fourth. *Check `service-shape`.*

| Shape | `handle()` | Use when |
|---|---|---|
| Creates | `(string $id, {X}Data $data): {Root}Entity` | the service builds a new aggregate. The handler mints the id. |
| Data in, Result out | `({X}Data $data): {Name}Result` | the input or the output is more than one value. |
| Plain | any parameters and return, with no `*Data` and no `*Result` | the service needs an id or two and returns an entity, a list or nothing. |

- A `*Data` or `*Result` that `handle()` names lives in the service's own folder. A Data may carry a name of its own (`UserCredentialData`) when several callers build it. A Result is always `{Name}Result`.

**Why:** a service's input differs with its job, so one fixed signature would force empty Data and Result classes around a single id. Three named shapes still let a reader tell from the signature alone what a service does, and an agent has three patterns to copy instead of eleven.

**Application service (handler)**. Its shape, actor, ids and transaction are in handlers.md.
- It is the only way in. Controllers, commands, jobs and listeners call handlers. Domain services are called only by handlers or by other domain services of the same context.
- Every class named `*Handler` lives in `app/Application/{Context}/UseCases/`. *Check `handlers`.*

**Why:** each layer then has one reason to change. A rule moves into the entity, a cross-aggregate rule into a domain service, and orchestration into a handler. A reader always knows where to look.

## Stepping outside this file
Use `rule-overrides.json` with `"rule": "layers"`, exactly as stack.md describes. Only the user may add an entry.
