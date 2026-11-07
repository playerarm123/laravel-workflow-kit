<?php

use App\Application\Media\MediaFolderNameTakenException;
use App\Application\Media\UseCases\CreateMediaFolder\CreateMediaFolderCommand;
use App\Application\Media\UseCases\CreateMediaFolder\CreateMediaFolderHandler;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Models\MediaFolder;

beforeEach(function () {
    $this->handler = app(CreateMediaFolderHandler::class);
});

describe('CreateMediaFolderHandler', function () {
    it('adds a folder to the library', function () {
        $id = ($this->handler)(new CreateMediaFolderCommand('Team'));

        expect(app(MediaFolderRepository::class)->getById($id)->name())->toBe('Team');
        $this->assertDatabaseHas('audit_entries', ['event' => 'media_folder.created', 'subject_type' => 'media_folder', 'subject_id' => $id]);
    });

    it('refuses a name another folder has, whatever its case', function () {
        MediaFolder::factory()->create(['name' => 'Logos']);

        expect(fn () => ($this->handler)(new CreateMediaFolderCommand('logos')))->toThrow(MediaFolderNameTakenException::class);
    });
});
