<?php

namespace App\Application\Catalog\UseCases\ListProducts;

use App\Application\Catalog\ProductListSort;
use App\Application\Concerns\DateRange;
use App\Application\Concerns\SortsAList;

/**
 * The list request as the handler settled it: no raw string survives in here, so the
 * read port has nothing left to decide, and the result echoes these — not the request —
 * back to the UI. Every field is required on purpose; a default would be a decision.
 */
final class ListProductsCriteria
{
    // Keep $sort typed as its own enum — the trait reads the concrete type, which a shared
    // base class could not give it.
    use SortsAList;

    /**
     * @param  'asc'|'desc'  $direction
     * @param  string  $search  already trimmed; an empty string means "do not search"
     * @param  DateRange  $createdAt  the days the row was created between; empty means "any day"
     */
    public function __construct(
        public readonly ProductListSort $sort,
        public readonly string $direction,
        public readonly string $search,
        // one nullable enum per filter, null meaning "do not filter", e.g.
        // public readonly ?SampleStatus $status,
        public readonly DateRange $createdAt,
        public readonly int $perPage,
    ) {}

    /**
     * The keys here are what the frontend reads back as the filters in force.
     *
     * `created_from`/`created_to` are the default date range every list toolbar offers.
     *
     * @return array{search: string, created_from: string|null, created_to: string|null}
     */
    public function toFilters(): array
    {
        return [
            'search' => $this->search,
            // 'status' => $this->status?->value,
            ...$this->createdAt->toFilters(),
        ];
    }
}
