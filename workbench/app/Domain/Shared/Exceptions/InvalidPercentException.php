<?php

namespace App\Domain\Shared\Exceptions;

/**
 * A value the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it, so no entry point catches it (exceptions.md).
 */
final class InvalidPercentException extends DomainValueException
{
    public const int NEGATIVE = 100;

    public const int MALFORMED = 101;

    public static function negative(int $basisPoints): self
    {
        return new self(
            message: 'Invalid percent : a percent cannot be negative.',
            code: self::NEGATIVE,
            context: ['basisPoints' => $basisPoints],
        );
    }

    public static function malformed(string $percent): self
    {
        return new self(
            message: 'Invalid percent : the percent is not a decimal with at most two decimals.',
            code: self::MALFORMED,
            context: ['percent' => $percent],
        );
    }
}
