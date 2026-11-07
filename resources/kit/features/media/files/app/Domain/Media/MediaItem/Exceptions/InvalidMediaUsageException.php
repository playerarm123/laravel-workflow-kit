<?php

namespace App\Domain\Media\MediaItem\Exceptions;

use App\Domain\Shared\Exceptions\DomainValueException;

/**
 * A value the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it, so no entry point catches it (exceptions.md).
 */
final class InvalidMediaUsageException extends DomainValueException
{
    public const int MALFORMED_USABLE_TYPE = 100;

    public const int EMPTY_USABLE_ID = 101;

    public static function malformedUsableType(string $usableType): self
    {
        return new self(
            message: "Invalid media usage : [{$usableType}] is not a snake_case key.",
            code: self::MALFORMED_USABLE_TYPE,
            context: ['usable_type' => $usableType],
        );
    }

    public static function emptyUsableId(string $usableType): self
    {
        return new self(
            message: "Invalid media usage : the id of the [{$usableType}] is empty.",
            code: self::EMPTY_USABLE_ID,
            context: ['usable_type' => $usableType],
        );
    }
}
