<?php

require_once __DIR__.'/../ListQueryContract.php';

use App\Application\Audit\AuditEntryListSort;
use App\Application\Audit\UseCases\ListAuditEntries\AuditEntryListRow;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesCriteria;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesQuery;
use App\Application\Concerns\DateRange;
use App\Infra\Persistence\Eloquent\Queries\EloquentListAuditEntriesQuery;
use App\Models\AuditEntry;
use App\Models\User;

/**
 * @param  'asc'|'desc'  $direction
 */
function eloquentListAuditEntriesCriteria(
    string $search = '',
    ?string $subjectType = null,
    ?DateRange $createdAt = null,
    string $direction = 'desc',
    int $perPage = 10,
): ListAuditEntriesCriteria {
    return new ListAuditEntriesCriteria(
        sort: AuditEntryListSort::OccurredAt,
        direction: $direction,
        search: $search,
        subjectType: $subjectType,
        createdAt: $createdAt ?? DateRange::fromInput('', ''),
        perPage: $perPage,
    );
}

/**
 * @return list<string>
 */
function eloquentListAuditEntriesSubjects(EloquentListAuditEntriesQuery $query, ListAuditEntriesCriteria $criteria): array
{
    return array_map(fn (AuditEntryListRow $row) => $row->subjectId, $query->paginate($criteria)->items());
}

listQueryContract(
    query: ListAuditEntriesQuery::class,
    criteria: fn (array $overrides): ListAuditEntriesCriteria => eloquentListAuditEntriesCriteria(
        search: $overrides['search'] ?? '',
        perPage: $overrides['perPage'] ?? 10,
    ),
    seed: fn (int $count) => AuditEntry::factory()->count($count)->create(['occurred_at' => '2026-09-10 12:00:00']),
    break: fn () => breakListQueryColumn('audit_entries', 'occurred_at'),
    seedMatching: fn (string $text) => AuditEntry::factory()->create(['subject_id' => "subject-{$text}-1"]),
);

beforeEach(function () {
    $this->query = app(EloquentListAuditEntriesQuery::class);
});

describe('EloquentListAuditEntriesQuery', function () {
    it('is what the read port resolves to', function () {
        expect(app(ListAuditEntriesQuery::class))->toBeInstanceOf(EloquentListAuditEntriesQuery::class);
    });

    it('sorts by the time each entry was recorded, in either direction', function (string $direction, array $expected) {
        AuditEntry::factory()->create(['subject_id' => 'middle', 'occurred_at' => '2026-09-10 12:00:00']);
        AuditEntry::factory()->create(['subject_id' => 'oldest', 'occurred_at' => '2026-09-09 12:00:00']);
        AuditEntry::factory()->create(['subject_id' => 'newest', 'occurred_at' => '2026-09-11 12:00:00']);

        expect(eloquentListAuditEntriesSubjects($this->query, eloquentListAuditEntriesCriteria(direction: $direction)))->toBe($expected);
    })->with([
        'newest first' => ['desc', ['newest', 'middle', 'oldest']],
        'oldest first' => ['asc', ['oldest', 'middle', 'newest']],
    ]);

    it('searches the subject id', function () {
        AuditEntry::factory()->create(['subject_id' => 'abc-123']);
        AuditEntry::factory()->create(['subject_id' => 'xyz-789']);

        expect(eloquentListAuditEntriesSubjects($this->query, eloquentListAuditEntriesCriteria(search: 'ABC')))->toBe(['abc-123']);
    });

    it('filters by subject type', function () {
        AuditEntry::factory()->about('payment', 'a-payment', 'payment.received')->create();
        AuditEntry::factory()->about('invoice', 'a-invoice', 'invoice.issued')->create();

        expect(eloquentListAuditEntriesSubjects($this->query, eloquentListAuditEntriesCriteria(subjectType: 'invoice')))->toBe(['a-invoice']);
    });

    it('applies the day range to the time the entry was recorded', function () {
        AuditEntry::factory()->create(['subject_id' => 'before', 'occurred_at' => '2026-09-09 23:59:59']);
        AuditEntry::factory()->create(['subject_id' => 'inside', 'occurred_at' => '2026-09-10 00:00:00']);
        AuditEntry::factory()->create(['subject_id' => 'after', 'occurred_at' => '2026-09-11 00:00:00']);

        $criteria = eloquentListAuditEntriesCriteria(createdAt: DateRange::fromInput('2026-09-10', '2026-09-10'));

        expect(eloquentListAuditEntriesSubjects($this->query, $criteria))->toBe(['inside']);
    });

    it('names the actor, and leaves the name empty for the system and for a user that is gone', function () {
        $user = User::factory()->create(['name' => 'Somchai']);
        AuditEntry::factory()->by($user->id)->create(['subject_id' => 'by-user', 'occurred_at' => '2026-09-12 00:00:00']);
        AuditEntry::factory()->create(['subject_id' => 'by-system', 'occurred_at' => '2026-09-11 00:00:00']);
        AuditEntry::factory()->by('0199a000-0000-7000-8000-0000000000ff')->create(['subject_id' => 'by-gone', 'occurred_at' => '2026-09-10 00:00:00']);

        $rows = $this->query->paginate(eloquentListAuditEntriesCriteria())->items();

        expect(array_map(fn (AuditEntryListRow $row) => [$row->actorId, $row->actorName], $rows))->toBe([
            [$user->id, 'Somchai'],
            [null, null],
            ['0199a000-0000-7000-8000-0000000000ff', null],
        ]);
    });

    it('carries every field of the entry onto its row', function () {
        $entry = AuditEntry::factory()->about('invoice', 'i-1', 'invoice.issued')->create([
            'data' => ['amount' => '100.00', 'order_id' => 'o-1'],
            'occurred_at' => '2026-09-10 08:30:00',
        ]);

        $row = $this->query->paginate(eloquentListAuditEntriesCriteria())->items()[0];

        expect($row->toArray())->toBe([
            'id' => $entry->id,
            'occurred_at' => $entry->fresh()->occurred_at->toISOString(),
            'actor_id' => null,
            'actor_name' => null,
            'event' => 'invoice.issued',
            'subject_type' => 'invoice',
            'subject_id' => 'i-1',
            'data' => ['amount' => '100.00', 'order_id' => 'o-1'],
        ]);
    });
});
