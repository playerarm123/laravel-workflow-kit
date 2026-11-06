<?php

namespace App\Domain\Media\MediaFolder\Exceptions;

use App\Domain\Media\MediaFolder\MediaFolderEntity;
use App\Domain\Shared\Exceptions\RepositoryException;
use Override;

class MediaFolderRepositoryException extends RepositoryException
{
    #[Override]
    public static function entityName(): string
    {
        return MediaFolderEntity::entityName();
    }
}
