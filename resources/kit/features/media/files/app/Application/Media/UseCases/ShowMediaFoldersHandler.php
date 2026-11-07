<?php

namespace App\Application\Media\UseCases;

use App\Application\Media\MediaFolderDirectory;
use App\Application\Media\MediaFolderOption;

/**
 * The folders of the image library, by name.
 */
final class ShowMediaFoldersHandler
{
    public function __construct(
        protected MediaFolderDirectory $folders,
    ) {}

    /**
     * @return list<MediaFolderOption>
     */
    public function __invoke(): array
    {
        return $this->folders->folders();
    }
}
