<?php

namespace App\Application\Media\UseCases\ListMediaItems;

use Spatie\LaravelData\Data;

/**
 * The raw query string of the library page, untouched: the handler settles every value.
 */
final class ListMediaItemsCommand extends Data
{
    /**
     * @param  string  $folder  a folder's id, `none` for the images in no folder, or empty for all
     */
    public function __construct(
        public readonly string $search = '',
        public readonly string $folder = '',
        public readonly string $usage = '',
        public readonly string $createdFrom = '',
        public readonly string $createdTo = '',
        public readonly string $perPage = '',
    ) {}
}
