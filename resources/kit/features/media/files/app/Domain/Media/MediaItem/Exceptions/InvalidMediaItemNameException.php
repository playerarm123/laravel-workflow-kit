<?php

namespace App\Domain\Media\MediaItem\Exceptions;

use App\Domain\Shared\Exceptions\DomainValueException;

/**
 * A value the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it, so no entry point catches it (exceptions.md).
 */
final class InvalidMediaItemNameException extends DomainValueException
{
    public const int EMPTY = 100;

    public const int TOO_LONG = 101;

    public const int MAX_LENGTH = 150;

    public static function empty(): self
    {
        return new self(message: 'Invalid media item name : the name is empty.', code: self::EMPTY);
    }

    public static function tooLong(string $name): self
    {
        $length = mb_strlen($name);

        return new self(
            message: 'Invalid media item name : '.$length.' characters is more than '.self::MAX_LENGTH.'.',
            code: self::TOO_LONG,
            context: ['length' => $length],
        );
    }
}
