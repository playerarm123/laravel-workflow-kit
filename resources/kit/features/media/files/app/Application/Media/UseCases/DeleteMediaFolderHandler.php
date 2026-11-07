<?php

namespace App\Application\Media\UseCases;

use App\Application\Audit\AuditLog;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use Illuminate\Support\Facades\DB;

/**
 * A user deletes a folder of the library. Its images stay in the library, in no folder; the
 * entry records how many there were.
 */
final class DeleteMediaFolderHandler
{
    public function __construct(
        protected MediaFolderRepository $repo,
        protected AuditLog $audit,
    ) {}

    public function __invoke(string $mediaFolderId): void
    {
        $folder = $this->repo->getById($mediaFolderId);

        DB::transaction(function () use ($folder): void {
            $itemCount = $this->repo->countItems($folder->id());

            $this->repo->delete($folder);
            $this->audit->record('media_folder.deleted', $folder, ['item_count' => $itemCount]);
        });
    }
}
