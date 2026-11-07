<?php

namespace App\Application\Media;

use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use Illuminate\Http\UploadedFile;

/**
 * Where the library's images are kept. An adapter in App\Infra implements it and a service
 * provider binds the two (layers.md). It sits in the application, not the domain, because it
 * takes the framework's UploadedFile; the domain sees only the MediaFile it hands back.
 */
interface MediaStorage
{
    /**
     * Writes a resized copy of $image and its thumbnail, and returns what was written. A write
     * that fails half way removes what it wrote before it throws.
     */
    public function store(UploadedFile $image): MediaFile;

    /**
     * Removes both files, such as those of a deleted image or of one the database then refused.
     */
    public function delete(MediaFile $file): void;

    /**
     * The url a browser loads the file at $path of disk $disk from. It takes the raw columns, so a
     * list can build it without rebuilding a MediaFile.
     */
    public function url(string $disk, string $path): string;
}
