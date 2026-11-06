<?php

use App\Application\Media\MediaQuota;
use App\Application\Media\MediaQuotaExceededException;
use App\Application\Media\MediaQuotaUsage;
use App\Application\Media\UseCases\UploadMediaItems\UploadMediaItemsCommand;
use App\Application\Media\UseCases\UploadMediaItems\UploadMediaItemsHandler;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Domain\Media\MediaItem\MediaItemRepository;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
    config(['media.disk' => 'public']);
});

describe('UploadMediaItemsHandler', function () {
    it('adds each image to the library, named after its file', function () {
        $folder = MediaFolder::factory()->create();

        $ids = app(UploadMediaItemsHandler::class)(new UploadMediaItemsCommand($folder->id, [
            UploadedFile::fake()->image('team photo.jpg', 600, 600),
            UploadedFile::fake()->image('.png', 100, 100),
        ]))->ids;

        $items = array_map(fn (string $id) => app(MediaItemRepository::class)->getById($id), $ids);

        expect($ids)->toHaveCount(2)
            ->and($items[0]->folderId())->toBe($folder->id)
            ->and($items[0]->name())->toBe('team photo')
            ->and($items[1]->name())->toBe('image');
        Storage::disk('public')->assertExists($items[0]->file()->path());
    });

    it('records each upload in the audit log', function () {
        $ids = app(UploadMediaItemsHandler::class)(new UploadMediaItemsCommand(null, [UploadedFile::fake()->image('a.jpg', 50, 50)]))->ids;

        $this->assertDatabaseHas('audit_entries', ['event' => 'media_item.uploaded', 'subject_type' => 'media_item', 'subject_id' => $ids[0], 'data->folder_id' => null]);
    });

    it('asks the quota for room for what it wrote, originals and thumbnails together', function () {
        $quota = new class implements MediaQuota
        {
            public ?int $asked = null;

            public function assertRoomFor(int $addedBytes): void
            {
                $this->asked = $addedBytes;
            }

            public function usage(): MediaQuotaUsage
            {
                return new MediaQuotaUsage(0, null);
            }
        };
        app()->instance(MediaQuota::class, $quota);

        $ids = app(UploadMediaItemsHandler::class)(new UploadMediaItemsCommand(null, [UploadedFile::fake()->image('a.jpg', 50, 50)]))->ids;

        expect($quota->asked)->toBe(app(MediaItemRepository::class)->getById($ids[0])->file()->totalBytes());
    });

    it('removes the files it wrote when the quota refuses them, and saves nothing', function () {
        app()->instance(MediaQuota::class, new class implements MediaQuota
        {
            public function assertRoomFor(int $addedBytes): void
            {
                throw MediaQuotaExceededException::exceeded(10, $addedBytes, 10);
            }

            public function usage(): MediaQuotaUsage
            {
                return new MediaQuotaUsage(10, 10);
            }
        });

        expect(fn () => app(UploadMediaItemsHandler::class)(new UploadMediaItemsCommand(null, [UploadedFile::fake()->image('a.jpg', 50, 50)])))
            ->toThrow(MediaQuotaExceededException::class);

        expect(Storage::disk('public')->allFiles())->toBe([])
            ->and(MediaItem::query()->count())->toBe(0);
    });

    it('refuses a folder that is gone', function () {
        expect(fn () => app(UploadMediaItemsHandler::class)(new UploadMediaItemsCommand((string) Str::uuid(), [UploadedFile::fake()->image('a.jpg', 50, 50)])))
            ->toThrow(MediaFolderNotFoundException::class);
    });
});
