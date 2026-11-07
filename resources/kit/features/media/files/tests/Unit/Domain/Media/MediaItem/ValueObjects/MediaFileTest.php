<?php

use App\Domain\Media\MediaItem\Exceptions\InvalidMediaFileException;
use App\Domain\Media\MediaItem\ValueObjects\MediaFile;

/**
 * @param  array<string, mixed>  $overrides
 */
function mediaFileOf(array $overrides = []): MediaFile
{
    $values = [...['disk' => 'public', 'path' => 'media/a.webp', 'thumbnailPath' => 'media/a_thumb.webp', 'mimeType' => 'image/webp', 'sizeBytes' => 1_000, 'thumbnailSizeBytes' => 100, 'width' => 800, 'height' => 600], ...$overrides];

    return MediaFile::from(...$values);
}

/**
 * @see MediaFile
 */
describe('MediaFile', function () {
    describe('from()', function () {
        it('holds the stored file and counts the original with its thumbnail', function () {
            $file = mediaFileOf();

            expect($file->disk())->toBe('public')
                ->and($file->path())->toBe('media/a.webp')
                ->and($file->thumbnailPath())->toBe('media/a_thumb.webp')
                ->and($file->mimeType())->toBe('image/webp')
                ->and($file->width())->toBe(800)
                ->and($file->height())->toBe(600)
                ->and($file->totalBytes())->toBe(1_100);
        });

        it('refuses a value that is not a stored picture', function (array $overrides, int $code) {
            expect(fn () => mediaFileOf($overrides))->toThrow(fn (InvalidMediaFileException $e) => expect($e->getCode())->toBe($code));
        })->with([
            'no disk' => [['disk' => ' '], InvalidMediaFileException::EMPTY_DISK],
            'no path' => [['path' => ''], InvalidMediaFileException::EMPTY_PATH],
            'no thumbnail' => [['thumbnailPath' => ''], InvalidMediaFileException::EMPTY_PATH],
            'not an image' => [['mimeType' => 'application/pdf'], InvalidMediaFileException::UNSUPPORTED_MIME],
            'empty file' => [['sizeBytes' => 0], InvalidMediaFileException::INVALID_SIZE],
            'empty thumbnail' => [['thumbnailSizeBytes' => 0], InvalidMediaFileException::INVALID_SIZE],
            'no width' => [['width' => 0], InvalidMediaFileException::INVALID_DIMENSIONS],
        ]);
    });

    describe('equals()', function () {
        it('is equal to a file at the same paths', function () {
            expect(mediaFileOf()->equals(mediaFileOf(['sizeBytes' => 5])))->toBeTrue();
        });

        it('differs from a file at another path', function () {
            expect(mediaFileOf()->equals(mediaFileOf(['path' => 'media/b.webp'])))->toBeFalse();
        });
    });
});
