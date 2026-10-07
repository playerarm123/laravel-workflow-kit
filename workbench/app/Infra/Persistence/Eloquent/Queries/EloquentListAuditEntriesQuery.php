<?php

namespace App\Infra\Persistence\Eloquent\Queries;

use App\Application\Audit\AuditEntryListSort;
use App\Application\Audit\UseCases\ListAuditEntries\AuditEntryListRow;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesCriteria;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesQuery;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Override;

/**
 * See list-queries.md for the shape this adapter must take.
 *
 * `actor_id` is a string with no foreign key (audit-log.md), so the actors' names are looked
 * up once per page in `$preparePage`, never joined: a join would compare a string column with
 * the user table's own key type.
 */
class EloquentListAuditEntriesQuery extends EloquentListQuery implements ListAuditEntriesQuery
{
    /**
     * Qualified columns the free-text search runs across — literals only.
     *
     * @var list<literal-string>
     */
    private const SEARCHABLE_COLUMNS = [
        'audit_entries.subject_id',
    ];

    /**
     * @var array<string, string> user name keyed by id, for the page being read
     */
    private array $actorNames = [];

    #[Override]
    public function paginate(ListAuditEntriesCriteria $criteria): LengthAwarePaginator
    {
        $query = AuditEntry::query()
            ->tap(fn (Builder $query) => $this->applySearch($query, $criteria->search, self::SEARCHABLE_COLUMNS))
            ->when($criteria->subjectType !== null, fn (Builder $query) => $query->where('audit_entries.subject_type', $criteria->subjectType))
            ->when(! $criteria->createdAt->isEmpty(), fn (Builder $query) => $this->applyCreatedBetween(
                $query,
                $criteria->createdAt,
                'audit_entries.occurred_at',
            ))
            ->orderBy($this->sortColumn($criteria->sort), $criteria->direction);

        return $this->paginateRows(
            $query,
            $criteria->perPage,
            fn (AuditEntry $entry): AuditEntryListRow => $this->toRow($entry),
            fn (array $entries) => $this->loadActorNames($entries),
        );
    }

    private function sortColumn(AuditEntryListSort $sort): string
    {
        return match ($sort) {
            AuditEntryListSort::OccurredAt => 'audit_entries.occurred_at',
        };
    }

    /**
     * @param  list<AuditEntry>  $entries
     */
    private function loadActorNames(array $entries): void
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (AuditEntry $entry) => $entry->actor_id, $entries))));

        $this->actorNames = $ids === []
            ? []
            : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function toRow(AuditEntry $entry): AuditEntryListRow
    {
        return new AuditEntryListRow(
            id: $entry->id,
            occurredAt: $entry->occurred_at->toISOString(),
            actorId: $entry->actor_id,
            actorName: $entry->actor_id === null ? null : ($this->actorNames[$entry->actor_id] ?? null),
            event: $entry->event,
            subjectType: $entry->subject_type,
            subjectId: $entry->subject_id,
            data: $entry->data,
        );
    }
}
