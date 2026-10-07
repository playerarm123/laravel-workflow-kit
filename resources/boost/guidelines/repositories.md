# Repositories

Every aggregate reaches the database through one repository. Every repository is written the same way: one-line public methods over `EloquentRepository`, two mapping methods, and nothing else.

Enforced by the package's `tests/Architecture/RepositoriesTest.php`. The spec in `repositoriesSpec()` is the machine-checked copy of this file: change the two together. The behaviour of the base class is proven in `tests/Feature/Infra/Persistence/Eloquent/Repositories/EloquentRepositoryTest.php`.

## Kit files
These live at fixed paths. Copy them into a new project as they are, never edit them per project. *Check `kit-files`.*
- `app/Infra/Persistence/Eloquent/Repositories/EloquentRepository.php` and `WriteMode.php`
- `app/Infra/Logging/EntityPayloads/EntityLogPayload.php`
- `app/Domain/Shared/Exceptions/RepositoryException.php` and `EntityNotFoundException.php`
- `app/Domain/Shared/ClonableAggregate.php`
- `tests/Feature/Infra/Persistence/Eloquent/RepositoryContract.php`

## Scaffold, never hand-write
Run `php artisan make:eloquent-repository {Aggregate} --domain={Context}/{Aggregate}`. It writes the repository, its exceptions, its log payload and a test file that already calls `repositoryContract()`. Then fill in the mapping and the contract hooks.

## The shape of a repository
```php
/**
 * @extends EloquentRepository<Customer, CustomerEntity>
 */
class EloquentCustomerRepository extends EloquentRepository implements CustomerRepository
{
    #[Override] public function save(CustomerEntity $entity): void { $this->saveEntity($entity); }
    #[Override] public function update(CustomerEntity $entity): void { $this->updateEntity($entity); }
    #[Override] public function findById(string $id): ?CustomerEntity { return $this->findEntity($id); }
    #[Override] public function getById(string $id): CustomerEntity { return $this->getEntity($id); }

    #[Override] protected function newQuery(): Builder { return Customer::query(); }
    #[Override] protected function exceptionClass(): string { return CustomerRepositoryException::class; }
    #[Override] protected function notFoundClass(): string { return CustomerNotFoundException::class; }
    #[Override] protected function logPayloadClass(): string { return CustomerLogPayload::class; }

    #[Override] protected function toModel(DomainEntity $entity): Model { /* entity → new Customer */ }
    #[Override] protected function toEntity(Model $model): DomainEntity { /* row → CustomerEntity::reconstitute(...) */ }
}
```

**Do**
- Name it `Eloquent{Name}` for the domain interface `{Name}`, and bind the pair in a service provider. *Checks `implementation`, `binding`.*
- Declare the model/entity pair with `@extends EloquentRepository<Model, Entity>`. PHPStan reads every helper's return type from it. *Check `shape`.*
- Order the sections like this: the public methods (in interface order), the four declarations, a `write()` override if any, `toModel()`, `toEntity()`, then private mapping helpers.
- Give every public method exactly one counterpart in the interface, and mark every method that implements the interface or overrides the base with `#[Override]`. *Check `public-surface`.*
- Make each public method a single call to a base helper. Custom finders start from `$this->newQuery()` and go through `firstEntity()`, `entities()` or `guardRead()`.
- Have finders return entities or scalars the domain needs (`exists`, a list of ids). List pages read through Query classes, not repositories.

**Don't** use an inline `@var`, `$model->save()`, `Model::create()`, `findOrNew()`, or `toModel()` with a second parameter. *Check `shape`.*

**Why:** when every repository is the same twelve lines, a wrong one stands out. All the behaviour that matters (transactions, error wrapping, batching, soft deletes, the MySQL path) lives once in the base class and is tested there.

## Which methods an interface declares
| Method | Required | Base helper | SQL |
|---|---|---|---|
| `save(X)` | yes | `saveEntity` | `INSERT … ON CONFLICT (id) DO UPDATE` |
| `update(X)` | yes | `updateEntity` | `UPDATE … WHERE id = ?` (0 rows → NotFound) |
| `findById` / `getById` | when read by id | `findEntity` / `getEntity` | `SELECT … WHERE id = ?` |
| `saveMany` / `updateMany` | when a use case writes a batch | `saveEntities` / `updateEntities` | as above, batched |
| `delete(X)` | when a use case deletes | `deleteEntity` | `DELETE` (or soft delete) |
| `restore(id)` / `purge(X)` | soft-deletable, when needed | `restoreEntity` / `purgeEntity` | |
| `clone(X, newId)` | clonable, when needed | `cloneEntity` | `INSERT` only |

Read by id with the standard names `findById` (null when missing) and `getById` (NotFound when missing). An aggregate that is never read by its own id (for example a one-to-one setting read by its owner) declares only the finder it needs.

## save() or update()
| The entity came from | Call |
|---|---|
| `{X}Entity::create(...)`, so it is new | `save()` / `saveMany()` |
| `getById()` / `findBy…()`, then changed through its own methods | `update()` / `updateMany()` |

*Review only*, but a handler test catches the slip: `update()` on a new entity throws NotFound.

