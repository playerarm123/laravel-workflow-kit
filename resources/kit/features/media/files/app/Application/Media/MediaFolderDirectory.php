<?php

namespace App\Application\Media;

/**
 * Reads the folders of the image library. An adapter in App\Infra implements it and a service
 * provider binds the two (layers.md).
 */
interface MediaFolderDirectory
{
    /**
     * Every folder by name, with how many images each holds.
     *
     * @return list<MediaFolderOption>
     */
    public function folders(): array;
}
