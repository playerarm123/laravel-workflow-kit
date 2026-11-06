<?php

namespace App\Domain\Media\MediaItem\Exceptions;

use App\Domain\Media\Exceptions\MediaDomainException;
use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;

/**
 * A business rule of this aggregate says no. The controller that can tell the user what to
 * do next catches it by name (exceptions.md).
 *
 * The usages travel on the exception, not in its context: their labels name people, which the
 * log never carries. The controller reads them through usages() to say where the image is used.
 */
final class MediaItemInUseException extends MediaDomainException
{
    public const int IN_USE = 100;

    /** @var list<MediaUsage> */
    private array $usages = [];

    /**
     * @param  list<MediaUsage>  $usages
     */
    public static function inUse(MediaItemEntity $mediaItem, array $usages): self
    {
        $exception = new self(
            message: "Media item [{$mediaItem->id()}] is still used in ".count($usages).' places.',
            code: self::IN_USE,
            context: [
                'media_item_id' => $mediaItem->id(),
                'usable_types' => array_values(array_unique(array_map(fn (MediaUsage $usage): string => $usage->usableType(), $usages))),
            ],
        );
        $exception->usages = $usages;

        return $exception;
    }

    /**
     * @return list<MediaUsage>
     */
    public function usages(): array
    {
        return $this->usages;
    }
}
