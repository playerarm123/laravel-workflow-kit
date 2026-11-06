<?php

namespace App\Application\Media\UseCases\ChangeMediaItemInfos;

use Spatie\LaravelData\Data;

final class ChangeMediaItemInfosCommand extends Data
{
    /**
     * @param  string|null  $altText  null or blank means none
     * @param  string|null  $folderId  null takes the image out of every folder
     */
    public function __construct(
        public readonly string $mediaItemId,
        public readonly string $name,
        public readonly ?string $altText,
        public readonly ?string $folderId,
    ) {}
}
