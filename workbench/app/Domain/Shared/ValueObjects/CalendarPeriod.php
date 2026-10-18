<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidCalendarPeriodException;

/**
 * A run of calendar days, its first and its last day both inside it: a promotion from
 * 2026-10-01 to 2026-10-31 holds 31 days, and one from 2026-10-05 to 2026-10-05 holds one.
 *
 * It never ends before it starts. A pair a user may enter the wrong way round is the
 * FormRequest's to refuse (`after_or_equal:`), never this class's to swap; a list's filter
 * that swaps them is `App\Application\Concerns\DateRange` (list-queries.md).
 */
final readonly class CalendarPeriod
{
    private function __construct(
        public CalendarDate $start,
        public CalendarDate $end,
    ) {
        if ($end->isBefore($start)) {
            throw InvalidCalendarPeriodException::endsBeforeStart($start, $end);
        }
    }

    public static function from(CalendarDate $start, CalendarDate $end): self
    {
        return new self($start, $end);
    }

    /** A period from the two days a form posts: `'2026-10-01'`, `'2026-10-31'`. */
    public static function fromStrings(string $start, string $end): self
    {
        return new self(CalendarDate::fromString($start), CalendarDate::fromString($end));
    }

    /** Whether $day is one of the period's days, its first and last included. */
    public function contains(CalendarDate $day): bool
    {
        return ! $day->isBefore($this->start) && ! $day->isAfter($this->end);
    }

    /** Whether the two periods share at least one day. Two that only touch, the 5th and the 6th, do not. */
    public function overlaps(self $other): bool
    {
        return ! $other->end->isBefore($this->start) && ! $other->start->isAfter($this->end);
    }

    /** How many days the period holds, both ends counted: never less than one. */
    public function days(): int
    {
        return $this->start->daysUntil($this->end) + 1;
    }

    public function equals(self $other): bool
    {
        return $this->start->equals($other->start) && $this->end->equals($other->end);
    }

    /** The period for a log line or an exception message: `'2026-10-01 – 2026-10-31'`. */
    public function format(): string
    {
        return $this->start->toString().' – '.$this->end->toString();
    }
}
