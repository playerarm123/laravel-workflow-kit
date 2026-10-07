<?php

namespace App\Application\Audit\UseCases\ListAuditEntries;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;

/**
 * The rows, plus the sort and filters the handler actually settled on — the caller echoes
 * these back rather than the raw request, so the UI always shows what was really applied.
 */
final class ListAuditEntriesResult extends Data
{
    /**
     * @param  LengthAwarePaginator<int, AuditEntryListRow>  $auditEntries
     * @param  array{column: string, direction: 'asc'|'desc'}  $sort
     * @param  array{search: string, subject_type: string|null, created_from: string|null, created_to: string|null}  $filters
     */
    public function __construct(
        public readonly LengthAwarePaginator $auditEntries,
        public readonly array $sort,
        public readonly array $filters,
    ) {}
}
