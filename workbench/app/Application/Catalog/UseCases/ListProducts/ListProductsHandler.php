<?php

namespace App\Application\Catalog\UseCases\ListProducts;

final class ListProductsHandler
{
    public function __construct(
        protected ListProductsQuery $query,
    ) {}

    public function __invoke(ListProductsCommand $command): ListProductsResult
    {
        // implement the use case here
    }
}
