<?php

namespace App\Application\Audit;

/**
 * The columns the audit log sorts by, as the query string spells them.
 *
 * Only the time: an entry is read in the order things happened, and every other column is
 * something to filter or search by, not to order by.
 */
enum AuditEntryListSort: string
{
    case OccurredAt = 'occurred_at';

    /**
     * Anything the list does not sort by falls back to the default instead of failing:
     * a hand-edited URL gets a page, not a 422.
     */
    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? self::OccurredAt;
    }
}
