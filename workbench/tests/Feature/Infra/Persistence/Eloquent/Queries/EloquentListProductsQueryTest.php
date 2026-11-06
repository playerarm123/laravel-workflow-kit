<?php

require_once __DIR__.'/../ListQueryContract.php';

use App\Application\Catalog\ProductListSort;
use App\Application\Catalog\UseCases\ListProducts\ListProductsCriteria;
use App\Application\Catalog\UseCases\ListProducts\ListProductsQuery;
use App\Application\Concerns\DateRange;
use App\Infra\Persistence\Eloquent\Queries\EloquentListProductsQuery;
use App\Models\Product;

/**
 * Pass seedMatching once SEARCHABLE_COLUMNS names a column, and seedOutOfScope once the list
 * has a scope. Add each filter's and each sort key's own cases below the contract. See
 * list-queries.md, "Tests".
 */
listQueryContract(
    query: ListProductsQuery::class,
    criteria: fn (array $overrides): ListProductsCriteria => new ListProductsCriteria(
        sort: ProductListSort::CreatedAt,
        direction: 'desc',
        search: $overrides['search'] ?? '',
        createdAt: DateRange::fromInput('', ''),
        perPage: $overrides['perPage'] ?? 10,
    ),
    seed: fn (int $count) => Product::factory()->count($count)->create(),
    break: fn () => breakListQueryColumn('products', 'created_at'),
    // seedMatching: fn (string $text) => Product::factory()->create(['name' => $text]),
);

describe('EloquentListProductsQuery', function () {
    it('is what the read port resolves to', function () {
        expect(app(ListProductsQuery::class))->toBeInstanceOf(EloquentListProductsQuery::class);
    });
});
