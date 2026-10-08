<?php

use App\Application\Audit\UseCases\ListAuditEntries\AuditEntryListRow;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesCommand;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesHandler;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @return list<string>
 */
function listAuditEntriesSubjects(LengthAwarePaginator $auditEntries): array
{
    return array_map(fn (AuditEntryListRow $row): string => $row->subjectId, $auditEntries->items());
}

beforeEach(function () {
    $this->handler = app(ListAuditEntriesHandler::class);
});

describe('ListAuditEntriesHandler', function () {
    it('lists everything newest first with no filters', function () {
        AuditEntry::factory()->create(['subject_id' => 'older', 'occurred_at' => '2026-09-09 12:00:00']);
        AuditEntry::factory()->create(['subject_id' => 'newer', 'occurred_at' => '2026-09-10 12:00:00']);

        $result = ($this->handler)(new ListAuditEntriesCommand);

        expect(listAuditEntriesSubjects($result->auditEntries))->toBe(['newer', 'older'])
            ->and($result->sort)->toBe(['column' => 'occurred_at', 'direction' => 'desc'])
            ->and($result->filters)->toBe([
                'search' => '',
                'subject_type' => null,
                'created_from' => null,
                'created_to' => null,
            ]);
    });

    it('falls back silently rather than failing on values it does not recognise', function () {
        AuditEntry::factory()->create();

        $result = ($this->handler)(new ListAuditEntriesCommand(
            sort: 'password',
            direction: 'sideways',
            createdFrom: 'yesterday-ish',
            perPage: 'all',
        ));

        expect($result->auditEntries->total())->toBe(1)
            ->and($result->sort)->toBe(['column' => 'occurred_at', 'direction' => 'desc'])
            ->and($result->filters['created_from'])->toBeNull()
            ->and($result->auditEntries->perPage())->toBe(10);
    });

    it('lists oldest first when asc is asked for', function () {
        AuditEntry::factory()->create(['subject_id' => 'older', 'occurred_at' => '2026-09-09 12:00:00']);
        AuditEntry::factory()->create(['subject_id' => 'newer', 'occurred_at' => '2026-09-10 12:00:00']);

        $result = ($this->handler)(new ListAuditEntriesCommand(sort: 'occurred_at', direction: 'asc'));

        expect(listAuditEntriesSubjects($result->auditEntries))->toBe(['older', 'newer'])
            ->and($result->sort)->toBe(['column' => 'occurred_at', 'direction' => 'asc']);
    });

    it('searches the subject id case insensitively', function (string $term) {
        AuditEntry::factory()->create(['subject_id' => 'abc-123']);
        AuditEntry::factory()->create(['subject_id' => 'xyz-789']);

        $result = ($this->handler)(new ListAuditEntriesCommand(search: $term));

        expect(listAuditEntriesSubjects($result->auditEntries))->toBe(['abc-123']);
    })->with(['abc', 'ABC', 'c-12']);

    it('escapes like wildcards instead of letting a search widen itself', function (string $term) {
        AuditEntry::factory()->create(['subject_id' => 'abc-123']);

        expect(($this->handler)(new ListAuditEntriesCommand(search: $term))->auditEntries->total())->toBe(0);
    })->with(['%', '_']);

    it('trims the search before using it and before echoing it back', function () {
        AuditEntry::factory()->create(['subject_id' => 'abc-123']);

        $result = ($this->handler)(new ListAuditEntriesCommand(search: '  abc  '));

        expect($result->auditEntries->total())->toBe(1)
            ->and($result->filters['search'])->toBe('abc');
    });

    it('filters by subject type and echoes the type it applied', function () {
        AuditEntry::factory()->about('payment', 'a-payment', 'payment.received')->create();
        AuditEntry::factory()->about('invoice', 'a-invoice', 'invoice.issued')->create();

        $result = ($this->handler)(new ListAuditEntriesCommand(subjectType: ' invoice '));

        expect(listAuditEntriesSubjects($result->auditEntries))->toBe(['a-invoice'])
            ->and($result->filters['subject_type'])->toBe('invoice');
    });

    it('lists nothing for a subject type nothing was recorded about, rather than failing', function () {
        AuditEntry::factory()->create();

        expect(($this->handler)(new ListAuditEntriesCommand(subjectType: 'nothing'))->auditEntries->total())->toBe(0);
    });

    it('filters by the days the entry was recorded between and echoes the bounds it applied', function () {
        AuditEntry::factory()->create(['subject_id' => 'before', 'occurred_at' => '2026-09-09 23:59:59']);
        AuditEntry::factory()->create(['subject_id' => 'inside', 'occurred_at' => '2026-09-10 10:00:00']);

        $result = ($this->handler)(new ListAuditEntriesCommand(createdFrom: '2026-09-10', createdTo: '2026-09-10'));

        expect(listAuditEntriesSubjects($result->auditEntries))->toBe(['inside'])
            ->and($result->filters['created_from'])->toBe('2026-09-10')
            ->and($result->filters['created_to'])->toBe('2026-09-10');
    });

    it('pages at every size it offers', function (int $perPage) {
        AuditEntry::factory()->count(12)->create();

        $result = ($this->handler)(new ListAuditEntriesCommand(perPage: (string) $perPage));

        expect($result->auditEntries->perPage())->toBe($perPage)
            ->and($result->auditEntries->count())->toBe(min($perPage, 12));
    })->with([10, 25, 50]);

    it('hands back rows carrying every field the table renders', function () {
        $user = User::factory()->create(['name' => 'Somchai']);
        $entry = AuditEntry::factory()->about('invoice', 'i-1', 'invoice.issued')->by($user->id)->create([
            'data' => ['amount' => '100.00'],
        ]);

        $row = ($this->handler)(new ListAuditEntriesCommand)->auditEntries->items()[0];

        expect($row)->toBeInstanceOf(AuditEntryListRow::class)
            ->and($row->toArray())->toBe([
                'id' => $entry->id,
                'occurred_at' => $entry->fresh()->occurred_at->toISOString(),
                'actor_id' => $user->id,
                'actor_name' => 'Somchai',
                'event' => 'invoice.issued',
                'subject_type' => 'invoice',
                'subject_id' => 'i-1',
                'data' => ['amount' => '100.00'],
            ]);
    });

    it('keeps the flat paginator envelope the frontend reads', function () {
        AuditEntry::factory()->create();

        $auditEntries = ($this->handler)(new ListAuditEntriesCommand)->auditEntries->toArray();

        expect(array_keys($auditEntries))->toContain(
            'data', 'current_page', 'last_page', 'per_page', 'total', 'next_page_url',
        )->and($auditEntries)->not->toHaveKey('meta');
    });
});
