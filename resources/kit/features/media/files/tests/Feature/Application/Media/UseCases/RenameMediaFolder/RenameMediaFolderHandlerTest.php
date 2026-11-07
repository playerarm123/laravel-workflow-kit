<?php

use App\Application\Media\MediaFolderNameTakenException;
use App\Application\Media\UseCases\RenameMediaFolder\RenameMediaFolderCommand;
use App\Application\Media\UseCases\RenameMediaFolder\RenameMediaFolderHandler;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Models\MediaFolder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->handler = app(RenameMediaFolderHandler::class);
});

describe('RenameMediaFolderHandler', function () {
    it('renames a folder, even to its own name in another case', function () {
        $folder = MediaFolder::factory()->create(['name' => 'logos']);

        ($this->handler)(new RenameMediaFolderCommand($folder->id, 'Logos'));

        expect($folder->fresh()?->name)->toBe('Logos');
        $this->assertDatabaseHas('audit_entries', ['event' => 'media_folder.renamed', 'subject_id' => $folder->id]);
    });

    it('refuses a name another folder has', function () {
        MediaFolder::factory()->create(['name' => 'Team']);
        $folder = MediaFolder::factory()->create();

        expect(fn () => ($this->handler)(new RenameMediaFolderCommand($folder->id, 'team')))->toThrow(MediaFolderNameTakenException::class);
    });

    it('does not find a folder that is gone', function () {
        expect(fn () => ($this->handler)(new RenameMediaFolderCommand((string) Str::uuid(), 'x')))->toThrow(MediaFolderNotFoundException::class);
    });
});
