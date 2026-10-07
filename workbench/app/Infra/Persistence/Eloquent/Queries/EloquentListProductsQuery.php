<?php

namespace App\Infra\Persistence\Eloquent\Queries;

use App\Application\Catalog\UseCases\ListProducts\ListProductsCriteria;
use App\Application\Catalog\UseCases\ListProducts\ListProductsQuery;
use App\Application\Catalog\UseCases\ListProducts\ProductListRow;
use Illuminate\Pagination\LengthAwarePaginator;
use Override;
use RuntimeException;

/**
 * See list-queries.md for the shape this adapter must take.
 */
class EloquentListProductsQuery extends EloquentListQuery implements ListProductsQuery
{
    /**
     * Qualified columns the free-text search runs across — literals only.
     *
     * @var list<literal-string>
     */
    private const SEARCHABLE_COLUMNS = [
        // 'table.column',
    ];

    #[Override]
    public function paginate(ListProductsCriteria $criteria): LengthAwarePaginator
    {
        // Build the scoped, filtered, sorted query, then finish with paginateRows():
        //
        //     $query = Model::query()
        //         ->with([/* every relation toRow() reads */])
        //         ->where('table.scope_column', $criteria->scopeValue)
        //         ->tap(fn (Builder $query) => $this->applySearch($query, $criteria->search, self::SEARCHABLE_COLUMNS))
        //         ->when(! $criteria->createdAt->isEmpty(), fn (Builder $query) => $this->applyCreatedBetween(
        //             $query,
        //             $criteria->createdAt,
        //             'table.created_at',
        //         ))
        //         ->orderBy($this->sortColumn($criteria->sort), $criteria->direction);
        //
        //     return $this->paginateRows($query, $criteria->perPage, fn (Model $model): ProductListRow => $this->toRow($model));
        //
        // sortColumn() matches every case of ProductListSort, so a new case without a column is
        // a PHPStan error rather than a 500:
        //
        //     private function sortColumn(ProductListSort $sort): string
        //     {
        //         return match ($sort) {
        //             ProductListSort::CreatedAt => 'table.created_at',
        //         };
        //     }

        throw new RuntimeException('EloquentListProductsQuery::paginate() is not implemented yet.');
    }
}
