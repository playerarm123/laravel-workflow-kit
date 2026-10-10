<?php

namespace App\Domain\Shared\Exceptions;

/**
 * A value the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it with `date_format:Y-m-d`, so no entry point catches it (exceptions.md).
 */
final class InvalidCalendarDateException extends DomainValueException
{
    public const int MALFORMED = 100;

    public static function malformed(string $date): self
    {
        return new self(
            message: "Invalid calendar date : \"{$date}\" is not a real day written as Y-m-d.",
            code: self::MALFORMED,
            context: ['date' => $date],
        );
    }
}
