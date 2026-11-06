<?php

namespace App\Infra\Persistence\Eloquent\Repositories;

use App\Domain\Media\MediaItem\Exceptions\MediaItemNotFoundException;
use App\Domain\Media\MediaItem\Exceptions\MediaItemRepositoryException;
use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Media\MediaItem\MediaItemRepository;
use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;
use App\Domain\Shared\DomainEntity;
use App\Infra\Logging\EntityPayloads\MediaItemLogPayload;
use App\Models\MediaItem;
use App\Models\MediaUsage as MediaUsageModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Override;

/**
 * The usages live in `media_usages`, which the aggregates that use an image write through
 * attachUsage() and detachUsage(), one row at a time. They are not the item's children: two
 * aggregates attaching the same image at once must never overwrite each other's row.
 *
 * @extends EloquentRepository<MediaItem, MediaItemEntity>
 */
class EloquentMediaItemRepository extends EloquentRepository implements MediaItemRepository
{
    #[Override]
    public function save(MediaItemEntity $entity): void
    {
        $this->saveEntity($entity);
    }

    #[Override]
    public function saveMany(array $entities): void
    {
        $this->saveEntities($entities);
    }

    #[Override]
    public function update(MediaItemEntity $entity): void
    {
        $this->updateEntity($entity);
    }

    #[Override]
    public function updateMany(array $entities): void
    {
        $this->updateEntities($entities);
    }

    #[Override]
    public function findById(string $id): ?MediaItemEntity
    {
        return $this->findEntity($id);
    }

    #[Override]
    public function getById(string $id): MediaItemEntity
    {
        return $this->getEntity($id);
    }

    #[Override]
    public function many(array $ids): array
    {
        return $ids === [] ? [] : $this->entities($this->newQuery()->whereKey($ids)->orderBy('id'));
    }

    #[Override]
    public function delete(MediaItemEntity $entity): void
    {
        $this->deleteEntity($entity);
    }

    #[Override]
    public function usagesOf(string $mediaItemId): array
    {
        $models = $this->guardRead(fn () => MediaUsageModel::query()
            ->where('media_item_id', $mediaItemId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get());

        return array_values($models->map(fn (MediaUsageModel $usage): MediaUsage => MediaUsage::from($usage->usable_type, $usage->usable_id, $usage->label))->all());
    }

    #[Override]
    public function attachUsage(string $usageId, string $mediaItemId, MediaUsage $usage): void
    {
        $now = Date::now();

        try {
            MediaUsageModel::query()->upsert(
                [[
                    'id' => $usageId,
                    'media_item_id' => $mediaItemId,
                    'usable_type' => $usage->usableType(),
                    'usable_id' => $usage->usableId(),
                    'label' => $usage->label(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                uniqueBy: ['media_item_id', 'usable_type', 'usable_id'],
                update: ['label', 'updated_at'],
            );
        } catch (QueryException $e) {
            throw MediaItemRepositoryException::saveFailed($e, ['media_item_id' => $mediaItemId, 'usable_type' => $usage->usableType(), 'usable_id' => $usage->usableId()]);
        }
    }

    #[Override]
    public function detachUsage(string $mediaItemId, MediaUsage $usage): void
    {
        try {
            MediaUsageModel::query()
                ->where('media_item_id', $mediaItemId)
                ->where('usable_type', $usage->usableType())
                ->where('usable_id', $usage->usableId())
                ->delete();
        } catch (QueryException $e) {
            throw MediaItemRepositoryException::deleteFailed($e, ['media_item_id' => $mediaItemId, 'usable_type' => $usage->usableType(), 'usable_id' => $usage->usableId()]);
        }
    }

    #[Override]
    protected function newQuery(): Builder
    {
        return MediaItem::query();
    }

    #[Override]
    protected function exceptionClass(): string
    {
        return MediaItemRepositoryException::class;
    }

    #[Override]
    protected function notFoundClass(): string
    {
        return MediaItemNotFoundException::class;
    }

    #[Override]
    protected function logPayloadClass(): string
    {
        return MediaItemLogPayload::class;
    }

    #[Override]
    protected function toModel(DomainEntity $entity): Model
    {
        $model = new MediaItem;
        $model->id = $entity->id();
        $model->folder_id = $entity->folderId();
        $model->name = $entity->name();
        $model->alt_text = $entity->altText();
        $model->disk = $entity->file()->disk();
        $model->path = $entity->file()->path();
        $model->thumbnail_path = $entity->file()->thumbnailPath();
        $model->mime_type = $entity->file()->mimeType();
        $model->size_bytes = $entity->file()->sizeBytes();
        $model->thumbnail_size_bytes = $entity->file()->thumbnailSizeBytes();
        $model->width = $entity->file()->width();
        $model->height = $entity->file()->height();

        return $model;
    }

    #[Override]
    protected function toEntity(Model $model): DomainEntity
    {
        return MediaItemEntity::reconstitute(
            id: $model->id,
            folderId: $model->folder_id,
            name: $model->name,
            altText: $model->alt_text,
            file: MediaFile::from(
                disk: $model->disk,
                path: $model->path,
                thumbnailPath: $model->thumbnail_path,
                mimeType: $model->mime_type,
                sizeBytes: $model->size_bytes,
                thumbnailSizeBytes: $model->thumbnail_size_bytes,
                width: $model->width,
                height: $model->height,
            ),
        );
    }
}
