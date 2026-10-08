<?php

namespace App\Application\Audit\UseCases\ListAuditEntries;

use App\Application\Audit\AuditEntryListSort;
use App\Application\Concerns\DateRange;
use App\Application\Concerns\PageSize;

/**
 * Lists the audit log, newest first unless asked otherwise.
 *
 * This is the one place that decides what a request value means. Nothing here rejects: an
 * unknown sort, direction or page size falls back, so a hand edited URL still lists. The
 * subject type is not an enum — the log stores whatever roots the project records — so any
 * non-empty value filters, and one that matches nothing lists nothing.
 */
final class ListAuditEntriesHandler
{
    public function __construct(
        protected ListAuditEntriesQuery $query,
    ) {}

    public function __invoke(ListAuditEntriesCommand $command): ListAuditEntriesResult
    {
        $subjectType = trim($command->subjectType);

        $criteria = new ListAuditEntriesCriteria(
            sort: AuditEntryListSort::fromInput($command->sort),
            direction: $command->direction === 'asc' ? 'asc' : 'desc',
            search: trim($command->search),
            subjectType: $subjectType === '' ? null : $subjectType,
            createdAt: DateRange::fromInput($command->createdFrom, $command->createdTo),
            perPage: PageSize::fromInput($command->perPage)->value,
        );

        return new ListAuditEntriesResult(
            auditEntries: $this->query->paginate($criteria),
            sort: $criteria->toSort(),
            filters: $criteria->toFilters(),
        );
    }
}
