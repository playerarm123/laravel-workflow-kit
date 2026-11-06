<?php

namespace App\Domain\Media\MediaItem\Exceptions;

use App\Domain\Shared\Exceptions\DomainValueException;

/**
 * A value the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it, so no entry point catches it (exceptions.md).
 */
final class InvalidMediaFileException extends DomainValueException
{
    public const int EMPTY_DISK = 100;

    public const int EMPTY_PATH = 101;

    public const int UNSUPPORTED_MIME = 102;

    public const int INVALID_SIZE = 103;

    public const int INVALID_DIMENSIONS = 104;

    public static function emptyDisk(): self
    {
        return new self(message: 'Invalid media file : the disk is empty.', code: self::EMPTY_DISK);
    }

    public static function emptyPath(): self
    {
        return new self(message: 'Invalid media file : a path is empty.', code: self::EMPTY_PATH);
    }

    public static function unsupportedMime(string $mimeType): self
    {
        return new self(
            message: "Invalid media file : [{$mimeType}] is not an image.",
            code: self::UNSUPPORTED_MIME,
            context: ['mime_type' => $mimeType],
        );
    }

    public static function invalidSize(int $sizeBytes, int $thumbnailSizeBytes): self
    {
        return new self(
            message: "Invalid media file : sizes {$sizeBytes} and {$thumbnailSizeBytes} bytes are not both positive.",
            code: self::INVALID_SIZE,
            context: ['size_bytes' => $sizeBytes, 'thumbnail_size_bytes' => $thumbnailSizeBytes],
        );
    }

    public static function invalidDimensions(int $width, int $height): self
    {
        return new self(
            message: "Invalid media file : {$width}×{$height} pixels is not a picture.",
            code: self::INVALID_DIMENSIONS,
            context: ['width' => $width, 'height' => $height],
        );
    }
}
