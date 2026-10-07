<?php

require_once __DIR__.'/../ListQueryContract.php';

use App\Application\Catalog\UseCases\ListProducts\ListProductsCriteria;
use App\Application\Catalog\UseCases\ListProducts\ListProductsQuery;

/**
 * Fill in the hooks, then add each filter's and each sort key's own cases below the contract.
 * See list-queries.md, "Tests".
 */
listQueryContract(
    query: ListProductsQuery::class,
    criteria: fn (array $overrides): ListProductsCriteria => throw new LogicException('Build ListProductsCriteria from perPage/search overrides.'),
    seed: fn (int $count) => throw new LogicException('Create $count rows inside the scope, tying on the default sort.'),
    break: fn () => throw new LogicException('Make the read fail, e.g. breakListQueryColumn().'),
);
