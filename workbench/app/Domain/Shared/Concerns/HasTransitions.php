<?php

namespace App\Domain\Shared\Concerns;

/**
 * The states a status may move to next, declared once on its enum (states.md). The enum writes
 * `transitions()` as a `match ($this)` over every case, so a new case is a PHPStan error until it
 * says where it goes. A final status goes nowhere.
 *
 * Nothing here refuses: the entity asks `canBecome()` and throws its own aggregate's refusal, which
 * the controller catches by name (exceptions.md).
 */
trait HasTransitions
{
    /**
     * The statuses this one may become next. Never itself: staying put is no change.
     *
     * @return list<self>
     */
    abstract public function transitions(): array;

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    /**
     * Whether this status is an end, one that becomes nothing else.
     */
    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }
}
