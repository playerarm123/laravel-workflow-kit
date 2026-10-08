<?php

namespace App\Application\Audit\UseCases\ListAuditEntries;

use App\Application\Audit\AuditEntryListSort;
use App\Application\Concerns\DateRange;
use App\Application\Concerns\SortsAList;

/**
 * The list request as the handler settled it: no raw string survives in here, so the
 * read port has nothing left to decide, and the result echoes these — not the request —
 * back to the UI. Every field is required on purpose; a default would be a decision.
 *
 * The audit log has no scope: whoever the policy lets in reads every entry.
 */
final class ListAuditEntriesCriteria
{
    // Keep $sort typed as its own enum — the trait reads the concrete type, which a shared
    // base class could not give it.
    use SortsAList;

    /**
     * @param  'asc'|'desc'  $direction
     * @param  string  $search  already trimmed; an empty string means "do not search"
     * @param  string|null  $subjectType  a stored subject type such as `invoice`; null means "any"
     * @param  DateRange  $createdAt  the days the entry was recorded between; empty means "any day"
     */
    public function __construct(
        public readonly AuditEntryListSort $sort,
        public readonly string $direction,
        public readonly string $search,
        public readonly ?string $subjectType,
        public readonly DateRange $createdAt,
        public readonly int $perPage,
    ) {}

    /**
     * The keys here are what the frontend reads back as the filters in force.
     *
     * The day range keeps the toolbar's `created_from`/`created_to` keys, which the adapter
     * applies to the time the entry was recorded.
     *
     * @return array{search: string, subject_type: string|null, created_from: string|null, created_to: string|null}
     */
    public function toFilters(): array
    {
        return [
            'search' => $this->search,
            'subject_type' => $this->subjectType,
            ...$this->createdAt->toFilters(),
        ];
    }
}
