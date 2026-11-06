<?php

namespace App\Application\Media\UseCases\ListMediaItems;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One image of the library, in the shape the grid and the picker render it.
 *
 * Deliberately a plain class rather than a spatie/laravel-data object: a Data item type
 * makes the library treat the paginator carrying it as a DataPaginator and reshape the
 * payload into {data, links, meta}, which is not the envelope the frontend reads.
 *
 * toArray() is what puts the keys on the wire, so it — not the property names — is the
 * contract the frontend row type mirrors. The urls are built on the server from the row's own
 * disk, so a page never assembles a storage path itself (deployment.md).
 *
 * @implements Arrayable<string, mixed>
 */
final class MediaItemListRow implements Arrayable
{
    /**
     * @param  int  $sizeBytes  the original and its thumbnail together, as the quota counts them
     * @param  list<array{usable_type: string, label: string}>  $usages  where the image is used, oldest first
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $altText,
        public readonly ?string $folderId,
        public readonly ?string $folderName,
        public readonly string $url,
        public readonly string $thumbnailUrl,
        public readonly int $sizeBytes,
        public readonly int $width,
        public readonly int $height,
        public readonly array $usages,
        public readonly string $createdAt,
    ) {}

    /**
     * @return array{id: string, name: string, alt_text: string|null, folder_id: string|null, folder_name: string|null, url: string, thumbnail_url: string, size_bytes: int, width: int, height: int, usages: list<array{usable_type: string, label: string}>, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'alt_text' => $this->altText,
            'folder_id' => $this->folderId,
            'folder_name' => $this->folderName,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
            'size_bytes' => $this->sizeBytes,
            'width' => $this->width,
            'height' => $this->height,
            'usages' => $this->usages,
            'created_at' => $this->createdAt,
        ];
    }
}
