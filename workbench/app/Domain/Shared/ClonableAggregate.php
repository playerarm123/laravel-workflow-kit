<?php

namespace App\Domain\Shared;

use App\Domain\Shared\Ports\IdGenerator;

/**
 * An aggregate that may be copied into a new one. The aggregate alone decides what the
 * copy carries: the values it keeps, a fresh id for every child, and what it resets
 * (status, running totals). A repository's clone() calls this and inserts the result.
 *
 * Implement it only when the entity can build a valid copy by itself. An aggregate whose
 * copy needs new business-unique values (a prefix, a username) or that must never be
 * duplicated (a wallet balance) stays non-clonable; build the new one with create().
 */
interface ClonableAggregate
{
    /**
     * A new, never-saved aggregate with the given root id. The source must not change.
     */
    public function cloneAs(string $newId, IdGenerator $ids): static;
}
