<?php

use App\Infra\Media\UnlimitedMediaQuota;
use App\Models\MediaItem;

beforeEach(function () {
    $this->adapter = app(UnlimitedMediaQuota::class);
});

describe('UnlimitedMediaQuota', function () {
    describe('assertRoomFor()', function () {
        it('has room for any number of bytes', function () {
            MediaItem::factory()->create(['size_bytes' => PHP_INT_MAX >> 2]);

            $this->adapter->assertRoomFor(PHP_INT_MAX >> 2);
        })->throwsNoExceptions();
    });

    describe('usage()', function () {
        it('sums the originals and thumbnails of every image, with no limit', function () {
            MediaItem::factory()->create(['size_bytes' => 1_000, 'thumbnail_size_bytes' => 24]);
            MediaItem::factory()->create(['size_bytes' => 2_000, 'thumbnail_size_bytes' => 48]);

            expect($this->adapter->usage()->toArray())->toBe(['used_bytes' => 3_072, 'limit_bytes' => null]);
        });

        it('is nothing for an empty library', function () {
            expect($this->adapter->usage()->usedBytes)->toBe(0);
        });
    });
});
