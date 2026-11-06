<?php

namespace App\Application\Media\UseCases\UploadMediaItems;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Data;

final class UploadMediaItemsCommand extends Data
{
    /**
     * @param  string|null  $folderId  the folder to put them in; null puts them in none
     * @param  list<UploadedFile>  $images
     */
    public function __construct(
        public readonly ?string $folderId,
        public readonly array $images,
    ) {}
}
