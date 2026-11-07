<?php

require_once __DIR__.'/../RepositoryContract.php';

use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderRepositoryException;
use App\Domain\Media\MediaFolder\MediaFolderEntity;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Models\MediaFolder;
use App\Models\MediaItem;

function eloquentMediaFolderRepositoryFolder(?string $name = null): MediaFolderEntity
{
    return MediaFolderEntity::create(
        id: fake()->uuid(),
        name: $name ?? 'Folder '.fake()->unique()->numberBetween(1, 99999),
    );
}

repositoryContract(
    repository: MediaFolderRepository::class,
    model: MediaFolder::class,
    make: fn (): MediaFolderEntity => eloquentMediaFolderRepositoryFolder(),
    change: fn (MediaFolderEntity $entity) => $entity->rename('Renamed'),
    unwritable: fn (): MediaFolderEntity => eloquentMediaFolderRepositoryFolder(MediaFolder::factory()->create()->name),
    corrupt: fn (MediaFolderEntity $entity) => dropRepositoryContractColumn('media_folders', 'name'),
);

describe('delete()', function () {
    it('deletes the row and everything the aggregate owns', function () {
        $folder = MediaFolder::factory()->create();
        $item = MediaItem::factory()->inFolder($folder)->create();
        $repo = app(MediaFolderRepository::class);

        $repo->delete($repo->getById($folder->id));

        $this->assertModelMissing($folder);
        expect($item->fresh()?->folder_id)->toBeNull();
    });

    it('throws not found from delete when the row no longer exists', function () {
        expect(fn () => app(MediaFolderRepository::class)->delete(eloquentMediaFolderRepositoryFolder()))->toThrow(MediaFolderNotFoundException::class);
    });
});

describe('nameTaken()', function () {
    it('finds a name another folder has, ignoring case and the blanks around it', function () {
        $folder = MediaFolder::factory()->create(['name' => 'Logos']);
        $repo = app(MediaFolderRepository::class);

        expect($repo->nameTaken(' logos '))->toBeTrue()
            ->and($repo->nameTaken('Logos', $folder->id))->toBeFalse()
            ->and($repo->nameTaken('Banners'))->toBeFalse();
    });

    it('is backed by the database, which refuses a second folder of the same name', function () {
        $folder = MediaFolder::factory()->create(['name' => 'Logos']);

        expect(fn () => app(MediaFolderRepository::class)->save(eloquentMediaFolderRepositoryFolder($folder->name)))
            ->toThrow(MediaFolderRepositoryException::class);
    });
});

describe('countItems()', function () {
    it('counts the images in the folder', function () {
        $folder = MediaFolder::factory()->create();
        MediaItem::factory()->count(2)->inFolder($folder)->create();
        MediaItem::factory()->create();

        expect(app(MediaFolderRepository::class)->countItems($folder->id))->toBe(2);
    });
});
