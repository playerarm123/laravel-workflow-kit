<?php

require_once __DIR__.'/../ListQueryContract.php';

use App\Application\Concerns\DateRange;
use App\Application\Media\MediaUsageFilter;
use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsCriteria;
use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsQuery;
use App\Application\Media\UseCases\ListMediaItems\MediaItemListRow;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use App\Models\MediaUsage;

function eloquentListMediaItemsCriteria(string $search = '', int $perPage = 10, ?string $folder = null, ?MediaUsageFilter $usage = null): ListMediaItemsCriteria
{
    return new ListMediaItemsCriteria(search: $search, folder: $folder, usage: $usage, createdAt: DateRange::fromInput('', ''), perPage: $perPage);
}

/**
 * @return list<string>
 */
function eloquentListMediaItemsNames(ListMediaItemsCriteria $criteria): array
{
    return array_map(fn (MediaItemListRow $row): string => $row->name, app(ListMediaItemsQuery::class)->paginate($criteria)->items());
}

listQueryContract(
    query: ListMediaItemsQuery::class,
    criteria: fn (array $overrides): ListMediaItemsCriteria => eloquentListMediaItemsCriteria(search: $overrides['search'] ?? '', perPage: $overrides['perPage'] ?? 10),
    seed: fn (int $count) => MediaItem::factory()->count($count)->create(['created_at' => '2026-10-01 09:00:00']),
    break: fn () => breakListQueryColumn('media_items', 'created_at'),
    seedMatching: fn (string $text) => MediaItem::factory()->create(['name' => "Image {$text} here"]),
);

describe('EloquentListMediaItemsQuery', function () {
    it('lists the newest first', function () {
        MediaItem::factory()->create(['name' => 'older', 'created_at' => '2026-10-01 09:00:00']);
        MediaItem::factory()->create(['name' => 'newer', 'created_at' => '2026-10-02 09:00:00']);

        expect(eloquentListMediaItemsNames(eloquentListMediaItemsCriteria()))->toBe(['newer', 'older']);
    });

    it('searches the alt text too', function () {
        MediaItem::factory()->create(['name' => 'a', 'alt_text' => 'facing front']);
        MediaItem::factory()->create(['name' => 'b']);

        expect(eloquentListMediaItemsNames(eloquentListMediaItemsCriteria(search: 'facing front')))->toBe(['a']);
    });

    it('keeps the images of one folder, or of none', function () {
        $folder = MediaFolder::factory()->create();
        MediaItem::factory()->inFolder($folder)->create(['name' => 'in a folder']);
        MediaItem::factory()->create(['name' => 'in none']);

        expect(eloquentListMediaItemsNames(eloquentListMediaItemsCriteria(folder: $folder->id)))->toBe(['in a folder'])
            ->and(eloquentListMediaItemsNames(eloquentListMediaItemsCriteria(folder: ListMediaItemsCriteria::NO_FOLDER)))->toBe(['in none']);
    });

    it('keeps the images in use, or those nothing uses', function () {
        $used = MediaItem::factory()->create(['name' => 'used']);
        MediaUsage::factory()->create(['media_item_id' => $used->id]);
        MediaItem::factory()->create(['name' => 'unused']);

        expect(eloquentListMediaItemsNames(eloquentListMediaItemsCriteria(usage: MediaUsageFilter::InUse)))->toBe(['used'])
            ->and(eloquentListMediaItemsNames(eloquentListMediaItemsCriteria(usage: MediaUsageFilter::Unused)))->toBe(['unused']);
    });

    it('sends the folder, the urls and where the image is used', function () {
        $folder = MediaFolder::factory()->create(['name' => 'Team']);
        $item = MediaItem::factory()->inFolder($folder)->create(['path' => 'media/x.webp', 'thumbnail_path' => 'media/x_thumb.webp', 'size_bytes' => 1_000, 'thumbnail_size_bytes' => 24]);
        MediaUsage::factory()->create(['media_item_id' => $item->id, 'usable_type' => 'profile', 'label' => 'Jane Doe']);

        expect(app(ListMediaItemsQuery::class)->paginate(eloquentListMediaItemsCriteria())->items()[0]->toArray())->toMatchArray([
            'folder_id' => $folder->id,
            'folder_name' => 'Team',
            'url' => Storage::disk('public')->url('media/x.webp'),
            'thumbnail_url' => Storage::disk('public')->url('media/x_thumb.webp'),
            'size_bytes' => 1_024,
            'usages' => [['usable_type' => 'profile', 'label' => 'Jane Doe']],
        ]);
    });
});
