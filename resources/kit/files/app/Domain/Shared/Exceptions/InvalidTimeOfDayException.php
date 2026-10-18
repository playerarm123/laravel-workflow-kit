<?php

namespace App\Domain\Shared\Exceptions;

/**
 * A time of day the domain refuses to hold. It is the caller's bug, because the FormRequest
 * should have stopped it with `date_format:H:i`, so no entry point catches it (exceptions.md).
 */
final class InvalidTimeOfDayException extends DomainValueException
{
    public const int MALFORMED = 100;

    public const int NOT_ON_THE_CLOCK = 101;

    public const int OUT_OF_DAY = 102;

    /**
     * @param  array<string, mixed>  $context
     */
    public static function create(string $message, int $code, array $context = []): self
    {
        return new self(
            message: "Invalid time of day : {$message}.",
            code: $code,
            context: $context,
        );
    }

    public static function malformed(string $time): self
    {
        return self::create('the time is not written as H:i on the 24-hour clock', self::MALFORMED, ['time' => $time]);
    }

    public static function notOnTheClock(int $hour, int $minute): self
    {
        return self::create('the clock has no such hour or minute', self::NOT_ON_THE_CLOCK, ['hour' => $hour, 'minute' => $minute]);
    }

    public static function outOfDay(int $minutes): self
    {
        return self::create('the minutes since midnight must be from 0 to 1439', self::OUT_OF_DAY, ['minutes' => $minutes]);
    }
}
