<?php

namespace App\Application\Audit\UseCases\ListAuditEntries;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One entry of the audit log, in the shape the table renders it.
 *
 * Deliberately a plain class rather than a spatie/laravel-data object: a Data item type
 * makes the library treat the paginator carrying it as a DataPaginator and reshape the
 * payload into {data, links, meta}, which is not the envelope the frontend reads.
 *
 * toArray() is what puts the keys on the wire, so it — not the property names — is the
 * contract the frontend row type mirrors (`resources/js/types/audit-entry.ts`).
 *
 * `actor_name` is null both when the system acted (`actor_id` null) and when the user has
 * since been removed; the page tells the two apart by `actor_id`.
 *
 * @implements Arrayable<string, string|array<string, scalar|null>|null>
 */
final class AuditEntryListRow implements Arrayable
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $occurredAt,
        public readonly ?string $actorId,
        public readonly ?string $actorName,
        public readonly string $event,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly array $data,
    ) {}

    /**
     * @return array{id: string, occurred_at: string, actor_id: string|null, actor_name: string|null, event: string, subject_type: string, subject_id: string, data: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurredAt,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'event' => $this->event,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'data' => $this->data,
        ];
    }
}
