<?php

use App\Domain\Media\MediaItem\Exceptions\InvalidMediaUsageException;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;

/**
 * @see MediaUsage
 */
describe('MediaUsage', function () {
    describe('from()', function () {
        it('holds the place an image is used, trimmed', function () {
            $usage = MediaUsage::from('profile', ' p-1 ', ' Jane Doe ');

            expect($usage->usableType())->toBe('profile')
                ->and($usage->usableId())->toBe('p-1')
                ->and($usage->label())->toBe('Jane Doe');
        });

        it('refuses a type that is not a snake_case key', function (string $type) {
            expect(fn () => MediaUsage::from($type, 'p-1', 'label'))->toThrow(InvalidMediaUsageException::class);
        })->with(['', 'Profile', 'App\\Models\\Profile']);

        it('refuses an empty id', function () {
            expect(fn () => MediaUsage::from('profile', ' ', 'label'))->toThrow(InvalidMediaUsageException::class);
        });
    });

    describe('equals()', function () {
        it('is the same place whatever the label says', function () {
            expect(MediaUsage::from('profile', 'p-1', 'a')->equals(MediaUsage::from('profile', 'p-1', 'b')))->toBeTrue();
        });

        it('differs for another thing', function () {
            expect(MediaUsage::from('profile', 'p-1', 'a')->equals(MediaUsage::from('profile', 'p-2', 'a')))->toBeFalse();
        });
    });
});
