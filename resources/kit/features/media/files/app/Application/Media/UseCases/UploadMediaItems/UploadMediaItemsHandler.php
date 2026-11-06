<?php

namespace App\Application\Media\UseCases\UploadMediaItems;

use App\Application\Audit\AuditLog;
use App\Application\Media\MediaQuota;
use App\Application\Media\MediaQuotaExceededException;
use App\Application\Media\MediaStorage;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Media\MediaItem\MediaItemRepository;
use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use App\Domain\Shared\Ports\IdGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A user adds images to the library, all or none, as far as its quota allows. Each is named after
 * its file. The files are written first, because only the resized copy says how much they weigh,
 * and are removed again when anything after that fails, the quota's refusal included. Two uploads
 * at once may both pass the quota by a little.
 */
final class UploadMediaItemsHandler
{
    public function __construct(
        protected IdGenerator $ids,
        protected MediaItemRepository $repo,
        protected MediaFolderRepository $folders,
        protected MediaQuota $quota,
        protected MediaStorage $storage,
        protected AuditLog $audit,
    ) {}

    /**
     * @throws MediaQuotaExceededException
     */
    public function __invoke(UploadMediaItemsCommand $command): UploadMediaItemsResult
    {
        $folderId = $command->folderId === null ? null : $this->folders->getById($command->folderId)->id();

        /** @var list<MediaFile> $stored */
        $stored = [];

        try {
            $items = [];

            foreach ($command->images as $image) {
                $file = $this->storage->store($image);
                $stored[] = $file;
                $items[] = MediaItemEntity::create($this->ids->next(), $folderId, $this->nameOf($image), $file);
            }

            DB::transaction(function () use ($items, $stored): void {
                $this->quota->assertRoomFor(array_sum(array_map(fn (MediaFile $file): int => $file->totalBytes(), $stored)));

                $this->repo->saveMany($items);

                foreach ($items as $item) {
                    $this->audit->record('media_item.uploaded', $item, ['folder_id' => $item->folderId(), 'size_bytes' => $item->file()->totalBytes()]);
                }
            });
        } catch (Throwable $e) {
            array_walk($stored, fn (MediaFile $file) => $this->storage->delete($file));

            throw $e;
        }

        return new UploadMediaItemsResult(ids: array_map(fn (MediaItemEntity $item): string => $item->id(), $items));
    }

    /**
     * The file's name without its extension, cut to the longest name an image may have.
     */
    private function nameOf(UploadedFile $image): string
    {
        $name = trim(pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME));

        return mb_substr($name === '' ? 'image' : $name, 0, 150);
    }
}
