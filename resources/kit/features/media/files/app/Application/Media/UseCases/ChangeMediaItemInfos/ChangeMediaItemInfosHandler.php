<?php

namespace App\Application\Media\UseCases\ChangeMediaItemInfos;

use App\Application\Audit\AuditLog;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Domain\Media\MediaItem\MediaItemRepository;
use Illuminate\Support\Facades\DB;

/**
 * A user renames an image of the library, changes its alt text, or moves it to another folder.
 * The file stays as it is.
 */
final class ChangeMediaItemInfosHandler
{
    public function __construct(
        protected MediaItemRepository $repo,
        protected MediaFolderRepository $folders,
        protected AuditLog $audit,
    ) {}

    public function __invoke(ChangeMediaItemInfosCommand $command): void
    {
        $item = $this->repo->getById($command->mediaItemId);
        $folderId = $command->folderId === null ? null : $this->folders->getById($command->folderId)->id();

        $item->changeInfos($command->name, $command->altText);
        $item->moveToFolder($folderId);

        DB::transaction(function () use ($item): void {
            $this->repo->update($item);
            $this->audit->record('media_item.changed', $item, ['folder_id' => $item->folderId()]);
        });
    }
}
