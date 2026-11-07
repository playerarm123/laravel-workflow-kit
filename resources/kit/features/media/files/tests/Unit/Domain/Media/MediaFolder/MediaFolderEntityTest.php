<?php

use App\Domain\Media\MediaFolder\Exceptions\InvalidMediaFolderNameException;
use App\Domain\Media\MediaFolder\MediaFolderEntity;

/**
 * @see MediaFolderEntity
 */
describe('MediaFolderEntity', function () {
    describe('create()', function () {
        it('builds a folder with a trimmed name', function () {
            $folder = MediaFolderEntity::create('folder-1', ' Team ');

            expect($folder->id())->toBe('folder-1')
                ->and($folder->name())->toBe('Team');
        });

        it('refuses a blank name or one longer than 60 characters', function (string $name) {
            expect(fn () => MediaFolderEntity::create('folder-1', $name))->toThrow(InvalidMediaFolderNameException::class);
        })->with(['', str_repeat('ก', 61)]);
    });

    describe('reconstitute()', function () {
        it('rebuilds the entity exactly as it was stored', function () {
            $folder = MediaFolderEntity::reconstitute('folder-1', 'Logos');

            expect($folder->name())->toBe('Logos');
        });
    });

    describe('rename()', function () {
        it('takes the trimmed name', function () {
            $folder = MediaFolderEntity::create('folder-1', 'Team');

            $folder->rename(' Services ');

            expect($folder->name())->toBe('Services');
        });

        it('throws InvalidMediaFolderNameException for a blank name', function () {
            expect(fn () => MediaFolderEntity::create('folder-1', 'Team')->rename(' '))->toThrow(InvalidMediaFolderNameException::class);
        });
    });
});
