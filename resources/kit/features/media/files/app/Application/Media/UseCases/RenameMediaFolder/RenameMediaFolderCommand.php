<?php

namespace App\Application\Media\UseCases\RenameMediaFolder;

use Spatie\LaravelData\Data;

final class RenameMediaFolderCommand extends Data
{
    public function __construct(
        public readonly string $mediaFolderId,
        public readonly string $name,
    ) {}
}
