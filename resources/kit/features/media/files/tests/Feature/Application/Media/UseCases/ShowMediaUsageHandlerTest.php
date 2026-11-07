<?php

use App\Application\Media\UseCases\ShowMediaUsageHandler;
use App\Models\MediaItem;

describe('ShowMediaUsageHandler', function () {
    it('answers what the bound quota says the library uses', function () {
        MediaItem::factory()->create(['size_bytes' => 100, 'thumbnail_size_bytes' => 10]);

        $usage = app(ShowMediaUsageHandler::class)();

        expect($usage->usedBytes)->toBe(110)->and($usage->limitBytes)->toBeNull();
    });
});
