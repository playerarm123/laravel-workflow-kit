# Audit log

Every change a handler makes leaves one entry behind, written in the same transaction as the change:

```
{Verb}{Aggregate}Handler
  DB::transaction {
    $repo->save($x)
    $audit->record('{subject}.{verb}', $x, [ids, enum values, amounts, codes])
  }
    → DatabaseAuditLog: + id (IdGenerator), subject type/id, actor_id (Context), occurred_at
    → audit_entries
```

The audit log is append only. Nothing changes or removes an entry.

Enforced by `tests/Architecture/AuditLogTest.php` (`php artisan test --testsuite=Architecture`). The spec in `auditLogSpec()` is the machine-checked copy of this file: change the two together. The adapter's behaviour is proven in `tests/Feature/Infra/Audit/DatabaseAuditLogTest.php`. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Kit files

These live at fixed paths. Copy them into a new project as they are. *Check `kit-files`.*
- `app/Application/Audit/AuditLog.php`, the port, and `AuditLogException.php` beside it
- `app/Infra/Audit/DatabaseAuditLog.php`, the adapter, bound in `app/Infra/Audit/AuditServiceProvider.php` (*check `binding`*)
- `app/Models/AuditEntry.php`, which names `AuditEntryPolicy` through `#[UsePolicy]`, and the migration `create_audit_entries_table`
- `tests/Feature/Infra/Audit/DatabaseAuditLogTest.php`
- the read-only page every project ships, listed in `page_files` of the spec:
  - the use case `app/Application/Audit/UseCases/ListAuditEntries/`, the sort `app/Application/Audit/AuditEntryListSort.php` and the adapter `app/Infra/Persistence/Eloquent/Queries/EloquentListAuditEntriesQuery.php`, bound in `AuditServiceProvider`
  - `app/Http/Controllers/AuditEntryController.php` and `database/factories/AuditEntryFactory.php`
  - `resources/js/pages/audit-entries/index.tsx`, `resources/js/components/audit-entry/{detail-dialog,table-toolbar}.tsx` and `resources/js/types/audit-entry.ts`
  - the tests of the handler, the adapter, the controller (`AuditEntryController/IndexTest.php`) and the page (`tests/Browser/AuditEntries/IndexTest.php`)

## The port

```php
interface AuditLog
{
    public function record(string $event, AggregateRoot $subject, array $data = []): void;
}
```

**Do**
- Declare `record()` and nothing else. *Check `port`.*
- Let the adapter fill in everything a handler could get wrong:
  - the id, from `IdGenerator`;
  - the subject type, the root's class name without `Entity`, in snake case (`LotteryTypeEntity` → `lottery_type`), and the subject id from `id()`;
  - the actor, from `Context::get('actor_id')`, which the middleware that binds `UserContext` adds (exceptions.md). Laravel carries it into every queued job. A console command or the scheduler never sets it, so the system's own work is recorded with no actor;
  - the time.
- Turn a refused insert into `AuditLogException`, with the database's exception as `previous`. It is a failure (exceptions.md): nobody catches it, and it rolls the change back with it.

**Why:** an entry is worth something only if nobody can bend it. The handler names what happened. Who did it and when come from places the handler cannot reach, so a handler can neither forget the actor nor name someone else.

## Recording

```php
return DB::transaction(function () use ($customer) {
    $this->customers->save($customer);
    $this->audit->record('customer.created', $customer, ['type' => $customer->type()->value]);

    return $customer->id();
});
```

**Do**
- Record from every handler that writes: one that injects a domain repository, or a domain service that injects one (handlers.md). *Check `record`.*
- Record inside the same `DB::transaction()` as the write. Because the entry is a write of its own, every handler that writes opens a transaction. *Check `record`.*
- Record after the write, with the root as it was saved. *Review only.*
- Record one entry per root the use case is about. Bookkeeping it does on other roots on the way (a usage it attaches, the child rows it opens) rides along as ids in that entry's `$data`. A bulk handler records one entry per row it really changed, never one for the batch and never one for a row it skipped. *Review only.*
- Name the event as a string literal `{subject}.{past-tense verb}` in snake case: `customer.created`, `lottery_type.status_changed`, `wallet.credited`. The subject part is the root the entry is about. *Check `event`.*
- Pass `$data` as an array literal of what a reader needs to tell this entry from the next: ids, enum values, amounts and codes. Use the target value of a change (`'status' => 'inactive'`). *Review only.*
- Give work the system runs the same `record()` call. It is recorded with no actor. *Review only.*

**Don't**
- Put personal data in `$data`: names, usernames, emails, phones, passwords, notes, addresses, account numbers, tokens and secrets. The policy is the one `{X}LogPayload` and an exception's context follow. *Check `data-keys`.*
- Put the actor in `$data` (`actor`, `actorId`, `{x}By`). *Check `data-keys`.*
- Use the port anywhere but a use-case handler. A controller, a command, a job, a listener or a domain service never records. *Check `home`.*
- Write to `audit_entries` any other way, or audit through model events. Repositories write with upsert and insert, which fire no model events (repositories.md, write-path.md).
- Build the event or the data keys at runtime. The checks read literals only. *Review only.*

**Why:** a handler is the one place that knows a use case finished and holds its transaction (layers.md). An entry written outside that transaction can outlive a change that rolled back, or go missing for one that landed.

## Scaffold, never hand-write

`php artisan make:use-case {Name} --domain={Context} --repo={Aggregate}` writes a handler that writes: it injects `AuditLog`, opens the `DB::transaction()` and leaves a commented `record('{aggregate}.created|changed', …)` inside it, with a todo for the audit case in its test. The `record` check keeps naming the handler until the call is written. Name the verb, then fill in `$data`.

## Reading

Every project ships the kit's read-only page at `audit-entries.index`. It reads the log through a list query like any other list (list-queries.md, list-pages.md): search on the subject id, a filter by subject type and by day, newest first. The kit decides everything about the page but who may open it.

**Do** give the page what only the project knows: *check `page`.*
- `app/Policies/AuditEntryPolicy.php` with `viewAny` alone, answered for the users who may read the log (authorization.md). Scaffold it with `php artisan make:policy AuditEntry` and delete the other abilities.
- `Route::resource('audit-entries', AuditEntryController::class)->only(['index'])` in the routes the signed-in users reach.
- `auditLogReader(): User` and `auditLogOutsider(): User` in `tests/Pest.php`: a user the policy allows and a signed-in user it refuses. The kit's controller and Browser tests sign in through them, so they never name a role.
- Every word the page speaks (`page_lang_keys` in the spec) in every `lang/*.json`.

**Do** add the link to the sidebar behind a shared `can` key that asks `viewAny` of `AuditEntry`, as the other menu entries do. *Review only.*

**Do** show the actor by looking the ids up for the page, in the adapter's `$preparePage`. `actor_id` is a string with no foreign key, because the user table's key differs between projects and removing a user must never rewrite what they did.

**Do** give every event, every subject type and every data key a label in every `lang/*.json`: `audit-entries.events.{event}`, `audit-entries.subject_types.{subject}` (the event's part before the dot) and `audit-entries.data_keys.{key}`. The page translates them with `t()` and shows the event's code under its label. The row and the table keep the codes, so a reworded label never rewrites what was recorded. *Check `labels`.*

**Don't** translate in PHP (`__()` in the row or the adapter). The row is the wire contract the TypeScript twin mirrors, and it carries codes, not text in the viewer's language.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "audit-log"`, exactly as stack.md describes. The `subject` is the handler class, or the file's path from the project root for `home`. Only the user may add an entry.
