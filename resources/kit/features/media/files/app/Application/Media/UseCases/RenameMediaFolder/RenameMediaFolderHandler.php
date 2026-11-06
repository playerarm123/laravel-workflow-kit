<?php

namespace App\Application\Media\UseCases\RenameMediaFolder;

use App\Application\Audit\AuditLog;
use App\Application\Media\MediaFolderNameTakenException;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use Illuminate\Support\Facades\DB;

/**
 * A user renames a folder of the library, to a name no other folder has.
 */
final class RenameMediaFolderHandler
{
    public function __construct(
        protected MediaFolderRepository $repo,
        protected AuditLog $audit,
    ) {}

    /**
     * @throws MediaFolderNameTakenException
     */
    public function __invoke(RenameMediaFolderCommand $command): void
    {
        $folder = $this->repo->getById($command->mediaFolderId);

        if ($this->repo->nameTaken($command->name, $folder->id())) {
            throw MediaFolderNameTakenException::taken($folder->id());
        }

        $folder->rename($command->name);

        DB::transaction(function () use ($folder): void {
            $this->repo->update($folder);
            $this->audit->record('media_folder.renamed', $folder, []);
        });
    }
}
