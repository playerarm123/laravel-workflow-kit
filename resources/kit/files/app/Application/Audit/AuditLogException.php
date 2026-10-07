<?php

namespace App\Application\Audit;

use RuntimeException;
use Throwable;

/**
 * The audit log refused an entry — a failure, never a refusal. Nobody catches it: it rolls the
 * handler's transaction back with the change it would have described, and reaches the log as a
 * reported 500 (exceptions.md).
 */
final class AuditLogException extends RuntimeException
{
    public static function writeFailed(string $event, string $subjectType, string $subjectId, Throwable $previous): self
    {
        return new self(sprintf('Could not record audit entry [%s] for [%s:%s].', $event, $subjectType, $subjectId), 0, $previous);
    }
}
