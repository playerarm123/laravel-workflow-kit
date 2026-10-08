<?php

namespace App\Infra\Persistence\Eloquent\Repositories;

use App\Domain\Shared\ClonableAggregate;
use App\Domain\Shared\DomainEntity;
use App\Domain\Shared\Exceptions\EntityNotFoundException;
use App\Domain\Shared\Exceptions\RepositoryException;
use App\Domain\Shared\Ports\IdGenerator;
use App\Infra\Logging\EntityPayloads\EntityLogPayload;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use LogicException;
use OverflowException;
use Throwable;

/**
 * The one way an aggregate reaches the database. A concrete repository declares its model,
 * its exceptions and its log payload, maps rows with toModel()/toEntity(), and writes every
 * public method as a one-line call to the helpers here — see repositories.md.
 *
 * Generic so PHPStan knows which pair a subclass binds: declare it with
 * `@extends EloquentRepository<Agent, AgentEntity>` and every helper returns the real entity.
 *
 * @template TModel of Model
 * @template TEntity of DomainEntity
 */
abstract class EloquentRepository
{
    /** Postgres and MySQL/MariaDB both refuse a statement with more placeholders than this. */
    private const int MAX_PLACEHOLDERS = 65535;

    /** Rows per INSERT even when the placeholder limit would allow more — keeps statements and locks short. */
    private const int MAX_ROWS_PER_STATEMENT = 1000;

    /** MySQL/MariaDB error number for a duplicate key. */
    private const int MYSQL_DUPLICATE_KEY = 1062;

    /**
     * ================================================
     * Declarations
     * ================================================
     */

    /**
     * The query every read starts from, which also names the model. An aggregate with
     * children eager loads them here (`Member::with('paymentAccounts')`).
     *
     * @return Builder<TModel>
     */
    abstract protected function newQuery(): Builder;

    /**
     * @return class-string<RepositoryException>
     */
    abstract protected function exceptionClass(): string;

    /**
     * @return class-string<EntityNotFoundException>
     */
    abstract protected function notFoundClass(): string;

    /**
     * @return class-string<EntityLogPayload>
     */
    abstract protected function logPayloadClass(): string;

    /**
     * @param  TEntity  $entity
     * @return TModel
     */
    abstract protected function toModel(DomainEntity $entity): Model;

    /**
     * @param  TModel  $model
     * @return TEntity
     */
    abstract protected function toEntity(Model $model): DomainEntity;

    /**
     * ================================================
     * Writes
     * ================================================
     */

    /**
     * @param  TEntity  $entity
     */
    protected function saveEntity(DomainEntity $entity): void
    {
        $this->saveEntities([$entity]);
    }

    /**
     * Insert, or write over the row with the same id — one statement per batch, never a
     * read first. An empty list runs no query.
     *
     * @param  array<TEntity>  $entities
     */
    protected function saveEntities(array $entities): void
    {
        $this->guardWrite(array_values($entities), WriteMode::Upsert);
    }

    /**
     * @param  TEntity  $entity
     */
    protected function updateEntity(DomainEntity $entity): void
    {
        $this->updateEntities([$entity]);
    }

    /**
     * Rewrite rows that must already exist. A missing row throws NotFound and rolls the
     * whole batch back; nothing is ever inserted.
     *
     * @param  array<TEntity>  $entities
     */
    protected function updateEntities(array $entities): void
    {
        $this->guardWrite(array_values($entities), WriteMode::Update);
    }

    /**
     * A new aggregate built by the source itself (ClonableAggregate), inserted as a whole.
     * An id that already exists fails instead of overwriting; the source is not touched.
     *
     * @param  TEntity  $source
     * @return TEntity
     */
    protected function cloneEntity(DomainEntity $source, string $newId): DomainEntity
    {
        if (! $source instanceof ClonableAggregate) {
            throw $this->exceptionClass()::notClonable($source->id());
        }

        $copy = $source->cloneAs($newId, app(IdGenerator::class));

        $this->guardWrite([$copy], WriteMode::Insert);

        return $copy;
    }

