<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Domain\Shared\DomainEntity;
use App\Infra\Persistence\Eloquent\Repositories\EloquentRepository;
use App\Infra\Persistence\Eloquent\Repositories\WriteMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * The owned-child-tables shape of repositories.md: writeRoots(), then syncChildren().
 *
 * @extends EloquentRepository<Crate, CrateEntity>
 */
class CrateRepository extends EloquentRepository
{
    /** @var class-string<CrateItem> */
    protected string $childModel = CrateItem::class;

    public function save(CrateEntity $entity): void
    {
        $this->saveEntity($entity);
    }

    /**
     * @param  list<CrateEntity>  $entities
     */
    public function saveMany(array $entities): void
    {
        $this->saveEntities($entities);
    }

    public function update(CrateEntity $entity): void
    {
        $this->updateEntity($entity);
    }

    /**
     * @param  list<CrateEntity>  $entities
     */
    public function updateMany(array $entities): void
    {
        $this->updateEntities($entities);
    }

    public function getById(string $id): CrateEntity
    {
        return $this->getEntity($id);
    }

    public function delete(CrateEntity $entity): void
    {
        $this->deleteEntity($entity);
    }

    #[Override]
    protected function newQuery(): Builder
    {
        return Crate::query()->with('items');
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
    protected function write(array $entities, WriteMode $mode): void
    {
        $this->writeRoots($entities, $mode);

        $children = [];

        foreach ($entities as $entity) {
            foreach ($entity->items as $item) {
                $child = new ($this->childModel);
                $child->id = $item->id();
                $child->crate_id = $entity->id();
                $child->sku = $item->sku;
                $children[] = $child;
            }
        }

        $this->syncChildren($this->childModel, 'crate_id', array_map(fn (CrateEntity $entity): string => $entity->id(), $entities), $children, $mode);
    }

    #[Override]
    protected function toModel(DomainEntity $entity): Model
    {
        $model = new Crate;
        $model->id = $entity->id();
        $model->label = $entity->label;

        return $model;
    }

    #[Override]
    protected function toEntity(Model $model): DomainEntity
    {
        return new CrateEntity(
            id: $model->id,
            label: $model->label,
            items: $model->items->sortBy('sku')->values()->map(fn (CrateItem $item): CrateItemEntity => new CrateItemEntity($item->id, $item->sku))->all(),
        );
    }
}
