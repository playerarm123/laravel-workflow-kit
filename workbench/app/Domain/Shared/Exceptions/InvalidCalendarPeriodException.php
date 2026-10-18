<?php

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\ValueObjects\CalendarDate;

/**
 * A period the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it with `after_or_equal:`, so no entry point catches it (exceptions.md).
 */
final class InvalidCalendarPeriodException extends DomainValueException
{
    public const int ENDS_BEFORE_START = 100;

    /**
     * @param  array<string, mixed>  $context
     */
    public static function create(string $message, int $code, array $context = []): self
    {
        return new self(
            message: "Invalid calendar period : {$message}.",
            code: $code,
            context: $context,
        );
    }

    public static function endsBeforeStart(CalendarDate $start, CalendarDate $end): self
    {
        return self::create('the period ends before it starts', self::ENDS_BEFORE_START, ['start' => $start->toString(), 'end' => $end->toString()]);
    }
}
