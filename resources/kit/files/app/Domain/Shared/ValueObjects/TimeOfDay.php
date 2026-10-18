<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidTimeOfDayException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A time on the clock face, `09:30`, to the minute, with no day and no time zone: when a shop
 * opens, when orders close, when a shift starts. It is held as the minutes since midnight, so
 * two times compare as integers.
 *
 * It is never a day plus a time stored as a fake date. A moment is a DateTimeImmutable; this is
 * the time a rule repeats at, and `on()` turns it into the moment of one day.
 */
final readonly class TimeOfDay
{
    private const int MINUTES_PER_DAY = 1440;

    private function __construct(
        public int $minutes,
    ) {
        if ($minutes < 0 || $minutes >= self::MINUTES_PER_DAY) {
            throw InvalidTimeOfDayException::outOfDay($minutes);
        }
    }

    /**
     * A time as a form posts it and the page reads it, `'09:30'`, or as a `time` column returns
     * it, `'09:30:00'`. Seconds other than `:00` are refused: the time is held to the minute.
     * Validate the field with `date_format:H:i`.
     */
    public static function fromString(string $time): self
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::00)?$/', $time, $parts) !== 1) {
            throw InvalidTimeOfDayException::malformed($time);
        }

        return new self((int) $parts[1] * 60 + (int) $parts[2]);
    }

    public static function of(int $hour, int $minute): self
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw InvalidTimeOfDayException::notOnTheClock($hour, $minute);
        }

        return new self($hour * 60 + $minute);
    }

    /** A time as an integer column stores it: the minutes since midnight, 0 to 1439. */
    public static function fromMinutes(int $minutes): self
    {
        return new self($minutes);
    }

    public static function midnight(): self
    {
        return new self(0);
    }

    public function hour(): int
    {
        return intdiv($this->minutes, 60);
    }

    public function minute(): int
    {
        return $this->minutes % 60;
    }

    /**
     * The moment this time falls at on $day in $zone. On the day a clock is set forward, a time
     * that does not exist there moves on by the gap, as PHP moves it.
     */
    public function on(CalendarDate $day, DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($day->toString().' '.$this->toString().':00', $zone);
    }

    public function equals(self $other): bool
    {
        return $this->minutes === $other->minutes;
    }

    public function isBefore(self $other): bool
    {
        return $this->minutes < $other->minutes;
    }

    public function isAfter(self $other): bool
    {
        return $this->minutes > $other->minutes;
    }

    /**
     * The time as the page reads it and a log line shows it: `'09:30'`, on the 24-hour clock.
     * A Row, a Result or a FormValues sends it as is.
     */
    public function toString(): string
    {
        return sprintf('%02d:%02d', $this->hour(), $this->minute());
    }
}
