<?php

namespace App\Application\Catalog\UseCases\ListProducts;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The read port of this use case: the handler decides, this fetches.
 *
 * The concrete paginator, not the contract: the contract's TValue is not covariant, so
 * what the adapter builds would never satisfy it.
 */
interface ListProductsQuery
{
    /**
     * The criteria arrive already narrowed by the use case, so nothing here decides
     * what is valid — it only turns settled values into SQL.
     *
     * @return LengthAwarePaginator<int, ProductListRow>
     */
    public function paginate(ListProductsCriteria $criteria): LengthAwarePaginator;
}
