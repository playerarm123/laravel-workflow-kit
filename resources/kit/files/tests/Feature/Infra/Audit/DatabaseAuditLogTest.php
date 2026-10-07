<?php

use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditLogException;
use App\Domain\Shared\AggregateRoot;
use App\Infra\Audit\DatabaseAuditLog;
use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A root the kit owns, so this test names no project's domain.
 */
final class AuditedThingEntity extends AggregateRoot
{
    public function __construct(private readonly string $id) {}

    public function id(): string
    {
        return $this->id;
    }

    public static function entityName(): string
    {
        return 'Audited Thing';
    }
}

describe('DatabaseAuditLog', function () {
    beforeEach(function () {
        $this->audit = app(AuditLog::class);
        $this->subject = new AuditedThingEntity('0199a000-0000-7000-8000-000000000001');
    });

    it('is the adapter bound to the port', function () {
        expect($this->audit)->toBeInstanceOf(DatabaseAuditLog::class);
    });

    describe('record', function () {
        it('records the event, the subject and the actor the request put into Context', function () {
            Context::add('actor_id', 'user-1');

            $this->audit->record('audited_thing.created', $this->subject, ['amount' => '100.00', 'status' => 'active']);

            $entry = AuditEntry::query()->sole();
            expect($entry->event)->toBe('audited_thing.created')
                ->and($entry->subject_type)->toBe('audited_thing')
                ->and($entry->subject_id)->toBe($this->subject->id())
                ->and($entry->actor_id)->toBe('user-1')
                ->and($entry->data)->toBe(['amount' => '100.00', 'status' => 'active'])
                ->and($entry->occurred_at->isToday())->toBeTrue();
        });

        it('records no actor when the system acted', function () {
            $this->audit->record('audited_thing.expired', $this->subject);

            $entry = AuditEntry::query()->sole();
            expect($entry->actor_id)->toBeNull()
                ->and($entry->data)->toBe([]);
        });

        it('rolls back with the transaction it was written in', function () {
            try {
                DB::transaction(function () {
                    $this->audit->record('audited_thing.created', $this->subject);

                    throw new RuntimeException('the write it describes failed');
                });
            } catch (RuntimeException) {
            }

            expect(AuditEntry::query()->count())->toBe(0);
        });

        it('throws AuditLogException carrying the database failure when the entry cannot be written', function () {
            Schema::table('audit_entries', fn (Blueprint $table) => $table->dropColumn('event'));

            try {
                $this->audit->record('audited_thing.created', $this->subject);
                $this->fail('no AuditLogException was thrown');
            } catch (AuditLogException $exception) {
                expect($exception->getPrevious())->toBeInstanceOf(QueryException::class)
                    ->and($exception->getMessage())->toContain('audited_thing.created');
            }
        });
    });
});
