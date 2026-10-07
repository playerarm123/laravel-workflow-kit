# Handlers

A use-case handler is the only way into the application (layers.md). Every handler follows the same outline:
- one `__invoke()` in one of three shapes
- the actor read from a port, never passed in
- new ids taken from `IdGenerator`
- one transaction around a write that spans more than one call

Enforced by the package's `tests/Architecture/HandlersTest.php` (`php artisan test --testsuite=Architecture`). The spec in `handlersSpec()` is the machine-checked copy of this file: change the two together.

## Kit files

These live at fixed paths. *Check `kit-files`.*
- `app/Domain/Shared/Ports/IdGenerator.php`, bound to an adapter in a service provider (*check `binding`*). `ClonableAggregate` uses the same port.
- `app/Application/Auth/UserContext.php`, the actor port. It declares at least `id(): string`, and each project adds the methods its roles need. Middleware binds it for each request.

## Scaffold, never hand-write

Run `php artisan make:use-case {Name} --domain={Context}` with one of `--command`, `--command --result` or `--plain`, plus `--repo={Aggregate}` when the handler writes one and `--creates` when it creates a root (it injects `IdGenerator` and returns the new id). List use cases add `--query` (list-queries.md).

## The three shapes

Every handler is a `final class {Name}Handler` whose only public method is `__invoke()`. Its signature takes one of three shapes. Use one of them, never a fourth. *Check `shape`.*

| Shape | `__invoke()` | Use when |
|---|---|---|
| Command in, Result out | `({Name}Command $command): {Name}Result` | the caller needs more than one value back |
| Command in | `({Name}Command $command): void\|string\|int` | the input is more than an id or two. It returns nothing, the id it created, or how many rows it changed. |
| Plain | ids, enums, value objects or dates in, with no Command and no Result | the input is an id or two (delete, sync), or nothing at all (work the system runs) |

- A use case with a Command lives in its own folder: `UseCases/{Name}/{Name}Handler.php`, beside `{Name}Command` and `{Name}Result`. The folder may also hold classes only that use case needs (a list's Criteria, Row and read port).
- A plain handler lives at `UseCases/{Name}Handler.php`, with no folder.
- The Command and the Result are `final`, extend `Spatie\LaravelData\Data`, and are named after their use case. One use case never borrows another's Command or Result.

**Don't** pass an id beside a Command (`(string $id, UpdateXCommand $command)`). An id the handler acts on is a field of the Command.

**Why:** a reader can tell from the signature alone what a handler takes and returns. An agent then has three patterns to copy. When ids travel both inside and beside the Command, every caller has to guess which way this handler wants them.

## Who is acting

**Do**
- Read the actor inside the handler. Inject `UserContext`, or an application class built on it (a `Current{X}` resolver that turns the actor into a scope, such as the owner whose rows these are).
- Build the domain's record of who acted (the actor id on a value object) inside the handler, from that port.
- Give work the system runs (the scheduler, a queued job, a console command) a handler of its own, which takes no actor.

**Don't**
- Carry the actor in a Command field or a plain parameter. Never use `actorId` or `{x}By` (`createdBy`, `publishedBy`), and never an owner id the session decides. *Check `actor` catches the names. The scope is review only.*
- Depend on `UserContext` from an entry point. The middleware that binds it is the only exception. *Check `actor`.*

An id the user picks as the target of the action stays in the Command. Example: the agent whose limit a company user changes.

**Why:** a handler that trusts whatever actor it is given acts as anyone a caller names. A hand-edited form or a forgotten controller line is then enough to impersonate another user. When the handler reads the actor itself, it cannot be spoofed, and a test states who is acting in one line by binding the port.

## New ids

**Do** mint the id of every root a handler creates inside that handler, through an injected `IdGenerator::next()`. Hand it back as the `string` return or a field of the Result. *Check `ids`.*

**Don't** mint an id in an entry point, or anywhere in `app/Domain`, `app/Application` or the entry points with `Str::uuid()`, `Str::uuid7()`, `Str::orderedUuid()`, `Str::ulid()`, `Ramsey\Uuid` or `Symfony\Component\Uid`. The adapter behind `IdGenerator` is the one place that does. *Check `ids`.*

**Why:** with one source of ids, the format changes in one adapter, and a test can swap the port to know the id in advance.

## Transactions

**Do** wrap every write in one `DB::transaction()` when a handler writes in two or more calls. A write is a call to a repository, or to a domain service that injects a repository. A service that only computes, such as a planner, does not count. *Check `transaction`.*

Every handler that writes also records an audit entry, which is a write of its own (audit-log.md). So every handler that writes opens a transaction, and audit-log.md checks it. The repository alone already keeps one aggregate atomic (repositories.md).

**Don't** open a transaction in an entry point (write-path.md). *Check `transaction`.*

**Why:** a use case is the unit that must land whole. If a handler writes twice with no transaction around it, a failure in the second write leaves the first one behind.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "handlers"`, exactly as stack.md describes. Only the user may add an entry.
