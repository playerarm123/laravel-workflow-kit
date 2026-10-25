<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Domain\Shared\DomainEntity;
use App\Infra\Persistence\Eloquent\Repositories\EloquentRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A soft-deletable aggregate: delete(), restore() and purge().
 *
 * @extends EloquentRepository<Bin, BinEntity>
 */
final class BinRepository extends EloquentRepository
{
    public function save(BinEntity $entity): void
    {
        $this->saveEntity($entity);
    }

    public function update(BinEntity $entity): void
    {
        $this->updateEntity($entity);
    }

    public function findById(string $id): ?BinEntity
    {
        return $this->findEntity($id);
    }

    public function delete(BinEntity $entity): void
    {
        $this->deleteEntity($entity);
    }

    public function restore(string $id): void
    {
        $this->restoreEntity($id);
    }

    public function purge(BinEntity $entity): void
    {
        $this->purgeEntity($entity);
    }

    #[Override]
    protected function newQuery(): Builder
    {
        return Bin::query();
    }

    #[Override]
    protected function exceptionClass(): string
    {
        return FixtureRepositoryException::class;
    }

    #[Override]
    protected function notFoundClass(): string
    {
        return FixtureNotFoundException::class;
    }

    #[Override]
    protected function logPayloadClass(): string
    {
        return FixtureLogPayload::class;
    }

    #[Override]
    protected function toModel(DomainEntity $entity): Model
    {
        $model = new Bin;
        $model->id = $entity->id();
        $model->label = $entity->label;

        return $model;
    }

    #[Override]
    protected function toEntity(Model $model): DomainEntity
    {
        return new BinEntity(id: $model->id, label: $model->label);
    }
}
