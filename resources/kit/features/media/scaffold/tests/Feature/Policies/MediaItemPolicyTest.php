<?php

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Each ability of MediaItemPolicy, allowed and denied, asked the way a controller asks it.
 */
beforeEach(function () {
    $this->mediaItem = MediaItem::factory()->create();
});

describe('viewAny', function () {
    it('allows a user who verified their email', function () {
        expect(Gate::forUser(User::factory()->create())->allows('viewAny', MediaItem::class))->toBeTrue();
    });

    it('denies a user who has not', function () {
        expect(Gate::forUser(User::factory()->unverified()->create())->allows('viewAny', MediaItem::class))->toBeFalse();
    });
});

describe('the abilities that change the library', function () {
    it('allows a user who verified their email', function () {
        $gate = Gate::forUser(User::factory()->create());

        expect($gate->allows('create', MediaItem::class))->toBeTrue()
            ->and($gate->allows('updateAny', MediaItem::class))->toBeTrue()
            ->and($gate->allows('moveAny', MediaItem::class))->toBeTrue()
            ->and($gate->allows('deleteAny', MediaItem::class))->toBeTrue()
            ->and($gate->allows('update', $this->mediaItem))->toBeTrue()
            ->and($gate->allows('delete', $this->mediaItem))->toBeTrue();
    });

    it('denies a user who has not', function () {
        $gate = Gate::forUser(User::factory()->unverified()->create());

        expect($gate->allows('create', MediaItem::class))->toBeFalse()
            ->and($gate->allows('updateAny', MediaItem::class))->toBeFalse()
            ->and($gate->allows('moveAny', MediaItem::class))->toBeFalse()
            ->and($gate->allows('deleteAny', MediaItem::class))->toBeFalse()
            ->and($gate->allows('update', $this->mediaItem))->toBeFalse()
            ->and($gate->allows('delete', $this->mediaItem))->toBeFalse();
    });
});
