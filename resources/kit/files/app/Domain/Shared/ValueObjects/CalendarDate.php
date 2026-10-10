<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidCalendarDateException;
use DateTimeImmutable;
use Override;
use Stringable;

/**
 * A day on the calendar, held as `Y-m-d` (dates.md). It is not a point in time, so it carries no
 * time zone: the page formats it with `formatCalendarDate()`, never as a local time.
 *
 * Two days compare as their strings do, because `Y-m-d` sorts the way the calendar runs.
 */
final readonly class CalendarDate implements Stringable
{
    private function __construct(
        private string $value,
    ) {}

    /**
     * A day as a form posts it and the database stores it: `'2026-10-05'`.
     *
     * Anything that is not a real day written exactly as `Y-m-d` (a 31st of April, a missing
     * zero, a time after it) is an invalid value: validate the field with `date_format:Y-m-d`.
     */
    public static function from(string $value): self
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw InvalidCalendarDateException::malformed($value);
        }

        return new self($value);
    }

    /**
     * The day a point in time falls on, read in that point's own time zone.
     */
    public static function fromDateTimeImmutable(DateTimeImmutable $dateTime): self
    {
        return new self($dateTime->format('Y-m-d'));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isBefore(self $other): bool
    {
        return $this->value < $other->value;
    }

    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
