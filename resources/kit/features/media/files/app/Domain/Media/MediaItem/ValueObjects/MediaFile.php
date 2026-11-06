<?php

namespace App\Domain\Media\MediaItem\ValueObjects;

use App\Domain\Media\MediaItem\Exceptions\InvalidMediaFileException;

/**
 * The stored file of one library image: the resized original, its thumbnail, and what was read
 * while they were written. It keeps the disk's name, because the disk comes from config and may
 * change: an image written to the old disk must still be found after the switch. A url is not the
 * domain's business; the storage port turns a path into one.
 */
final class MediaFile
{
    /**
     * @throws InvalidMediaFileException
     */
    private function __construct(
        private readonly string $disk,
        private readonly string $path,
        private readonly string $thumbnailPath,
        private readonly string $mimeType,
        private readonly int $sizeBytes,
        private readonly int $thumbnailSizeBytes,
        private readonly int $width,
        private readonly int $height,
    ) {
        if (trim($disk) === '') {
            throw InvalidMediaFileException::emptyDisk();
        }

        if (trim($path) === '' || trim($thumbnailPath) === '') {
            throw InvalidMediaFileException::emptyPath();
        }

        if (! str_starts_with($mimeType, 'image/')) {
            throw InvalidMediaFileException::unsupportedMime($mimeType);
        }

        if ($sizeBytes <= 0 || $thumbnailSizeBytes <= 0) {
            throw InvalidMediaFileException::invalidSize($sizeBytes, $thumbnailSizeBytes);
        }

        if ($width <= 0 || $height <= 0) {
            throw InvalidMediaFileException::invalidDimensions($width, $height);
        }
    }

    /**
     * @throws InvalidMediaFileException
     */
    public static function from(
        string $disk,
        string $path,
        string $thumbnailPath,
        string $mimeType,
        int $sizeBytes,
        int $thumbnailSizeBytes,
        int $width,
        int $height,
    ): self {
        return new self($disk, $path, $thumbnailPath, $mimeType, $sizeBytes, $thumbnailSizeBytes, $width, $height);
    }

    public function disk(): string
    {
        return $this->disk;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function thumbnailPath(): string
    {
        return $this->thumbnailPath;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function thumbnailSizeBytes(): int
    {
        return $this->thumbnailSizeBytes;
    }

    /** What the file counts against the plan's storage quota: the original and its thumbnail. */
    public function totalBytes(): int
    {
        return $this->sizeBytes + $this->thumbnailSizeBytes;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function equals(self $other): bool
    {
        return $this->disk === $other->disk
            && $this->path === $other->path
            && $this->thumbnailPath === $other->thumbnailPath;
    }
}
