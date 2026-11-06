<?php

use App\Application\Media\UseCases\DeleteMediaItemHandler;
use App\Domain\Media\MediaItem\Exceptions\MediaItemInUseException;
use App\Domain\Media\MediaItem\Exceptions\MediaItemNotFoundException;
use App\Models\MediaItem;
use App\Models\MediaUsage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
    $this->handler = app(DeleteMediaItemHandler::class);
});

describe('DeleteMediaItemHandler', function () {
    it('deletes an image nothing uses, with its files', function () {
        $item = MediaItem::factory()->create();
        Storage::disk('public')->put($item->path, 'x');
        Storage::disk('public')->put($item->thumbnail_path, 'x');

        ($this->handler)($item->id);

        $this->assertModelMissing($item);
        expect(Storage::disk('public')->allFiles())->toBe([]);
        $this->assertDatabaseHas('audit_entries', ['event' => 'media_item.deleted', 'subject_id' => $item->id]);
    });

    it('refuses an image something uses, and keeps its files', function () {
        $item = MediaItem::factory()->create();
        MediaUsage::factory()->create(['media_item_id' => $item->id]);
        Storage::disk('public')->put($item->path, 'x');

        expect(fn () => ($this->handler)($item->id))->toThrow(MediaItemInUseException::class);

        $this->assertModelExists($item);
        Storage::disk('public')->assertExists($item->path);
    });

    it('does not find an image that is gone', function () {
        expect(fn () => ($this->handler)((string) Str::uuid()))->toThrow(MediaItemNotFoundException::class);
    });
});
