<?php

require_once __DIR__.'/../RepositoryContract.php';

use App\Domain\Media\MediaItem\Exceptions\MediaItemNotFoundException;
use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Media\MediaItem\MediaItemRepository;
use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use App\Models\MediaUsage as MediaUsageModel;
use Illuminate\Support\Facades\DB;

function eloquentMediaItemRepositoryItem(?string $folderId = null): MediaItemEntity
{
    $key = fake()->uuid();

    return MediaItemEntity::create(
        id: fake()->uuid(),
        folderId: $folderId,
        name: 'Team photo',
        file: MediaFile::from('public', "media/{$key}.webp", "media/{$key}_thumb.webp", 'image/webp', 2_000, 200, 1200, 900),
    );
}

repositoryContract(
    repository: MediaItemRepository::class,
    model: MediaItem::class,
    make: fn (): MediaItemEntity => eloquentMediaItemRepositoryItem(),
    change: function (MediaItemEntity $entity) {
        $entity->changeInfos('New name', 'A description');
        $entity->moveToFolder(MediaFolder::factory()->create()->id);
    },
    unwritable: fn (): MediaItemEntity => eloquentMediaItemRepositoryItem(fake()->uuid()),
    corrupt: fn (MediaItemEntity $entity) => DB::table('media_items')->where('id', $entity->id())->update(['mime_type' => 'text/plain']),
);

describe('delete()', function () {
    it('deletes the row and everything the aggregate owns', function () {
        $item = MediaItem::factory()->create();
        MediaUsageModel::factory()->create(['media_item_id' => $item->id]);
        $repo = app(MediaItemRepository::class);

        $repo->delete($repo->getById($item->id));

        $this->assertModelMissing($item);
        $this->assertDatabaseMissing('media_usages', ['media_item_id' => $item->id]);
    });

    it('throws not found from delete when the row no longer exists', function () {
        $item = eloquentMediaItemRepositoryItem();

        expect(fn () => app(MediaItemRepository::class)->delete($item))->toThrow(MediaItemNotFoundException::class);
    });
});

describe('many()', function () {
    it('reads the images of the ids, leaving out an id that names none', function () {
        $item = MediaItem::factory()->create();
        $repo = app(MediaItemRepository::class);

        expect(array_map(fn (MediaItemEntity $found): string => $found->id(), $repo->many([$item->id, fake()->uuid()])))->toBe([$item->id])
            ->and($repo->many([]))->toBe([]);
    });
});

describe('attachUsage() and detachUsage()', function () {
    it('records a usage once, taking a new label, and forgets it', function () {
        $item = MediaItem::factory()->create();
        $repo = app(MediaItemRepository::class);
        $profileId = '01a15500-0000-7000-8000-00000000b001';

        $repo->attachUsage('01a15500-0000-7000-8000-00000000a001', $item->id, MediaUsage::from('profile', $profileId, 'Jane'));
        $repo->attachUsage('01a15500-0000-7000-8000-00000000a002', $item->id, MediaUsage::from('profile', $profileId, 'Jane Doe'));

        expect($repo->usagesOf($item->id))->toHaveCount(1)
            ->and($repo->usagesOf($item->id)[0]->label())->toBe('Jane Doe');
        $this->assertDatabaseHas('media_usages', ['id' => '01a15500-0000-7000-8000-00000000a001', 'label' => 'Jane Doe']);

        $repo->detachUsage($item->id, MediaUsage::from('profile', $profileId, ''));
        $repo->detachUsage($item->id, MediaUsage::from('profile', '01a15500-0000-7000-8000-00000000b002', ''));

        expect($repo->usagesOf($item->id))->toBe([]);
    });
});
