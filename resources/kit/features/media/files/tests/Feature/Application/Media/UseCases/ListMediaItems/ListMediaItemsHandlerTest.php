<?php

use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsCommand;
use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsHandler;
use App\Application\Media\UseCases\ListMediaItems\MediaItemListRow;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use App\Models\MediaUsage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->handler = app(ListMediaItemsHandler::class);
});

/**
 * @return list<string>
 */
function listMediaItemsHandlerIds(ListMediaItemsCommand $command): array
{
    return array_map(fn (MediaItemListRow $row): string => $row->id, app(ListMediaItemsHandler::class)($command)->mediaItems->items());
}

describe('ListMediaItemsHandler', function () {
    it('lists the images, 25 to a page', function () {
        MediaItem::factory()->create();

        $result = ($this->handler)(new ListMediaItemsCommand);

        expect($result->mediaItems->total())->toBe(1)
            ->and($result->mediaItems->perPage())->toBe(25)
            ->and($result->filters)->toBe(['search' => '', 'folder' => null, 'usage' => null, 'created_from' => null, 'created_to' => null]);
    });

    it('filters by a folder, by no folder and by usage, and echoes them', function () {
        $folder = MediaFolder::factory()->create();
        $inFolder = MediaItem::factory()->inFolder($folder)->create();
        $loose = MediaItem::factory()->create();
        MediaUsage::factory()->create(['media_item_id' => $loose->id]);

        expect(listMediaItemsHandlerIds(new ListMediaItemsCommand(folder: $folder->id)))->toBe([$inFolder->id])
            ->and(listMediaItemsHandlerIds(new ListMediaItemsCommand(folder: 'none')))->toBe([$loose->id])
            ->and(listMediaItemsHandlerIds(new ListMediaItemsCommand(usage: 'in_use')))->toBe([$loose->id])
            ->and(listMediaItemsHandlerIds(new ListMediaItemsCommand(usage: 'unused')))->toBe([$inFolder->id])
            ->and(($this->handler)(new ListMediaItemsCommand(folder: 'none', usage: 'unused'))->filters)->toMatchArray(['folder' => 'none', 'usage' => 'unused']);
    });

    it('searches the trimmed term and echoes it', function () {
        MediaItem::factory()->create(['name' => 'Company logo']);
        MediaItem::factory()->create(['name' => 'Team']);

        $result = ($this->handler)(new ListMediaItemsCommand(search: '  logo '));

        expect($result->mediaItems->total())->toBe(1)->and($result->filters['search'])->toBe('logo');
    });

    it('lists nothing for a folder that is gone', function () {
        MediaItem::factory()->create();

        expect(listMediaItemsHandlerIds(new ListMediaItemsCommand(folder: (string) Str::uuid())))->toBe([]);
    });

    it('falls back silently rather than failing on values it does not recognise', function () {
        $result = ($this->handler)(new ListMediaItemsCommand(folder: 'nope', usage: 'sometimes', createdFrom: 'yesterday', perPage: 'all'));

        expect($result->mediaItems->perPage())->toBe(25)
            ->and($result->filters)->toBe(['search' => '', 'folder' => null, 'usage' => null, 'created_from' => null, 'created_to' => null]);
    });

    it('honours every page size it offers', function (int $perPage) {
        expect(($this->handler)(new ListMediaItemsCommand(perPage: (string) $perPage))->mediaItems->perPage())->toBe($perPage);
    })->with([10, 25, 50]);
});
