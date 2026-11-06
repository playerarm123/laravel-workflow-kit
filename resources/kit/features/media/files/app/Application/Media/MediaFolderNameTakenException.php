<?php

namespace App\Application\Media;

use App\Application\ApplicationException;

/**
 * A use case says no. The controller that can tell the user what to do next catches it by
 * name (exceptions.md).
 */
final class MediaFolderNameTakenException extends ApplicationException
{
    public const int TAKEN = 100;

    public static function taken(?string $exceptFolderId = null): self
    {
        return new self(
            message: 'Another media folder has the name already.',
            code: self::TAKEN,
            context: ['media_folder_id' => $exceptFolderId],
        );
    }
}
