<?php

namespace App\Application\Catalog\UseCases\ListProducts;

use App\Application\Catalog\ProductListSort;
use App\Application\Concerns\DateRange;
use App\Application\Concerns\PageSize;

final class ListProductsHandler
{
    public function __construct(
        protected ListProductsQuery $query,
    ) {}

    public function __invoke(ListProductsCommand $command): ListProductsResult
    {
        $criteria = new ListProductsCriteria(
            sort: ProductListSort::fromInput($command->sort),
            direction: $command->direction === 'asc' ? 'asc' : 'desc',
            search: trim($command->search),
            // one settled value per filter, null meaning "do not filter", e.g.
            // status: SampleStatus::tryFrom($command->status),
            createdAt: DateRange::fromInput($command->createdFrom, $command->createdTo),
            perPage: PageSize::fromInput($command->perPage)->value,
        );

        return new ListProductsResult(
            products: $this->query->paginate($criteria),
            sort: $criteria->toSort(),
            filters: $criteria->toFilters(),
        );
    }
}
