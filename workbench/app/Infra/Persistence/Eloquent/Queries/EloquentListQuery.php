<?php

namespace App\Infra\Persistence\Eloquent\Queries;

use App\Application\Concerns\DateRange;
use App\Application\Concerns\ListQueryException;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The one way a list page reads the database — see list-queries.md. An adapter
 * builds its scoped, filtered, sorted query and hands it to paginateRows(); everything every
 * list must get right (the search that cannot widen the scope, the tie-breaker that keeps
 * paging stable, the wrapped failure, the row paginator) lives here once.
 */
abstract class EloquentListQuery
{
    /**
     * Search across the given columns, always inside its own where() group: a search is a run
     * of ORs, and left at the top level of the query one OR would reach past the scope the
     * adapter set (another agent's customers). An empty search adds nothing.
     *
     * `%` and `_` are wildcards to LIKE, so a user typing them must not silently widen their
     * own search — they are escaped and the escape character is declared. Postgres compares
     * with ILIKE; MySQL/MariaDB with LIKE, whose `_ci` collations already ignore case.
     *
     * The columns are interpolated into raw SQL, so they must be literals the adapter wrote
     * down — a const — never anything that came in with the request.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<literal-string>  $columns  qualified columns the search runs across
     */
    protected function applySearch(Builder $query, string $search, array $columns): void
    {
        if ($search === '' || $columns === []) {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        /**
         * Both declare a backslash as the escape character, spelled per dialect: Postgres reads
         * '\' as one backslash, MySQL/MariaDB treat a backslash inside a literal as an escape
         * and need '\\'.
         */
        $comparison = $this->isMySqlFamily($query) ? "LIKE ? ESCAPE '\\\\'" : "ILIKE ? ESCAPE '\\'";

        $query->where(function (Builder $matches) use ($columns, $term, $comparison): void {
            foreach ($columns as $column) {
                $matches->orWhereRaw("{$column} {$comparison}", [$term]);
            }
        });
    }

    /**
     * Compares the timestamp against the day's edges rather than casting it to a date:
     * the column stays indexable, and the edges fall in the app timezone — the day the
     * user picked — instead of the UTC day the row was stored under.
     *
     * @param  Builder<covariant Model>  $query
     * @param  literal-string  $column  qualified timestamp column the range applies to
     */
    protected function applyCreatedBetween(Builder $query, DateRange $range, string $column): void
    {
        if ($range->from !== null) {
            $query->where($column, '>=', $range->from->startOfDay());
        }

        if ($range->to !== null) {
            $query->where($column, '<=', $range->to->endOfDay());
        }
    }

    /**
     * Page the query and turn each model into its row. The primary key is appended as the
     * last sort, so rows that tie on the chosen column keep one order and paging neither
     * skips nor repeats them. A refused query becomes a ListQueryException naming this list.
     *
     * @template TModel of Model
     * @template TRow of Arrayable
     *
     * $preparePage runs once on the page's models before they become rows — for what a single
     * with() cannot load (a polymorphic relation's own relations) or a lookup shared by every
     * row. It runs under the same guard, so its failure is wrapped too.
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): TRow  $toRow
     * @param  (Closure(list<TModel>): void)|null  $preparePage
     * @return LengthAwarePaginator<int, TRow>
     */
    protected function paginateRows(Builder $query, int $perPage, Closure $toRow, ?Closure $preparePage = null): LengthAwarePaginator
    {
        $query->orderBy($query->getModel()->getQualifiedKeyName(), $this->leadingDirection($query));

        try {
            $models = $query->paginate($perPage);

            if ($preparePage !== null) {
                $preparePage(array_values($models->items()));
            }
        } catch (QueryException $e) {
            throw ListQueryException::failed(static::class, $e);
        }

        return $this->makePaginator($models, $toRow);
    }

    /**
     * The rows get a paginator of their own rather than being mapped in place:
     * through(), setCollection() and Collection::map() all hand back a static-bound
     * type, and TValue is invariant in larastan's paginator stub, so none of them can
     * satisfy the declared return type. Rebuilding carries the options across, so the
     * paging links still point at this request.
     *
     * @template TModel of Model
     * @template TRow of Arrayable
     *
     * @param  LengthAwarePaginator<int, TModel>  $models
     * @param  Closure(TModel): TRow  $toRow
     * @return LengthAwarePaginator<int, TRow>
     */
    private function makePaginator(LengthAwarePaginator $models, Closure $toRow): LengthAwarePaginator
    {
        $rows = new Collection(array_map($toRow, $models->items()));

        $paginator = new LengthAwarePaginator(
            $rows,
            $models->total(),
            $models->perPage(),
            $models->currentPage(),
            $models->getOptions(),
        );

        return $paginator->withQueryString();
    }

    /**
     * The tie-breaker runs the way the page is sorted, so with time-ordered keys (uuid7) a
     * newest-first page stays newest-first among rows that share a timestamp.
     *
     * @param  Builder<covariant Model>  $query
     * @return 'asc'|'desc'
     */
    private function leadingDirection(Builder $query): string
    {
        $first = $query->getQuery()->orders[0] ?? null;

        return is_array($first) && strtolower((string) ($first['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function isMySqlFamily(Builder $query): bool
    {
        return in_array($query->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
