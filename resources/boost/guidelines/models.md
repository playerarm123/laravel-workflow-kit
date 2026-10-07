# Models

A model describes how a table is stored and nothing else. Every model has the same shape:

```
#[Fillable([...])]  #[UsePolicy(...)]  /** @property {cast type} ${column} for every column */
class {Model} extends Model
  /** @use HasFactory<{Model}Factory> */ use HasFactory
  use KeyedByUuid                     the uuid IdGenerator mints, never the model; a malformed route value is a 404
  casts(): array
  {relation}(): BelongsTo|HasMany|MorphTo|…   /** @return {Relation}<{Related}, $this> */

database/factories/{Model}Factory.php   'id' => fake()->uuid()
```

Enforced by `tests/Architecture/ModelsTest.php` (`php artisan test --testsuite=Architecture`), which reads the source, and `tests/Feature/ModelsTest.php`, which compares each model with the table it maps. The specs in `modelsSpec()` and `modelSchemaSpec()` are the machine-checked copy of this file: change them together. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Kit files

These live at fixed paths. Copy them into a new project as they are. *Check `kit-files`.*
- `app/Models/Concerns/KeyedByUuid.php`, with `tests/Feature/Models/Concerns/KeyedByUuidTest.php`
- `stubs/model.stub`, which uses `KeyedByUuid` and declares `#[Fillable]`, `casts()` and `@use HasFactory`, and `stubs/factory.stub`, which fills `id`
- `tests/Feature/ModelsTest.php`

## The key

```php
use HasFactory, KeyedByUuid;
```

**Do** key every model with `KeyedByUuid`, the users model included. It says the key is a string that never increments, and it answers a route value that is not a uuid with `ModelNotFoundException`, so the request is a 404. *Check `key`.*

**Don't** use `HasUuids`, `HasVersion4Uuids` or `HasUlids`, declare `$incrementing` or `$keyType` by hand, or change `$primaryKey`. *Check `key`.*

**Why:** `HasUuids` fills a missing id, which makes it a second place that mints ids beside `IdGenerator` (handlers.md). Without it, a write that forgot the id fails at the insert, where the mistake is made, instead of landing with an id nothing else knew about. The two properties alone leave route binding to the database, and Postgres refuses `/{models}/abc` against a uuid column with a 500.

## Attributes

**Do**
- Declare the columns a repository fills with `#[Fillable([...])]`, and the columns a response must never carry with `#[Hidden([...])]`. *Check `attributes`.*
- Declare casts in a `casts()` method. Cast every enum column to its domain enum and every value object column to its cast (layers.md lets a model use both). *Check `attributes`.*

**Don't**
- Use the `$fillable`, `$guarded`, `$hidden`, `$visible` or `$casts` properties. *Check `attributes`.*
- Call `Model::unguard()` anywhere in `app/`. *Check `attributes`.*

**Why:** one idiom per job means a reader looks in one place. The attributes sit on the class beside `#[UsePolicy]`, so everything about how a row is exposed is read before the body starts.

## The docblock

```php
/**
 * @property string $id
 * @property CustomerStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
```

**Do**
- Give every column of the table one `@property` line, and nothing else. A relation needs no line, because Larastan reads it from the method; the schema check skips `@property-read`. *Check `docblock`, and the schema check `properties`.*
- Write the type the cast returns, not the column's type: the enum (`CustomerStatus`, not `string`), `bool`, `int`, `array`, `Carbon` for `datetime` and `CarbonImmutable` for `immutable_datetime`. Add `|null` when the column is nullable. *Schema check `casts`.*
- Annotate the factory: `/** @use HasFactory<{Model}Factory> */`. *Check `factory`.*

**Why:** Larastan reads the schema, not `casts()`. Without the block it thinks a repository assigns an enum to a string column, and a typo in a column name passes analysis and fails only at runtime.

## Members

A model holds `casts()`, its relations and, when a route binds by another column, `getRouteKeyName()` or `resolveRouteBinding()`. Nothing else. *Check `members`.*

**Do** give every relation a native return type and a generic `@return`: `@return BelongsTo<Agent, $this>`. Larastan cannot see an untyped relation, so `whereHas('results')` fails analysis while it works at runtime. *Check `members`.*

**Don't**
- Add an accessor or mutator (`Attribute`), a local scope (`scope*()`, `#[Scope]`), a helper method, or `boot()`/`booted()`. *Check `members`.*
- Listen to model events: no `#[ObservedBy]`, no `$dispatchesEvents`. *Check `members`.*

**Why:** the business rules live in the entity, and a list's filters live in its query adapter (layers.md, list-queries.md). Repositories write with upsert and insert, which fire no model events, so an observer or a `booted()` hook runs on some writes and silently skips the rest (repositories.md).

## Polymorphic relations

**Do** register `Relation::enforceMorphMap()` in a service provider whenever a model declares a `MorphTo`, mapping every value of the domain enum the `{x}_type` column holds (migrations.md). *Check `morph-map`. The full map is review only.*

**Why:** without the map, Eloquent reads the stored enum value as a class name and fails only when the relation is read.

## Factories

```php
public function definition(): array
{
    return [
        'id' => fake()->uuid(),
        'agent_id' => Agent::factory(),
        // …
    ];
}
```

**Do**
- Give every model a factory at `database/factories/{Model}Factory.php`, declared `@extends Factory<{Model}>`. *Check `factory`.*
- Fill the id with `'id' => fake()->uuid()` in `definition()`. *Check `factory`.*
- Fill a foreign key with the parent's factory (`Agent::factory()`). Use `fake()->uuid()` only for a polymorphic id, which has no foreign key. *Review only.*

**Don't** take an id in a factory from `IdGenerator` or `Str::uuid*()`/`Str::ulid()`. *Check `factory`.*

**Why:** a test fixes the next id by swapping `IdGenerator` (testing.md). A factory that asked the same port would then hand every row that one id. Because the model mints none, a factory without an `id` fails on its first insert.

## Seeders

**Do** write a seeder's rows through a handler, as reference data installs do. When no handler fits, mint each id through `IdGenerator`. *Review only.*

**Don't** use `fake()`, `$this->faker` or `Str::uuid*()`/`Str::ulid()` in a seeder. *Check `seeders`.*

**Why:** a seeder runs in production, where `composer install --no-dev` leaves no faker. `IdGenerator` is the one id source there too.

## Scaffold, never hand-write

Run `php artisan make:model {Model} --factory`. The stubs write `KeyedByUuid`, `#[Fillable([])]`, `casts()`, `@use HasFactory` and a factory that fills `id`. Then add a `@property` line per column, the fillable columns, the casts and the relations. Without `--factory` the model has no factory, and the `factory` check names it.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "models"`, exactly as stack.md describes. The `subject` is the model class for a model check, and the file's path from the project root for a factory or seeder check. Only the user may add an entry.
