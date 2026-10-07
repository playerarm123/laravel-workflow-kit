<?php

namespace App\Application\Audit;

use App\Domain\Shared\AggregateRoot;

/**
 * The record of what happened to an aggregate, written in the same transaction as the change
 * it describes (audit-log.md). Append only: there is no way to change or remove an entry.
 *
 * Who acted is never passed in. The adapter reads it from where the request put it, so a
 * handler can neither forget it nor name someone else.
 */
interface AuditLog
{
    /**
     * @param  string  $event  `{subject}.{past-tense verb}` in snake case, e.g. `customer.created`
     * @param  array<string, scalar|null>  $data  ids, enum values, amounts and codes — never personal data
     *
     * @throws AuditLogException
     */
    public function record(string $event, AggregateRoot $subject, array $data = []): void;
}
