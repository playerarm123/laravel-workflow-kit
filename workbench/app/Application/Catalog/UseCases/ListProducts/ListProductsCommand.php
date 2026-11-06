<?php

namespace App\Application\Catalog\UseCases\ListProducts;

use Spatie\LaravelData\Data;

/**
 * The list's query string as it arrived: every field a raw string defaulting to '', which
 * the Index request's toCommand() fills untouched. The handler settles each one into the
 * Criteria, so nothing here is validated (list-queries.md).
 */
final class ListProductsCommand extends Data
{
    public function __construct(
        public readonly string $sort = '',
        public readonly string $direction = '',
        public readonly string $search = '',
        // one raw string per filter, e.g.
        // public readonly string $status = '',
        public readonly string $createdFrom = '',
        public readonly string $createdTo = '',
        public readonly string $perPage = '',
    ) {}
}