## How a write runs
```
save()/update()/clone() → base → transaction { write($entities, WriteMode) } → catch QueryException → {X}RepositoryException::saveFailed + log payload
```
- One statement writes the root rows, with no read first. Two requests creating the same id cannot race.
- `created_at` is never rewritten. `updated_at` moves on every write.
- An empty batch runs no query. A batch is cut into statements of at most `min(1000, 65535 ÷ columns)` rows, all inside one transaction, so it all lands or none of it does.
- Domain exceptions (for example a concurrency failure) pass through unwrapped. Only database failures are wrapped.
- The repository never reads the entity back. The entity passed in is the truth.
- No Eloquent model events fire. Audit through the application's `AuditLog` port (stack.md).

`write()` has three shapes. Use one of them, never a fourth:
1. **One table**: the default, so no override.
2. **Owned child tables**: override `write()`. Call `writeRoots($entities, $mode)`, then `syncChildren(Child::class, 'parent_id', $parentIds, $childModels, $mode)` once per child table. `syncChildren` deletes the parents' children that are gone, then upserts the rest. Deleting comes first because a replacement child often reuses the old one's unique key. Also override `newQuery()` to eager load every child table (`Member::query()->with('paymentAccounts')`).
3. **Optimistic locking**: the aggregate carries a `version`. Override `write()`: version 0 means INSERT. A higher version means `UPDATE … WHERE id = ? AND version = ?`, and 0 rows means `{X}ConcurrencyException`. Never rewrite `created_at`. `update()` on version 0 is NotFound.

A repository opens a transaction for one reason only: to keep a multi-table aggregate atomic. The business transaction belongs to the use-case handler (layers.md).

## MySQL and MariaDB
On these drivers Laravel's `upsert()` becomes `ON DUPLICATE KEY UPDATE`, which matches any unique index. A clash on a business key would then silently rewrite another aggregate's row. So on mysql/mariadb the base inserts, and falls back to `UPDATE … WHERE id` only when the primary key collides. A clash on any other unique key fails as `saveFailed`, as it must. Never call `upsert()` on an aggregate table yourself.

`upsertEntitiesBy($entities, uniqueBy: [...], update: [...])` upserts on a business key on purpose. It exists only to re-install reference data a definition owns, never for aggregates users edit.

## delete(), soft delete, restore(), purge()
- `delete()` deletes by id, and 0 rows means NotFound. Owned child rows go with it through the foreign key's `cascadeOnDelete()`. The repository never deletes children itself. A database refusal (a restricting foreign key) becomes `deleteFailed`.
- **Soft delete is opt-in per aggregate.** Add `SoftDeletes` to the model and `$table->softDeletes()` to its migration, and the base detects it:
  - `delete()` stamps `deleted_at`.
  - Every read skips trashed rows.
  - `update()` on a trashed row is NotFound.
  - `save()` never clears `deleted_at`.
  - Child rows stay but are unreachable.
  - `restore(id)` and `purge(X)` are available.
- A soft-deleted row still holds its unique values. Make business unique indexes partial (`WHERE deleted_at IS NULL` on Postgres, a generated column on MySQL/MariaDB). Otherwise the domain sees a value as free but the insert collides.
- To deactivate something that must stay visible (a `Closed` account), use a status on the entity and `update()`, not a soft delete.

## clone()
- The aggregate implements `ClonableAggregate::cloneAs(string $newId, IdGenerator $ids): static` and decides everything: the values it keeps, fresh ids for every child, and what it resets.
- The handler mints the new root id. The base inserts the copy (INSERT only, so an existing id is `saveFailed`) and returns it. The source is not touched.
- Never implement it for an aggregate that must not be duplicated (a wallet balance) or whose copy needs new business-unique values (a prefix, a username). Build those with `create()` and `save()`. `clone()` on a non-clonable aggregate throws `notClonable`.

## Errors
| Failure | Thrown |
|---|---|
| Write refused by the database | `{X}RepositoryException::saveFailed`, code 100, carrying the log payload |
| Row cannot be rebuilt into an entity | `reconstituteFailed` (101), carrying the raw attributes. Another repository's exception (a nested load) passes through as is. |
| Delete refused | `deleteFailed` (102) |
| Read refused (bad query, lost connection) | `queryFailed` (103) |
| Clone of a non-clonable aggregate | `notClonable` (104) |
| Row missing for getById / update / delete / restore / purge | `{X}NotFoundException` |

No framework exception ever leaves a repository. Log payloads carry no personal data. Each `{X}LogPayload` decides what is safe.

## Tests
Each repository has `tests/Feature/Infra/Persistence/Eloquent/Repositories/Eloquent{Name}Test.php`. *Check `tests`.* It must:
- call `repositoryContract()` with hooks for its aggregate: `make`, `change`, `unwritable`, `corrupt`, and optionally `read`/`same`. That registers the cases every repository needs: read back, write over, failed write, broken row, update, update of a missing row, and getById of an unknown id.
- add, by literal title, the cases its interface opts into:
  - `delete`: `deletes the row and everything the aggregate owns` (soft: `soft deletes the row so no read finds it any more`) and `throws not found from delete when the row no longer exists`
  - `restore`: `restores a soft deleted row`
  - `purge`: `purges a soft deleted row for good`
  - `clone`: `clones into a new aggregate with fresh ids and leaves the source untouched` and `refuses to clone onto an id that already exists`
- keep everything else specific to the aggregate (finders, constraints, child tables) as ordinary cases beside the contract.

## Stepping outside this file
Use `rule-overrides.json` with `"rule": "repositories"`, exactly as stack.md describes. Only the user may add an entry.
