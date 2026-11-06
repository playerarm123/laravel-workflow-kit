<?php

namespace App\Infra\Media;

use App\Application\Media\MediaStorage;
use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Override;
use RuntimeException;
use Throwable;

/**
 * MediaStorage built on Intervention Image with the GD driver (deployment.md). Every image is
 * turned the right way up by its EXIF orientation, scaled down to fit the longest side config
 * allows and stored as webp, which also drops the EXIF block with its GPS position. The
 * thumbnail is a square crop. Files are named by a random key, never the name a user typed, and
 * sit under config('media.directory') on the disk config('media.disk') names.
 */
class InterventionMediaStorage implements MediaStorage
{
    #[Override]
    public function store(UploadedFile $image): MediaFile
    {
        $diskName = (string) config('media.disk');
        $disk = Storage::disk($diskName);
        $key = config('media.directory').'/'.Str::random(40);
        $path = "{$key}.webp";
        $thumbnailPath = "{$key}_thumb.webp";

        try {
            $manager = new ImageManager(GdDriver::class, autoOrientation: true, strip: true);
            $original = $manager->decodePath($image->getRealPath())
                ->scaleDown((int) config('media.max_side'), (int) config('media.max_side'));
            $width = $original->width();
            $height = $original->height();
            $encoded = $original->encode(new WebpEncoder(quality: (int) config('media.quality')))->toString();
            $this->put($disk, $path, $encoded);

            $thumbnail = $original
                ->cover((int) config('media.thumbnail.size'), (int) config('media.thumbnail.size'))
                ->encode(new WebpEncoder(quality: (int) config('media.thumbnail.quality')))
                ->toString();
            $this->put($disk, $thumbnailPath, $thumbnail);
        } catch (Throwable $e) {
            $disk->delete([$path, $thumbnailPath]);

            throw new RuntimeException("The image [{$image->getClientOriginalName()}] could not be stored.", previous: $e);
        }

        return MediaFile::from(
            disk: $diskName,
            path: $path,
            thumbnailPath: $thumbnailPath,
            mimeType: 'image/webp',
            sizeBytes: strlen($encoded),
            thumbnailSizeBytes: strlen($thumbnail),
            width: $width,
            height: $height,
        );
    }

    #[Override]
    public function delete(MediaFile $file): void
    {
        Storage::disk($file->disk())->delete([$file->path(), $file->thumbnailPath()]);
    }

    #[Override]
    public function url(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }

    /**
     * A disk set to `throw => false` answers a failed write with false, so it is checked here.
     */
    private function put(Filesystem $disk, string $path, string $contents): void
    {
        if (! $disk->put($path, $contents)) {
            throw new RuntimeException("The file [{$path}] could not be written.");
        }
    }
}
