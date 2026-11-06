<?php

namespace App\Application\Media\UseCases\ListMediaItems;

use App\Application\Concerns\DateRange;
use App\Application\Media\MediaUsageFilter;

/**
 * The library lists newest first and offers no other order, so it has no sort.
 */
final class ListMediaItemsCriteria
{
    /**
     * The folder filter in force for the images in no folder.
     */
    public const string NO_FOLDER = 'none';

    /**
     * @param  string  $search  already trimmed; an empty string means "do not search"
     * @param  string|null  $folder  a folder's id, NO_FOLDER, or null for every image
     * @param  DateRange  $createdAt  the days the image was uploaded between; empty means "any day"
     */
    public function __construct(
        public readonly string $search,
        public readonly ?string $folder,
        public readonly ?MediaUsageFilter $usage,
        public readonly DateRange $createdAt,
        public readonly int $perPage,
    ) {}

    /**
     * The keys here are what the frontend reads back as the filters in force.
     *
     * @return array{search: string, folder: string|null, usage: string|null, created_from: string|null, created_to: string|null}
     */
    public function toFilters(): array
    {
        return [
            'search' => $this->search,
            'folder' => $this->folder,
            'usage' => $this->usage?->value,
            ...$this->createdAt->toFilters(),
        ];
    }
}
