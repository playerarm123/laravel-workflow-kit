<?php

namespace App\Application\Catalog\UseCases\ListProducts;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;

/**
 * The rows, plus the sort and filters the handler actually settled on — the caller echoes
 * these back rather than the raw request, so the UI always shows what was really applied.
 */
final class ListProductsResult extends Data
{
    /**
     * @param  LengthAwarePaginator<int, ProductListRow>  $products
     * @param  array{column: string, direction: 'asc'|'desc'}  $sort  the Criteria's toSort()
     * @param  array<string, string|null>  $filters  the Criteria's toFilters()
     */
    public function __construct(
        public readonly LengthAwarePaginator $products,
        public readonly array $sort,
        public readonly array $filters,
    ) {}
}
