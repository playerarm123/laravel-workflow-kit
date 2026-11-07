<?php

namespace App\Application\Media\UseCases\ListMediaItems;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;

final class ListMediaItemsResult extends Data
{
    /**
     * @param  LengthAwarePaginator<int, MediaItemListRow>  $mediaItems
     * @param  array{search: string, folder: string|null, usage: string|null, created_from: string|null, created_to: string|null}  $filters
     */
    public function __construct(
        public readonly LengthAwarePaginator $mediaItems,
        public readonly array $filters,
    ) {}
}
