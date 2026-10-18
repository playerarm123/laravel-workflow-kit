<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Enums\Weekday;
use App\Domain\Shared\Exceptions\InvalidCalendarDateException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A day on the calendar, `2026-10-05`, with no time and no time zone (dates.md): a birthday, a
 * due date, the last day of a contract. It is the same day for every viewer, so it never moves
 * back one day the way a midnight read in another zone does.
 *
 * A point in time is not one of these: the domain holds it as a DateTimeImmutable and asks the
 * Clock for the current one.
 */
final readonly class CalendarDate
{
    private const string FORMAT = 'Y-m-d';

    /**
     * @param  DateTimeImmutable  $midnight  midnight UTC of the day, which no daylight saving
     *                                       shift ever moves
     */
    private function __construct(
        private DateTimeImmutable $midnight,
    ) {}

    /**
     * A day as the database, a form and the page carry it: `'2026-10-05'`.
     *
     * It reads the string exactly. A day the calendar does not have (`'2026-02-30'`) or any
     * other shape (`'2026-2-5'`, `'05/10/2026'`) is an invalid value: validate the field with
     * `date_format:Y-m-d`.
     */
    public static function fromString(string $date): self
    {
        $midnight = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $date, new DateTimeZone('UTC'));

        if ($midnight === false || $midnight->format(self::FORMAT) !== $date) {
            throw InvalidCalendarDateException::malformed($date);
        }

        return new self($midnight);
    }

    public static function of(int $year, int $month, int $day): self
    {
        if ($year < 1 || $year > 9999 || ! checkdate($month, $day, $year)) {
            throw InvalidCalendarDateException::notOnTheCalendar($year, $month, $day);
        }

        return self::fromString(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * The day a point in time falls on in its own time zone. Move the moment into the zone the
     * day is meant in first (`->setTimezone()`): near midnight, two zones disagree.
     */
    public static function fromDateTime(DateTimeInterface $moment): self
    {
        return self::fromString($moment->format(self::FORMAT));
    }

    public function year(): int
    {
        return (int) $this->midnight->format('Y');
    }

    public function month(): int
    {
        return (int) $this->midnight->format('n');
    }

    public function day(): int
    {
        return (int) $this->midnight->format('j');
    }

    public function weekday(): Weekday
    {
        return Weekday::from((int) $this->midnight->format('N'));
    }

    /** The day `$days` later, or earlier when `$days` is negative. */
    public function addDays(int $days): self
    {
        return new self($this->midnight->modify(sprintf('%+d days', $days)));
    }

    /**
     * The same day `$months` later, or earlier when `$months` is negative. A day the target
     * month does not have becomes its last day: January 31 plus one month is February 28 (or 29),
     * never March 3.
     */
    public function addMonths(int $months): self
    {
        $first = $this->midnight->modify('first day of this month')->modify(sprintf('%+d months', $months));

        return self::of((int) $first->format('Y'), (int) $first->format('n'), min($this->day(), (int) $first->format('t')));
    }

    /**
     * The days from this day to $other: positive when $other is later, negative when earlier,
     * zero on the same day.
     */
    public function daysUntil(self $other): int
    {
        $days = (int) $this->midnight->diff($other->midnight)->days;

        return $other->isBefore($this) ? -$days : $days;
    }

    /**
     * The moment this day starts in $zone, for reading the points in time that fall on it:
     * from `startsIn($zone)` up to, not including, `addDays(1)->startsIn($zone)`.
     */
    public function startsIn(DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($this->toString().' 00:00:00', $zone);
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function isBefore(self $other): bool
    {
        return $this->toString() < $other->toString();
    }

    public function isAfter(self $other): bool
    {
        return $this->toString() > $other->toString();
    }

    /**
     * The day as the database stores it and the page reads it: `'2026-10-05'`. A Row, a Result
     * or a FormValues sends it as is, and the page formats it with `formatCalendarDate()`.
     */
    public function toString(): string
    {
        return $this->midnight->format(self::FORMAT);
    }
}
