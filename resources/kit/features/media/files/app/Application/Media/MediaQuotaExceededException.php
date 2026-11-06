<?php

namespace App\Application\Media;

use App\Application\ApplicationException;

/**
 * A use case says no: the images would take the library past its limit. The controller catches it
 * by name and says how much room there is (exceptions.md).
 */
final class MediaQuotaExceededException extends ApplicationException
{
    public const int EXCEEDED = 100;

    private int $limitBytes = 0;

    public static function exceeded(int $usedBytes, int $addedBytes, int $limitBytes): self
    {
        $exception = new self(
            message: "Adding {$addedBytes} bytes to the {$usedBytes} the image library uses would pass its limit of {$limitBytes}.",
            code: self::EXCEEDED,
            context: ['used_bytes' => $usedBytes, 'added_bytes' => $addedBytes, 'limit_bytes' => $limitBytes],
        );
        $exception->limitBytes = $limitBytes;

        return $exception;
    }

    public function limitBytes(): int
    {
        return $this->limitBytes;
    }
}
