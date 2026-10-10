<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidTimeOfDayException;
use DateTimeInterface;

/**
 * A time on the clock, held as the minutes since midnight (0–1439). It carries no day and no
 * time zone, so it compares and wraps on the clock alone: 23:30 plus an hour is 00:30.
 *
 * A form posts it and a page reads it as `HH:mm` (form-pages.md), through `fromString()` and
 * `format()`. The database stores `->minutes` in an integer column.
 */
final readonly class TimeOfDay
{
    private const int MINUTES_PER_DAY = 1440;

    private function __construct(
        public int $minutes,
    ) {
        if ($minutes < 0 || $minutes >= self::MINUTES_PER_DAY) {
            throw InvalidTimeOfDayException::minutesOutOfRange($minutes);
        }
    }

    public static function from(int $minutes): self
    {
        return new self($minutes);
    }

    public static function fromHourMinute(int $hour, int $minute): self
    {
        if ($hour < 0 || $hour > 23) {
            throw InvalidTimeOfDayException::hourOutOfRange($hour);
        }

        if ($minute < 0 || $minute > 59) {
            throw InvalidTimeOfDayException::minuteOutOfRange($minute);
        }

        return new self($hour * 60 + $minute);
    }

    /**
     * A time as a form posts it: `'09:30'`. Anything else is an invalid value: validate the
     * field with `date_format:H:i`.
     */
    public static function fromString(string $time): self
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            throw InvalidTimeOfDayException::malformed($time);
        }

        return self::fromHourMinute((int) $matches[1], (int) $matches[2]);
    }

    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        $minutes = ((int) $dateTime->format('H') * 60)
            + (int) $dateTime->format('i');

        return new self($minutes);
    }

    public static function startOfDay(): self
    {
        return new self(0);
    }

    public static function endOfDay(): self
    {
        return new self(self::MINUTES_PER_DAY - 1);
    }

    public function hour(): int
    {
        return intdiv($this->minutes, 60);
    }

    public function minute(): int
    {
        return $this->minutes % 60;
    }

    public function addMinutes(int $minutes): self
    {
        $result = ($this->minutes + $minutes) % self::MINUTES_PER_DAY;

        if ($result < 0) {
            $result += self::MINUTES_PER_DAY;
        }

        return new self($result);
    }

    public function subtractMinutes(int $minutes): self
    {
        return $this->addMinutes(-$minutes);
    }

    public function diffInMinutes(self $other): int
    {
        return $this->minutes - $other->minutes;
    }

    public function equals(self $other): bool
    {
        return $this->minutes === $other->minutes;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minutes > $other->minutes;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->minutes >= $other->minutes;
    }

    public function lessThan(self $other): bool
    {
        return $this->minutes < $other->minutes;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->minutes <= $other->minutes;
    }

    /**
     * Whether this time falls between two others, both ends included. An end before its start
     * runs past midnight.
     */
    public function isBetween(self $start, self $end): bool
    {
        if ($start->lessThanOrEqual($end)) {
            return $this->greaterThanOrEqual($start)
                && $this->lessThanOrEqual($end);
        }

        return $this->greaterThanOrEqual($start)
            || $this->lessThanOrEqual($end);
    }

    public function format(): string
    {
        return sprintf('%02d:%02d', $this->hour(), $this->minute());
    }
}
