<?php

use App\Application\Media\UseCases\DeleteMediaFolderHandler;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->handler = app(DeleteMediaFolderHandler::class);
});

describe('DeleteMediaFolderHandler', function () {
    it('deletes the folder and leaves its images in no folder', function () {
        $folder = MediaFolder::factory()->create();
        $items = MediaItem::factory()->count(2)->inFolder($folder)->create();

        ($this->handler)($folder->id);

        $this->assertModelMissing($folder);
        expect($items->map(fn (MediaItem $item) => $item->fresh()?->folder_id)->all())->toBe([null, null]);
        $this->assertDatabaseHas('audit_entries', ['event' => 'media_folder.deleted', 'subject_id' => $folder->id, 'data->item_count' => 2]);
    });

    it('does not find a folder that is gone', function () {
        expect(fn () => ($this->handler)((string) Str::uuid()))->toThrow(MediaFolderNotFoundException::class);
    });
});
