<?php

namespace App\Infra\Media;

use App\Application\Media\MediaStorage;
use App\Application\Media\UseCases\ListMediaItems\MediaItemListRow;
use App\Models\MediaItem;
use App\Models\MediaUsage;

/**
 * Turns a library image into the row every page reads it as, with its urls built on the server.
 * The library's list and the pages that show an image of it, such as a profile's photo, share
 * it, so the picker gets the same row wherever it starts. The caller loads `folder` and `usages`.
 */
final class MediaItemRows
{
    public function __construct(
        protected MediaStorage $storage,
    ) {}

    public function toRow(MediaItem $mediaItem): MediaItemListRow
    {
        return new MediaItemListRow(
            id: $mediaItem->id,
            name: $mediaItem->name,
            altText: $mediaItem->alt_text,
            folderId: $mediaItem->folder_id,
            folderName: $mediaItem->folder?->name,
            url: $this->storage->url($mediaItem->disk, $mediaItem->path),
            thumbnailUrl: $this->storage->url($mediaItem->disk, $mediaItem->thumbnail_path),
            sizeBytes: $mediaItem->size_bytes + $mediaItem->thumbnail_size_bytes,
            width: $mediaItem->width,
            height: $mediaItem->height,
            usages: array_values($mediaItem->usages->map(fn (MediaUsage $usage): array => ['usable_type' => $usage->usable_type, 'label' => $usage->label])->all()),
            createdAt: $mediaItem->created_at?->toIso8601String() ?? '',
        );
    }
}