    /**
     * Upsert on a business key instead of the id — only for re-installing the rows a
     * definition owns (reference data). Columns left out of $update keep what is stored;
     * the id and created_at are never rewritten.
     *
     * @param  array<TEntity>  $entities
     * @param  list<string>  $uniqueBy
     * @param  list<string>|null  $update
     */
    protected function upsertEntitiesBy(array $entities, array $uniqueBy, ?array $update = null): void
    {
        $entities = array_values($entities);

        if ($entities === []) {
            return;
        }

        try {
            $this->newModel()->getConnection()->transaction(function () use ($entities, $uniqueBy, $update): void {
                $models = array_map(fn (DomainEntity $entity): Model => $this->toModel($entity), $entities);

                foreach ($this->chunksOf($models) as $chunk) {
                    $rows = array_map($this->rowOf(...), $chunk);

                    $chunk[0]->newQuery()->upsert($rows, $uniqueBy, $update ?? $this->updatableColumns($chunk[0], $rows[0]));
                }
            });
        } catch (QueryException $e) {
            throw $this->exceptionClass()::saveFailed($e, $this->payloadOf($entities));
        }
    }

    /**
     * Delete the row — or stamp deleted_at when the model uses SoftDeletes. Owned child
     * rows go with it through the foreign key's cascadeOnDelete().
     *
     * @param  TEntity  $entity
     */
    protected function deleteEntity(DomainEntity $entity): void
    {
        try {
            $deleted = $this->newModel()->newQuery()->whereKey($entity->id())->delete();
        } catch (QueryException $e) {
            throw $this->exceptionClass()::deleteFailed($e, $this->payloadOf([$entity]));
        }

        if ($deleted === 0) {
            throw $this->notFoundClass()::byId($entity->id());
        }
    }

    /**
     * Bring a soft-deleted row back. Only for models that use SoftDeletes.
     */
    protected function restoreEntity(string $id): void
    {
        $this->assertSoftDeletes('restore');

        try {
            $restored = $this->newModel()->newQuery()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->whereKey($id)
                ->whereNotNull($this->deletedAtColumn())
                ->update([$this->deletedAtColumn() => null]);
        } catch (QueryException $e) {
            throw $this->exceptionClass()::saveFailed($e, ['id' => $id]);
        }

        if ($restored === 0) {
            throw $this->notFoundClass()::byId($id);
        }
    }

    /**
     * Delete a soft-deletable row for good, trashed or not. Only for models that use SoftDeletes.
     *
     * @param  TEntity  $entity
     */
    protected function purgeEntity(DomainEntity $entity): void
    {
        $this->assertSoftDeletes('purge');

        try {
            $purged = $this->newModel()->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($entity->id())->forceDelete();
        } catch (QueryException $e) {
            throw $this->exceptionClass()::deleteFailed($e, $this->payloadOf([$entity]));
        }

        if ($purged === 0) {
            throw $this->notFoundClass()::byId($entity->id());
        }
    }

    /**
     * Write the given aggregates. Runs inside a transaction opened by the base, so an
     * override that writes several tables stays atomic.
     *
     * Override only in one of two shapes (repositories.md):
     * - children: writeRoots() then syncChildren() for every owned table;
     * - optimistic locking: the version column decides insert or `UPDATE … WHERE version`.
     *
     * @param  non-empty-list<TEntity>  $entities
     */
    protected function write(array $entities, WriteMode $mode): void
    {
        $this->writeRoots($entities, $mode);
    }

    /**
     * @param  list<TEntity>  $entities
     */
    protected function writeRoots(array $entities, WriteMode $mode): void
    {
        $models = array_map(fn (DomainEntity $entity): Model => $this->toModel($entity), $entities);

        match ($mode) {
            WriteMode::Upsert => $this->upsertModels($models),
            WriteMode::Insert => $this->insertModels($models),
            WriteMode::Update => $this->updateModels($models),
        };
    }

