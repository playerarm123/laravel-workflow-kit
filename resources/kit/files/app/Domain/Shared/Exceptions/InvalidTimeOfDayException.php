<?php

namespace App\Domain\Shared\Exceptions;

/**
 * A value the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it with `date_format:H:i`, so no entry point catches it (exceptions.md).
 */
final class InvalidTimeOfDayException extends DomainValueException
{
    public const int MINUTES_OUT_OF_RANGE = 100;

    public const int HOUR_OUT_OF_RANGE = 101;

    public const int MINUTE_OUT_OF_RANGE = 102;

    public const int MALFORMED = 103;

    public static function minutesOutOfRange(int $minutes): self
    {
        return new self(
            message: 'Invalid time of day : the minutes since midnight must be between 0 and 1439.',
            code: self::MINUTES_OUT_OF_RANGE,
            context: ['minutes' => $minutes],
        );
    }

    public static function hourOutOfRange(int $hour): self
    {
        return new self(
            message: 'Invalid time of day : the hour must be between 0 and 23.',
            code: self::HOUR_OUT_OF_RANGE,
            context: ['hour' => $hour],
        );
    }

    public static function minuteOutOfRange(int $minute): self
    {
        return new self(
            message: 'Invalid time of day : the minute must be between 0 and 59.',
            code: self::MINUTE_OUT_OF_RANGE,
            context: ['minute' => $minute],
        );
    }

    public static function malformed(string $time): self
    {
        return new self(
            message: "Invalid time of day : \"{$time}\" is not written as HH:mm.",
            code: self::MALFORMED,
            context: ['time' => $time],
        );
    }
}
