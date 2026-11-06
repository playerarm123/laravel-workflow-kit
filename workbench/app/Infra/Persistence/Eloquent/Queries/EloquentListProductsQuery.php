<?php

namespace App\Infra\Persistence\Eloquent\Queries;

use App\Application\Catalog\ProductListSort;
use App\Application\Catalog\UseCases\ListProducts\ListProductsCriteria;
use App\Application\Catalog\UseCases\ListProducts\ListProductsQuery;
use App\Application\Catalog\UseCases\ListProducts\ProductListRow;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Override;

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
        // 'products.name',
    ];

    #[Override]
    public function paginate(ListProductsCriteria $criteria): LengthAwarePaginator
    {
        $query = Product::query()
            // ->with([...])  every relation toRow() reads
            // ->where('products.owner_id', $criteria->ownerId)  the scope, first, when the list has one
            ->tap(fn (Builder $query) => $this->applySearch($query, $criteria->search, self::SEARCHABLE_COLUMNS))
            // one ->when() per filter, e.g.
            // ->when($criteria->status !== null, fn (Builder $query) => $query->where('products.status', $criteria->status))
            ->when(! $criteria->createdAt->isEmpty(), fn (Builder $query) => $this->applyCreatedBetween(
                $query,
                $criteria->createdAt,
                'products.created_at',
            ))
            ->orderBy($this->sortColumn($criteria->sort), $criteria->direction);

        return $this->paginateRows($query, $criteria->perPage, fn (Product $product): ProductListRow => $this->toRow($product));
    }

    /**
     * Matches every case of ProductListSort, so a new case without a column is a PHPStan error
     * rather than a 500.
     */
    private function sortColumn(ProductListSort $sort): string
    {
        return match ($sort) {
            ProductListSort::CreatedAt => 'products.created_at',
        };
    }

    private function toRow(Product $product): ProductListRow
    {
        return new ProductListRow(
            id: $product->id,
        );
    }
}
