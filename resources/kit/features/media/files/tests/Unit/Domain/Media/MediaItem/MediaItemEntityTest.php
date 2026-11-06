<?php

use App\Domain\Media\MediaItem\Exceptions\InvalidMediaItemNameException;
use App\Domain\Media\MediaItem\Exceptions\MediaItemInUseException;
use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;

function mediaItemEntityFile(): MediaFile
{
    return MediaFile::from('public', 'media/c-1/a.webp', 'media/c-1/a_thumb.webp', 'image/webp', 1_000, 100, 800, 600);
}

function mediaItemEntity(): MediaItemEntity
{
    return MediaItemEntity::create('item-1', 'folder-1', 'Team photo', mediaItemEntityFile());
}

/**
 * @see MediaItemEntity
 */
describe('MediaItemEntity', function () {
    describe('create()', function () {
        it('builds an image with a trimmed name and no alt text', function () {
            $item = MediaItemEntity::create('item-1', null, '  Team photo  ', mediaItemEntityFile());

            expect($item->id())->toBe('item-1')
                ->and($item->folderId())->toBeNull()
                ->and($item->name())->toBe('Team photo')
                ->and($item->altText())->toBeNull()
                ->and($item->file()->equals(mediaItemEntityFile()))->toBeTrue();
        });

        it('refuses a blank name or one longer than 150 characters', function (string $name) {
            expect(fn () => MediaItemEntity::create('item-1', null, $name, mediaItemEntityFile()))->toThrow(InvalidMediaItemNameException::class);
        })->with(['   ', str_repeat('ก', 151)]);
    });

    describe('reconstitute()', function () {
        it('rebuilds the entity exactly as it was stored', function () {
            $item = MediaItemEntity::reconstitute('item-1', 'folder-1', 'name', 'alt', mediaItemEntityFile());

            expect($item->folderId())->toBe('folder-1')
                ->and($item->name())->toBe('name')
                ->and($item->altText())->toBe('alt')
                ->and($item->file()->path())->toBe('media/c-1/a.webp');
        });
    });

    describe('changeInfos()', function () {
        it('takes the trimmed name and alt text, keeping a blank alt text as none', function () {
            $item = mediaItemEntity();

            $item->changeInfos(' Team ', ' Facing front ');

            expect($item->name())->toBe('Team')->and($item->altText())->toBe('Facing front');

            $item->changeInfos('Team', '  ');

            expect($item->altText())->toBeNull();
        });

        it('throws InvalidMediaItemNameException for a blank name', function () {
            expect(fn () => mediaItemEntity()->changeInfos('', null))->toThrow(InvalidMediaItemNameException::class);
        });
    });

    describe('moveToFolder()', function () {
        it('moves the image to a folder, or out of every folder', function () {
            $item = mediaItemEntity();

            $item->moveToFolder('folder-2');
            expect($item->folderId())->toBe('folder-2');

            $item->moveToFolder(null);
            expect($item->folderId())->toBeNull();
        });
    });

    describe('assertNotInUse()', function () {
        it('allows an image nothing uses', function () {
            mediaItemEntity()->assertNotInUse([]);
        })->throwsNoExceptions();

        it('throws MediaItemInUseException carrying the usages, with no label in its context', function () {
            $usage = MediaUsage::from('profile', 'p-1', 'Jane Doe');

            expect(fn () => mediaItemEntity()->assertNotInUse([$usage]))->toThrow(
                function (MediaItemInUseException $e) use ($usage) {
                    expect($e->usages())->toBe([$usage])
                        ->and($e->context())->toBe(['media_item_id' => 'item-1', 'usable_types' => ['profile']]);
                },
            );
        });
    });
});
