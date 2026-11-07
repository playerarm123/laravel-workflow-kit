<?php

namespace App\Application\Media\UseCases\UploadMediaItems;

use Spatie\LaravelData\Data;

final class UploadMediaItemsResult extends Data
{
    /**
     * @param  list<string>  $ids  the new images, in the order they were uploaded
     */
    public function __construct(
        public readonly array $ids,
    ) {}
}
