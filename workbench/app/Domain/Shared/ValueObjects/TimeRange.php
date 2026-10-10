<?php

namespace App\Domain\Shared\ValueObjects;

/**
 * A stretch of the day between two times, both ends included. An end before its start runs past
 * midnight: 23:00 → 02:00 holds 01:00.
 */
final readonly class TimeRange
{
    private function __construct(
        public TimeOfDay $start,
        public TimeOfDay $end,
    ) {}

    public static function from(TimeOfDay $start, TimeOfDay $end): self
    {
        return new self($start, $end);
    }

    public function contains(TimeOfDay $time): bool
    {
        return $time->isBetween($this->start, $this->end);
    }
}
