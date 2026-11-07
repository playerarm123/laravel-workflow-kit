<?php

use App\Application\Media\UseCases\MoveMediaItems\MoveMediaItemsCommand;
use App\Application\Media\UseCases\MoveMediaItems\MoveMediaItemsHandler;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->handler = app(MoveMediaItemsHandler::class);
});

describe('MoveMediaItemsHandler', function () {
    it('moves the images, skipping one already there or an id that names no image', function () {
        $folder = MediaFolder::factory()->create();
        $loose = MediaItem::factory()->create();
        $there = MediaItem::factory()->inFolder($folder)->create();

        $moved = ($this->handler)(new MoveMediaItemsCommand([$loose->id, $there->id, (string) Str::uuid()], $folder->id));

        expect($moved)->toBe(1)
            ->and($loose->fresh()?->folder_id)->toBe($folder->id);
        $this->assertDatabaseHas('audit_entries', ['event' => 'media_item.moved', 'subject_id' => $loose->id]);
        $this->assertDatabaseCount('audit_entries', 1);
    });

    it('takes images out of every folder', function () {
        $item = MediaItem::factory()->inFolder(MediaFolder::factory()->create())->create();

        expect(($this->handler)(new MoveMediaItemsCommand([$item->id], null)))->toBe(1)
            ->and($item->fresh()?->folder_id)->toBeNull();
    });

    it('moves nothing when every image is already there', function () {
        $item = MediaItem::factory()->create();

        expect(($this->handler)(new MoveMediaItemsCommand([$item->id], null)))->toBe(0);
        $this->assertDatabaseCount('audit_entries', 0);
    });

    it('refuses a folder that is gone', function () {
        expect(fn () => ($this->handler)(new MoveMediaItemsCommand([], (string) Str::uuid())))->toThrow(MediaFolderNotFoundException::class);
    });
});
