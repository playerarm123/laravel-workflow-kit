<?php

namespace App\Domain\Media\MediaItem\Exceptions;

use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Shared\Exceptions\RepositoryException;
use Override;

class MediaItemRepositoryException extends RepositoryException
{
    #[Override]
    public static function entityName(): string
    {
        return MediaItemEntity::entityName();
    }
}
