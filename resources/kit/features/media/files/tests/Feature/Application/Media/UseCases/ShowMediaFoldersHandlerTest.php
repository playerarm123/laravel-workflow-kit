<?php

use App\Application\Media\MediaFolderOption;
use App\Application\Media\UseCases\ShowMediaFoldersHandler;
use App\Models\MediaFolder;

describe('ShowMediaFoldersHandler', function () {
    it('lists every folder by name', function () {
        $b = MediaFolder::factory()->create(['name' => 'B']);
        $a = MediaFolder::factory()->create(['name' => 'A']);

        expect(array_map(fn (MediaFolderOption $folder): string => $folder->id, app(ShowMediaFoldersHandler::class)()))->toBe([$a->id, $b->id]);
    });

    it('is empty for a library with no folders', function () {
        expect(app(ShowMediaFoldersHandler::class)())->toBe([]);
    });
});
