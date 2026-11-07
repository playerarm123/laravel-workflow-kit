<?php

namespace App\Application\Media\UseCases\MoveMediaItems;

use Spatie\LaravelData\Data;

final class MoveMediaItemsCommand extends Data
{
    /**
     * @param  list<string>  $ids  the images to move
     * @param  string|null  $folderId  the folder they go to; null takes them out of every folder
     */
    public function __construct(
        public readonly array $ids,
        public readonly ?string $folderId,
    ) {}
}
