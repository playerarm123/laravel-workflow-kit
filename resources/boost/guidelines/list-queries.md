# List queries

A list page reads the database through one path:

```
Controller → List{Name}Command → List{Name}Handler → List{Name}Criteria → List{Name}Query (port) → EloquentList{Name}Query (adapter) → {Name}ListRow → List{Name}Result
```

Lists never go through a repository, and repositories never return list rows (repositories.md).

Enforced by the package's `tests/Architecture/ListQueriesTest.php`. The spec in `listQueriesSpec()` is the machine-checked copy of this file: change the two together. The base adapter's behaviour is proven in `tests/Feature/Infra/Persistence/Eloquent/Queries/EloquentListQueryTest.php`, on the app's database and on MySQL/MariaDB.

## Kit files
These live at fixed paths. `php artisan kit:install` writes them from the kit, and *check `kit-files`* fails when one is missing or differs from the kit's copy, so a change to one is a change to the kit.
- `app/Infra/Persistence/Eloquent/Queries/EloquentListQuery.php`
- `app/Application/Concerns/ListQueryException.php`, `PageSize.php`, `DateRange.php`, `SortsAList.php`
- `tests/Feature/Infra/Persistence/Eloquent/ListQueryContract.php`

## Scaffold, never hand-write
Run `php artisan make:use-case List{Name} --domain={Context} --command --result --query`. It writes:
- the Command, Handler, Result, Criteria, Row and port, and the `{Name}ListSort` enum beside the context (kept when it already exists)
- the Eloquent adapter
- a test file that already calls `listQueryContract()`

Then fill them in and bind the port to the adapter in a service provider. *Checks `implementation`, `binding`.*

## Application side
**Command.** Every field is a raw `string` defaulting to `''`. The controller passes `$request->string('x')->toString()` untouched, with no FormRequest and no validation.

**Handler.** It settles every value with a fallback, and nothing here throws: a hand-edited URL must get a page, not a 422.

| Input | Settled with |
|---|---|
| sort | `{Name}ListSort::fromInput()`, whose default is used for anything unknown |
| direction | `'asc'` or `'desc'`, defaulting to the list's natural order |
| search | `trim()` |
| enum filters | `Enum::tryFrom()`, where null means no filter |
| created range | `DateRange::fromInput($from, $to)`, which never throws, nulls a malformed date and swaps a reversed pair |
| page size | `PageSize::fromInput()` (10/25/50, fallback 10) |

The scope (whose rows these are) is resolved by the handler from the actor, never taken from the request.

**Criteria.** One object goes into the port.
- It carries the settled values with no defaults, and `use SortsAList` for `toSort()`.
- `toFilters()` echoes every filter back.
- `toFilters()` always includes the key `search` (`''` when the page has no search box, because the shared toolbar reads it) and `created_from`/`created_to` via `...$this->createdAt->toFilters()`.

Never spread the Criteria back into separate port arguments.

**Result.** `{rows}` (the paginator), `sort` and `filters`, straight from the Criteria.

**Row.** Named `{Singular}ListRow` (`ListLotteryTypes` reads `LotteryTypeListRow`). `final`, `implements Arrayable`, with readonly constructor properties. *Check `rows`.* `toArray()` names every key, snake_case, one by one. It is the wire contract the frontend's TypeScript type mirrors, so it is never generated.

**Don't**
- Generalise across lists: no shared Criteria base class, no reflection-built row. Per-list types are the contract.
- Name a domain filter `direction`. Every list already uses `sort` + `direction` for ordering.

## Adapter side
```php
class EloquentListCustomersQuery extends EloquentListQuery implements ListCustomersQuery
{
    /** @var list<literal-string> */
    private const SEARCHABLE_COLUMNS = ['customers.name'];

    #[Override]
    public function paginate(ListCustomersCriteria $criteria): LengthAwarePaginator
    {
        $query = Customer::query()
            ->with([...])                                      // every relation toRow() reads
            ->where('customers.agent_id', $criteria->agentId)  // the scope, first
            ->tap(fn (Builder $query) => $this->applySearch($query, $criteria->search, self::SEARCHABLE_COLUMNS))
            ->when(/* each filter */)
            ->orderBy($this->sortColumn($criteria->sort), $criteria->direction);

        return $this->paginateRows($query, $criteria->perPage, fn (Customer $customer): CustomerListRow => $this->toRow($customer));
    }

    private function sortColumn(CustomerListSort $sort): string { return match ($sort) { /* every case */ }; }

    private function toRow(Customer $customer): CustomerListRow { /* ... */ }
}
```

**Do**
- Apply the scope from the Criteria as the first condition.
- Search only through `applySearch()`. It skips an empty term, wraps itself in its own `where()` group so its ORs can never widen the scope, escapes `%` and `_`, and compares with `ILIKE` on Postgres and `LIKE` on MySQL/MariaDB. Searchable columns are a `const` of qualified literals.
- Filter the created-at range with `applyCreatedBetween($query, $range, '{table}.created_at')`. It compares against the day's edges in the app timezone and keeps the column indexable.
- Sort through a private `sortColumn()` (or `applySort()` when a key needs a subquery) that `match`es every case of the sort enum. A new case without a column is then a PHPStan error, not a 500.
- Finish with `paginateRows($query, $perPage, $toRow, $preparePage = null)`:
  - It appends the primary key as the last sort, in the page's direction, so rows that tie never skip or repeat across pages.
  - It turns a refused query into `ListQueryException` naming the adapter.
  - It maps each model to its row.
  - `$preparePage` runs once on the page's models for what `with()` cannot load (a morph's own relations) or a lookup every row shares.
- Eager load every relation `toRow()` reads. Lazy loading throws outside production (`Model::preventLazyLoading()`), and the contract counts queries.
- Expose only the port's `paginate()`, marked `#[Override]`. *Check `public-surface`.*

**Don't** call `->paginate()` directly, write `LIKE`/`ILIKE` by hand, or use an inline `@var`. *Check `shape`.*

**Why:** every list then gets the security-relevant parts (scope first, a search that cannot leak) and the correctness parts (stable paging, no N+1, a named failure) from one tested base, and a reviewer reads only what is specific to the page.

## Tests
Each adapter has `tests/Feature/Infra/Persistence/Eloquent/Queries/EloquentList{Name}Test.php`, which calls `listQueryContract()`. *Check `tests`.* The hooks are:
- `criteria(['perPage' => …, 'search' => …])`
- `seed($count)`: rows inside the scope, tying on the default sort
- `break()`: make the read fail, for example with `breakListQueryColumn('table', 'created_at')`
- optionally `seedMatching($text)` and `seedOutOfScope($text)` for lists with a search or a scope

That registers these cases:
- the page size is honoured
- tied rows page through without loss or repeats, and the page query ends on the primary key
- a page reads in the same number of queries whatever its size
- a refused query is a `ListQueryException`
- `%` and `_` are searched literally
- a search never reaches past the scope

Keep each filter's and each sort key's own cases beside the contract.

## Stepping outside this file
Use `rule-overrides.json` with `"rule": "list-queries"`, exactly as stack.md describes. Only the user may add an entry.
