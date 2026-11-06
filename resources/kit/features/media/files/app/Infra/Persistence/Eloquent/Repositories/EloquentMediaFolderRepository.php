<?php

namespace App\Infra\Persistence\Eloquent\Repositories;

use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderRepositoryException;
use App\Domain\Media\MediaFolder\MediaFolderEntity;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Domain\Shared\DomainEntity;
use App\Infra\Logging\EntityPayloads\MediaFolderLogPayload;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @extends EloquentRepository<MediaFolder, MediaFolderEntity>
 */
class EloquentMediaFolderRepository extends EloquentRepository implements MediaFolderRepository
{
    #[Override]
    public function save(MediaFolderEntity $entity): void
    {
        $this->saveEntity($entity);
    }

    #[Override]
    public function update(MediaFolderEntity $entity): void
    {
        $this->updateEntity($entity);
    }

    #[Override]
    public function findById(string $id): ?MediaFolderEntity
    {
        return $this->findEntity($id);
    }

    #[Override]
    public function getById(string $id): MediaFolderEntity
    {
        return $this->getEntity($id);
    }

    #[Override]
    public function delete(MediaFolderEntity $entity): void
    {
        $this->deleteEntity($entity);
    }

    #[Override]
    public function nameTaken(string $name, ?string $exceptFolderId = null): bool
    {
        return $this->guardRead(fn (): bool => $this->newQuery()
            ->whereRaw('lower(name) = lower(?)', [trim($name)])
            ->when($exceptFolderId !== null, fn (Builder $query) => $query->whereKeyNot($exceptFolderId))
            ->exists());
    }

    #[Override]
    public function countItems(string $folderId): int
    {
        return $this->guardRead(fn (): int => MediaItem::query()->where('folder_id', $folderId)->count());
    }

    #[Override]
    protected function newQuery(): Builder
    {
        return MediaFolder::query();
    }

    #[Override]
    protected function exceptionClass(): string
    {
        return MediaFolderRepositoryException::class;
    }

    #[Override]
    protected function notFoundClass(): string
    {
        return MediaFolderNotFoundException::class;
    }

    #[Override]
    protected function logPayloadClass(): string
    {
        return MediaFolderLogPayload::class;
    }

    #[Override]
    protected function toModel(DomainEntity $entity): Model
    {
        $model = new MediaFolder;
        $model->id = $entity->id();
        $model->name = $entity->name();

        return $model;
    }

    #[Override]
    protected function toEntity(Model $model): DomainEntity
    {
        return MediaFolderEntity::reconstitute(
            id: $model->id,
            name: $model->name,
        );
    }
}
