<?php

namespace App\Infra\Audit;

use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditLogException;
use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\Ports\IdGenerator;
use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Override;

/**
 * Writes each entry as one row of `audit_entries`, on the default connection, so it lands or
 * rolls back with the handler's own transaction.
 *
 * The actor is the `actor_id` the request put into Laravel's Context (exceptions.md). Context
 * travels into every queued job the request starts, and a console command or the scheduler
 * never sets it, which is how the system's own work is recorded with no actor.
 */
final class DatabaseAuditLog implements AuditLog
{
    public function __construct(private readonly IdGenerator $ids) {}

    #[Override]
    public function record(string $event, AggregateRoot $subject, array $data = []): void
    {
        $subjectType = self::subjectType($subject);
        $actor = Context::get('actor_id');

        try {
            AuditEntry::query()->insert([
                'id' => $this->ids->next(),
                'event' => $event,
                'subject_type' => $subjectType,
                'subject_id' => $subject->id(),
                'actor_id' => is_scalar($actor) ? (string) $actor : null,
                'data' => json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
        } catch (QueryException $exception) {
            throw AuditLogException::writeFailed($event, $subjectType, $subject->id(), $exception);
        }
    }

    /**
     * `LotteryTypeEntity` → `lottery_type`: the same logical key other tables store for an
     * aggregate, so an entry never names a class that a refactor may move.
     */
    private static function subjectType(AggregateRoot $subject): string
    {
        return Str::snake(Str::beforeLast(class_basename($subject), 'Entity'));
    }
}
