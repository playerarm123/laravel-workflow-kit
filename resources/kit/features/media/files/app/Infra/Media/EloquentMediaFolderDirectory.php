<?php

namespace App\Infra\Media;

use App\Application\Media\MediaFolderDirectory;
use App\Application\Media\MediaFolderOption;
use App\Models\MediaFolder;
use Override;

/**
 * MediaFolderDirectory read straight from the tables. Bound to the port in a service provider,
 * so the core never names it (layers.md).
 */
class EloquentMediaFolderDirectory implements MediaFolderDirectory
{
    #[Override]
    public function folders(): array
    {
        return array_values(MediaFolder::query()
            ->withCount('items')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (MediaFolder $folder): MediaFolderOption => new MediaFolderOption(
                id: $folder->id,
                name: $folder->name,
                itemCount: (int) $folder->getAttribute('items_count'),
            ))
            ->all());
    }
}
