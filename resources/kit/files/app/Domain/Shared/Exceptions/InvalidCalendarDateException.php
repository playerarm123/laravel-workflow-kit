<?php

namespace App\Domain\Shared\Exceptions;

/**
 * A day the domain refuses to hold. It is the caller's bug, because the FormRequest should have
 * stopped it with `date_format:Y-m-d`, so no entry point catches it (exceptions.md).
 */
final class InvalidCalendarDateException extends DomainValueException
{
    public const int MALFORMED = 100;

    public const int NOT_ON_THE_CALENDAR = 101;

    /**
     * @param  array<string, mixed>  $context
     */
    public static function create(string $message, int $code, array $context = []): self
    {
        return new self(
            message: "Invalid calendar date : {$message}.",
            code: $code,
            context: $context,
        );
    }

    public static function malformed(string $date): self
    {
        return self::create('the date is not a day of the calendar written as Y-m-d', self::MALFORMED, ['date' => $date]);
    }

    public static function notOnTheCalendar(int $year, int $month, int $day): self
    {
        return self::create('the calendar has no such day', self::NOT_ON_THE_CALENDAR, ['year' => $year, 'month' => $month, 'day' => $day]);
    }
}
