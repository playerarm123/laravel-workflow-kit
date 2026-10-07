# Migrations

A migration changes the schema and nothing else. Every table it builds has the same shape:

```
Schema::create('{table}')
  uuid('id')->primary()                                   the id IdGenerator mints (handlers.md)
  foreignUuid('{x}_id')->constrained('{table}')->{cascade|restrict|null}OnDelete()  + an index it leads
  unsignedBigInteger('{amount}')                          money in minor units
  string('{status}', {length})                            a backed enum's value
  jsonb('{details}')
  timestamps()
down(): Schema::dropIfExists('{table}')
```

Enforced by `tests/Architecture/MigrationsTest.php` (`php artisan test --testsuite=Architecture`), which reads the source, and `tests/Feature/Database/MigrationsTest.php`, which reads the schema the migrations build on the declared engine. The specs in `migrationsSpec()` and `migrationSchemaSpec()` are the machine-checked copy of this file: change them together. Items marked *review only* cannot be read from the code, so a reviewer checks them.

The migrations Laravel and its packages publish (`0001_01_01_*`, passkeys, two-factor columns) keep their own idioms. The source checks skip them, but the schema checks read every table they build.

## Kit files

These live at fixed paths. Copy them into a new project as they are. *Check `kit-files`.*
- `stubs/migration.create.stub`, which opens every table with `$table->uuid('id')->primary()`, with `stubs/migration.update.stub` and `stubs/migration.stub`
- `tests/Feature/Database/MigrationsTest.php`

## Keys

```php
$table->uuid('id')->primary();
$table->foreignUuid('agent_id')->index()->constrained('agents')->cascadeOnDelete();
```

**Do**
- Key every table on `$table->uuid('id')->primary()`. That includes `users`: a new starter kit ships it on `id()`, so change its migration (and the `user_id` of sessions and passkeys) before the first deploy. Only the framework's own tables (`migrationSchemaSpec()['framework_tables']`) key their own way. *Check `primary-key`.*
- Declare a foreign key as `foreignUuid('{x}_id')->constrained('{table}')`, naming the parent table. *Check `foreign-key`.*
- Say what a delete of the parent does, on every foreign key: *check `foreign-key`.*
  - `cascadeOnDelete()` for rows the parent owns: the child rows of its aggregate (repositories.md), or a row that means nothing without it;
  - `restrictOnDelete()` for a reference to another aggregate, so the delete is refused while anything still points at it. Use it for a child too when the child's presence is the reason the entity refuses the delete (`assertCanBeDeleted()`, write-path.md), so the database is the last guard when two requests race;
  - `nullOnDelete()` for an optional reference that may outlive its target.
- Give every foreign key an index it leads: `->index()` on the column, or the column first in a composite or unique index. Postgres never indexes a foreign key by itself, so every join and every cascade scans the child table. *Check `foreign-key-index`.*
- Keep a polymorphic reference (an owner that is one of several aggregates) as a `uuid` id beside a `string` type that holds a domain enum value, never a class name. It has no foreign key, so say so in a comment. *Review only.*

**Don't**
- Use `id()` (*check `primary-key`*), or `foreignId()`, `foreignUlid()` or any `morphs()` (*check `foreign-key`*).
- Use `foreignUuidFor(Model::class)`, a bare `constrained()`, or the longhand `->references()->on()` / `->onDelete('…')`. *Check `foreign-key`.*

**Why:** the database is the last thing that holds when two requests race. A foreign key whose delete behaviour was never chosen turns into whatever the engine defaults to, and a reader cannot tell a decision from an accident. A uuid key is what `IdGenerator` mints, so a row's id never depends on the insert order.

## Columns

**Do**
- Store money as an integer in minor units (satang, cents): `unsignedBigInteger('amount')`, or `bigInteger()` when it can go below zero. Name the unit in a comment. A rate or a multiplier is a scaled integer the same way (basis points, hundredths). *Check `money` bans floating point. `decimal` is review only.*
- Store a backed enum's value in `string('{x}', {length})`, with a length that fits every case. *Check `enum`.*
- Store json in `jsonb()`. Laravel writes it as `json` on mysql/mariadb, so the same migration runs on every engine. *Check `json`.*
- Use `timestamps()` on every table a repository writes. The repository moves `updated_at` and never rewrites `created_at` (repositories.md). *Review only.*
- Make a column nullable only when null means something, and say what in a comment above it ("null means no limit"). *Review only.*

**Don't**
- Use `float()` or `double()`. *Check `money`.*
- Use `enum()`. Adding a case would then need a migration, and each engine stores it differently. *Check `enum`.*
- Use `json()`. *Check `json`.*
- Use a feature only another engine has, such as `->after()` on Postgres, a generated column or a partial index on MySQL (stack.md). *Review only.*

**Why:** a float cannot hold 0.10 exactly, so money summed as floats drifts by a satang that nobody can explain. An integer in minor units is exact on every engine. A database enum or a json column type fixes a choice in the schema that the domain owns.

## Schema only

**Do**
- Return `new class extends Migration`, with a `down()` that undoes exactly what `up()` did: `Schema::dropIfExists()` for every table it created, in reverse order of dependence, and `dropColumn()` / `dropConstrainedForeignId()` for what it added. *Check `shape`.*
- Keep every migration reversible as a whole: `migrate:reset` leaves only the `migrations` table, and `migrate` builds everything again. *Check `rollback`.*
- Use `DB::statement()` only for a constraint the schema builder cannot write on the declared engine, such as a CHECK or a deferrable unique on Postgres. *Review only.*
- Add a new migration to change a table that has been deployed. Edit a migration in place only while no database that must keep its data has run it. *Review only.*

**Don't**
- Reach into `App\` (a model, an enum, a constant). A migration is history: it must run the same way after the app has changed. *Check `schema-only`.*
- Read or write rows (`DB::table()`, `DB::insert()`, a model), or mint ids or values (`Str::`). Data a new schema needs is filled in by a console command that calls a handler, so it goes through the aggregate's rules, `IdGenerator` and the audit log (handlers.md, audit-log.md). *Check `schema-only`.*

**Why:** a migration runs once on every database, years apart. One that loads a model breaks when the model is renamed, and one that writes rows skips every rule the write path holds. A `down()` that misses a table only shows up on the day someone has to roll back.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "migrations"`, exactly as stack.md describes. The `subject` is the migration's path from the project root for a source check, the table (`{table}` or `{table}.{column}`) for a schema check. Only the user may add an entry.