    /**
     * Make an owned child table match the aggregates: delete the parents' children that are
     * no longer there, then write the rest. Deleting first matters — a child replaced by one
     * with a new id often reuses the old one's unique key.
     *
     * @param  class-string<Model>  $childModel
     * @param  list<string>  $parentIds
     * @param  list<Model>  $children
     */
    protected function syncChildren(string $childModel, string $foreignKey, array $parentIds, array $children, WriteMode $mode): void
    {
        if ($parentIds === []) {
            return;
        }

        if ($mode !== WriteMode::Insert) {
            $childIds = array_map(fn (Model $child): mixed => $child->getKey(), $children);

            if (count($parentIds) + count($childIds) > self::MAX_PLACEHOLDERS - 1000) {
                throw new OverflowException(sprintf(
                    'Syncing %d %s rows in one call exceeds what one DELETE can name; redesign the aggregate or split the call.',
                    count($childIds),
                    $childModel,
                ));
            }

            $childModel::query()
                ->whereIn($foreignKey, $parentIds)
                ->when($childIds !== [], fn (Builder $query) => $query->whereKeyNot($childIds))
                ->delete();
        }

        $mode === WriteMode::Insert ? $this->insertModels($children) : $this->upsertModels($children);
    }

    /**
     * ================================================
     * Reads
     * ================================================
     */

    /**
     * @return TEntity|null
     */
    protected function findEntity(string $id): ?DomainEntity
    {
        return $this->firstEntity($this->newQuery()->whereKey($id));
    }

    /**
     * @return TEntity
     */
    protected function getEntity(string $id): DomainEntity
    {
        return $this->findEntity($id) ?? throw $this->notFoundClass()::byId($id);
    }

    /**
     * @param  Builder<TModel>  $query
     * @return TEntity|null
     */
    protected function firstEntity(Builder $query): ?DomainEntity
    {
        $model = $this->guardRead(fn () => $query->first());

        return $model === null ? null : $this->hydrate($model);
    }

    /**
     * @param  Builder<TModel>  $query
     * @return list<TEntity>
     */
    protected function entities(Builder $query): array
    {
        $models = $this->guardRead(function () use ($query): array {
            $models = $query->getModels();

            return $models === [] ? [] : $query->eagerLoadRelations($models);
        });

        return array_values(array_map($this->hydrate(...), $models));
    }

    /**
     * Run a read and turn a database failure into this aggregate's queryFailed, so no
     * framework exception leaves the repository.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    protected function guardRead(Closure $read): mixed
    {
        try {
            return $read();
        } catch (QueryException $e) {
            throw $this->exceptionClass()::queryFailed($e);
        }
    }

    /**
     * toEntity() behind a guard: a row that cannot be rebuilt becomes reconstituteFailed with
     * its raw attributes. Another repository's failure (a nested load) passes through as is.
     *
     * getAttributes(), not toArray() — a broken row usually breaks on a cast, which toArray()
     * would trigger again inside this handler.
     *
     * @param  TModel  $model
     * @return TEntity
     */
    protected function hydrate(Model $model): DomainEntity
    {
        try {
            return $this->toEntity($model);
        } catch (RepositoryException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->exceptionClass()::reconstituteFailed((string) $model->getKey(), $e->getMessage(), $e, $model->getAttributes());
        }
    }

    /**
     * ================================================
     * Internals
     * ================================================
     */

    /**
     * @param  list<TEntity>  $entities
     */
    private function guardWrite(array $entities, WriteMode $mode): void
    {
        if ($entities === []) {
            return;
        }

        try {
            $this->newModel()->getConnection()->transaction(fn () => $this->write($entities, $mode));
        } catch (QueryException|OverflowException $e) {
            throw $this->exceptionClass()::saveFailed($e, $this->payloadOf($entities));
        }
    }

    /**
     * @param  list<Model>  $models
     */
    private function upsertModels(array $models): void
    {
        if ($models === []) {
            return;
        }

        if ($this->isMySqlFamily($models[0])) {
            $this->insertOrUpdateByKey($models);

            return;
        }

        foreach ($this->chunksOf($models) as $chunk) {
            $rows = array_map($this->rowOf(...), $chunk);

            $chunk[0]->newQuery()->upsert(
                $rows,
                uniqueBy: [$chunk[0]->getKeyName()],
                update: $this->updatableColumns($chunk[0], $rows[0]),
            );
        }
    }

