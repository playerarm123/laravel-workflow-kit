<?php

use App\Application\Media\MediaFolderOption;
use App\Infra\Media\EloquentMediaFolderDirectory;
use App\Models\MediaFolder;
use App\Models\MediaItem;

beforeEach(function () {
    $this->adapter = app(EloquentMediaFolderDirectory::class);
});

describe('EloquentMediaFolderDirectory', function () {
    describe('folders()', function () {
        it('lists the folders by name with how many images each holds', function () {
            $logos = MediaFolder::factory()->create(['name' => 'Logos']);
            MediaFolder::factory()->create(['name' => 'Banners']);
            MediaItem::factory()->count(2)->inFolder($logos)->create();
            MediaItem::factory()->create();

            expect(array_map(fn (MediaFolderOption $folder): array => [$folder->name, $folder->itemCount], $this->adapter->folders()))
                ->toBe([['Banners', 0], ['Logos', 2]]);
        });

        it('is empty for a library with no folders', function () {
            expect($this->adapter->folders())->toBe([]);
        });
    });
});
