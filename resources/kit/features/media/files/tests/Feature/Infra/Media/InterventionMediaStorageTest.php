<?php

use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use App\Infra\Media\InterventionMediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    config(['media.disk' => 'public']);
    $this->adapter = app(InterventionMediaStorage::class);
});

describe('InterventionMediaStorage', function () {
    describe('store()', function () {
        it('scales a large image down to webp and writes a square thumbnail', function () {
            $file = $this->adapter->store(UploadedFile::fake()->image('team.jpg', 3000, 1500));

            expect($file->disk())->toBe('public')
                ->and($file->mimeType())->toBe('image/webp')
                ->and($file->width())->toBe(2000)
                ->and($file->height())->toBe(1000)
                ->and($file->path())->toStartWith('media/')->toEndWith('.webp')
                ->and($file->thumbnailPath())->toEndWith('_thumb.webp')
                ->and($file->sizeBytes())->toBe(Storage::disk('public')->size($file->path()))
                ->and($file->thumbnailSizeBytes())->toBe(Storage::disk('public')->size($file->thumbnailPath()));

            [$thumbnailWidth, $thumbnailHeight] = getimagesizefromstring(Storage::disk('public')->get($file->thumbnailPath()));
            expect([$thumbnailWidth, $thumbnailHeight])->toBe([400, 400]);
        });

        it('keeps a small image at its size', function () {
            $file = $this->adapter->store(UploadedFile::fake()->image('logo.png', 300, 200));

            expect([$file->width(), $file->height()])->toBe([300, 200]);
        });

        it('throws and leaves nothing behind for a file it cannot decode', function () {
            expect(fn () => $this->adapter->store(UploadedFile::fake()->createWithContent('broken.jpg', 'not an image')))
                ->toThrow(RuntimeException::class);

            expect(Storage::disk('public')->allFiles())->toBe([]);
        });
    });

    describe('delete()', function () {
        it('removes the image and its thumbnail', function () {
            $file = $this->adapter->store(UploadedFile::fake()->image('a.jpg', 100, 100));

            $this->adapter->delete($file);

            expect(Storage::disk('public')->allFiles())->toBe([]);
        });

        it('passes quietly over files that are already gone', function () {
            $this->adapter->delete(MediaFile::from('public', 'media/gone.webp', 'media/gone_thumb.webp', 'image/webp', 1, 1, 1, 1));
        })->throwsNoExceptions();
    });

    describe('url()', function () {
        it('is the disk\'s url of the path', function () {
            expect($this->adapter->url('public', 'media/a.webp'))->toBe(Storage::disk('public')->url('media/a.webp'));
        });

        it('fails for a disk config does not have', function () {
            expect(fn () => $this->adapter->url('missing-disk', 'media/a.webp'))->toThrow(InvalidArgumentException::class);
        });
    });
});