    /**
     * MySQL/MariaDB turn upsert() into ON DUPLICATE KEY UPDATE, which matches *any* unique
     * index and would silently rewrite another aggregate's row on a business-key clash. So:
     * insert the batch, and only when the primary key collides fall back to row by row,
     * updating by id — a clash on any other unique key still fails, as it must.
     *
     * @param  list<Model>  $models
     */
    private function insertOrUpdateByKey(array $models): void
    {
        foreach ($this->chunksOf($models) as $chunk) {
            try {
                $chunk[0]->newQuery()->insert(array_map($this->rowOf(...), $chunk));

                continue;
            } catch (QueryException $e) {
                if (! $this->isPrimaryKeyCollision($e)) {
                    throw $e;
                }
            }

            foreach ($chunk as $model) {
                $row = $this->rowOf($model);

                try {
                    $model->newQuery()->insert($row);
                } catch (QueryException $e) {
                    if (! $this->isPrimaryKeyCollision($e)) {
                        throw $e;
                    }

                    $model->newQuery()->whereKey($model->getKey())->update(
                        array_intersect_key($row, array_flip($this->updatableColumns($model, $row))),
                    );
                }
            }
        }
    }

    /**
     * @param  list<Model>  $models
     */
    private function insertModels(array $models): void
    {
        foreach ($this->chunksOf($models) as $chunk) {
            $chunk[0]->newQuery()->insert(array_map($this->rowOf(...), $chunk));
        }
    }

    /**
     * One UPDATE per row. MySQL reports rows *changed*, not matched, so a zero is confirmed
     * with an existence check before it becomes NotFound.
     *
     * @param  list<Model>  $models
     */
    private function updateModels(array $models): void
    {
        foreach ($models as $model) {
            $row = $this->rowOf($model);
            $query = $model->newQuery()->whereKey($model->getKey());

            $updated = (clone $query)->update(array_intersect_key($row, array_flip($this->updatableColumns($model, $row))));

            if ($updated === 0 && ! $query->exists()) {
                throw $this->notFoundClass()::byId((string) $model->getKey());
            }
        }
    }

    /**
     * Every column except the key and the creation time — a write over an existing row must
     * never move created_at.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function updatableColumns(Model $model, array $row): array
    {
        return array_values(array_diff(
            array_keys($row),
            array_filter([$model->getKeyName(), $model->getCreatedAtColumn()]),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowOf(Model $model): array
    {
        if ($model->usesTimestamps()) {
            $model->updateTimestamps();
        }

        return $model->getAttributes();
    }

    /**
     * @template TChunk of Model
     *
     * @param  list<TChunk>  $models
     * @return list<non-empty-list<TChunk>>
     */
    protected function chunksOf(array $models): array
    {
        if ($models === []) {
            return [];
        }

        $columns = max(1, count($this->rowOf(clone $models[0])));
        $size = max(1, min(self::MAX_ROWS_PER_STATEMENT, intdiv(self::MAX_PLACEHOLDERS, $columns)));

        return array_chunk($models, $size);
    }

    private function isMySqlFamily(Model $model): bool
    {
        return in_array($model->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function isPrimaryKeyCollision(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY
            && preg_match("/for key '(?:[^']*\\.)?PRIMARY'/", $e->getMessage()) === 1;
    }

    /**
     * @return TModel
     */
    private function newModel(): Model
    {
        return $this->newQuery()->getModel()->newInstance();
    }

    private function assertSoftDeletes(string $action): void
    {
        $model = $this->newModel();

        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            throw new LogicException(sprintf('Cannot %s %s: the model does not use SoftDeletes.', $action, $model::class));
        }
    }

    private function deletedAtColumn(): string
    {
        $model = $this->newModel();

        return method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : 'deleted_at';
    }

    /**
     * One payload for a single entity, a list for a batch — what the log reader expects.
     *
     * @param  list<TEntity>  $entities
     * @return array<array-key, mixed>
     */
    private function payloadOf(array $entities): array
    {
        $payloads = array_map(
            fn (DomainEntity $entity): array => $this->logPayloadClass()::from($entity)->toArray(),
            $entities,
        );

        return count($payloads) === 1 ? $payloads[0] : $payloads;
    }
}
