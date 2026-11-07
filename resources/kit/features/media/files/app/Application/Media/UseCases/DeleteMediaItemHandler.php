<?php

namespace App\Application\Media\UseCases;

use App\Application\Audit\AuditLog;
use App\Application\Media\MediaStorage;
use App\Domain\Media\MediaItem\Exceptions\MediaItemInUseException;
use App\Domain\Media\MediaItem\MediaItemRepository;
use Illuminate\Support\Facades\DB;

/**
 * A user deletes an image of the library that nothing uses. Its files go once the row is gone,
 * so a refused delete never loses a picture.
 */
final class DeleteMediaItemHandler
{
    public function __construct(
        protected MediaItemRepository $repo,
        protected MediaStorage $storage,
        protected AuditLog $audit,
    ) {}

    /**
     * @throws MediaItemInUseException
     */
    public function __invoke(string $mediaItemId): void
    {
        $item = $this->repo->getById($mediaItemId);

        DB::transaction(function () use ($item): void {
            $item->assertNotInUse($this->repo->usagesOf($item->id()));

            $this->repo->delete($item);
            $this->audit->record('media_item.deleted', $item, ['folder_id' => $item->folderId(), 'size_bytes' => $item->file()->totalBytes()]);
        });

        $this->storage->delete($item->file());
    }
}
