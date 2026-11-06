<?php

use App\Application\Media\UseCases\ChangeMediaItemInfos\ChangeMediaItemInfosCommand;
use App\Application\Media\UseCases\ChangeMediaItemInfos\ChangeMediaItemInfosHandler;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Domain\Media\MediaItem\Exceptions\MediaItemNotFoundException;
use App\Domain\Media\MediaItem\MediaItemRepository;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->handler = app(ChangeMediaItemInfosHandler::class);
});

describe('ChangeMediaItemInfosHandler', function () {
    it('renames the image, sets its alt text and moves it to a folder', function () {
        $item = MediaItem::factory()->create();
        $folder = MediaFolder::factory()->create();

        ($this->handler)(new ChangeMediaItemInfosCommand($item->id, 'New name', 'A description', $folder->id));

        $saved = app(MediaItemRepository::class)->getById($item->id);
        expect($saved->name())->toBe('New name')
            ->and($saved->altText())->toBe('A description')
            ->and($saved->folderId())->toBe($folder->id);
        $this->assertDatabaseHas('audit_entries', ['event' => 'media_item.changed', 'subject_id' => $item->id, 'data->folder_id' => $folder->id]);
    });

    it('does not find an image that is gone', function () {
        expect(fn () => ($this->handler)(new ChangeMediaItemInfosCommand((string) Str::uuid(), 'x', null, null)))->toThrow(MediaItemNotFoundException::class);
    });

    it('refuses a folder that is gone', function () {
        $item = MediaItem::factory()->create();

        expect(fn () => ($this->handler)(new ChangeMediaItemInfosCommand($item->id, 'x', null, (string) Str::uuid())))
            ->toThrow(MediaFolderNotFoundException::class);
    });
});
