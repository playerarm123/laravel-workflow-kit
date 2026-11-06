<?php

namespace App\Application\Media\UseCases\MoveMediaItems;

use App\Application\Audit\AuditLog;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Media\MediaItem\MediaItemRepository;
use Illuminate\Support\Facades\DB;

/**
 * Moves images of the library into one folder, or out of every folder. One already there, or an
 * id that names no image, is skipped. Returns how many moved.
 */
final class MoveMediaItemsHandler
{
    public function __construct(
        protected MediaItemRepository $repo,
        protected MediaFolderRepository $folders,
        protected AuditLog $audit,
    ) {}

    public function __invoke(MoveMediaItemsCommand $command): int
    {
        $folderId = $command->folderId === null ? null : $this->folders->getById($command->folderId)->id();

        return DB::transaction(function () use ($command, $folderId): int {
            $moved = array_values(array_filter(
                $this->repo->many($command->ids),
                fn (MediaItemEntity $item): bool => $item->folderId() !== $folderId,
            ));

            if ($moved === []) {
                return 0;
            }

            foreach ($moved as $item) {
                $item->moveToFolder($folderId);
            }

            $this->repo->updateMany($moved);

            array_walk($moved, fn (MediaItemEntity $item) => $this->audit->record('media_item.moved', $item, ['folder_id' => $folderId]));

            return count($moved);
        });
    }
}
