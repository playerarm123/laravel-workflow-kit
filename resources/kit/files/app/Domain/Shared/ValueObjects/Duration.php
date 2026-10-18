<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidDurationException;
use DateTimeImmutable;

/**
 * A length of time, held as a whole number of seconds: how long an invitation lasts, a service
 * takes, a session stays open. Unlike a DateInterval, two of them compare and add as integers.
 *
 * It is never negative. It has no days or months: a day is not always 24 hours across a clock
 * change, and a month has no fixed length, so a rule in days moves a CalendarDate instead
 * (`addDays()`, `addMonths()`).
 */
final readonly class Duration
{
    private const int SECONDS_PER_MINUTE = 60;

    private const int SECONDS_PER_HOUR = 3600;

    private function __construct(
        public int $seconds,
    ) {
        if ($seconds < 0) {
            throw InvalidDurationException::negative($seconds);
        }
    }

    /** A length in seconds, as an integer column stores it. */
    public static function fromSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    public static function fromMinutes(int $minutes): self
    {
        return new self($minutes * self::SECONDS_PER_MINUTE);
    }

    public static function fromHours(int $hours): self
    {
        return new self($hours * self::SECONDS_PER_HOUR);
    }

    /** The time from $start to $end. An $end before $start is an invalid value. */
    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self($end->getTimestamp() - $start->getTimestamp());
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** The whole minutes it holds, the seconds past them dropped: 90 seconds is 1. */
    public function inMinutes(): int
    {
        return intdiv($this->seconds, self::SECONDS_PER_MINUTE);
    }

    /** The whole hours it holds, the minutes past them dropped. */
    public function inHours(): int
    {
        return intdiv($this->seconds, self::SECONDS_PER_HOUR);
    }

    /** The moment this long after $moment: when an invitation sent at $moment expires. */
    public function after(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->modify(sprintf('+%d seconds', $this->seconds));
    }

    public function add(self $other): self
    {
        return new self($this->seconds + $other->seconds);
    }

    public function subtract(self $other): self
    {
        return new self($this->seconds - $other->seconds);
    }

    public function equals(self $other): bool
    {
        return $this->seconds === $other->seconds;
    }

    public function greaterThan(self $other): bool
    {
        return $this->seconds > $other->seconds;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->seconds >= $other->seconds;
    }

    public function lessThan(self $other): bool
    {
        return $this->seconds < $other->seconds;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->seconds <= $other->seconds;
    }

    public function isZero(): bool
    {
        return $this->seconds === 0;
    }

    /**
     * The length for a log line or an exception message, as hours, minutes and seconds:
     * `'1:30:05'`, `'26:00:00'`. A page never shows it; the length goes to the page as
     * `->seconds`.
     */
    public function format(): string
    {
        return sprintf('%d:%02d:%02d', $this->inHours(), intdiv($this->seconds % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE), $this->seconds % self::SECONDS_PER_MINUTE);
    }
}
