<?php

namespace App\Domain\Media\MediaItem;

use App\Domain\Media\MediaItem\Exceptions\MediaItemNotFoundException;
use App\Domain\Media\MediaItem\Exceptions\MediaItemRepositoryException;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;

interface MediaItemRepository
{
    /**
     * @throws MediaItemRepositoryException
     */
    public function save(MediaItemEntity $entity): void;

    /**
     * Means the same as `save()`, for many entities in one statement: inserts, or overwrites the existing row by id.
     *
     * @param  array<MediaItemEntity>  $entities
     *
     * @throws MediaItemRepositoryException
     */
    public function saveMany(array $entities): void;

    /**
     * Rewrite an aggregate that already exists — the row is never inserted.
     *
     * @throws MediaItemNotFoundException
     * @throws MediaItemRepositoryException
     */
    public function update(MediaItemEntity $entity): void;

    /**
     * Rewrite many aggregates that already exist, in one transaction.
     *
     * @param  array<MediaItemEntity>  $entities
     *
     * @throws MediaItemNotFoundException
     * @throws MediaItemRepositoryException
     */
    public function updateMany(array $entities): void;

    /**
     * @throws MediaItemRepositoryException
     */
    public function findById(string $id): ?MediaItemEntity;

    /**
     * @throws MediaItemNotFoundException
     * @throws MediaItemRepositoryException
     */
    public function getById(string $id): MediaItemEntity;

    /**
     * The images of $ids; an id that names no image is left out.
     *
     * @param  list<string>  $ids
     * @return list<MediaItemEntity>
     *
     * @throws MediaItemRepositoryException
     */
    public function many(array $ids): array;

    /**
     * @throws MediaItemNotFoundException
     * @throws MediaItemRepositoryException
     */
    public function delete(MediaItemEntity $entity): void;

    /**
     * Where image $mediaItemId is used, oldest first.
     *
     * @return list<MediaUsage>
     *
     * @throws MediaItemRepositoryException
     */
    public function usagesOf(string $mediaItemId): array;

    /**
     * Records that $usage uses image $mediaItemId. Recording the same place again keeps its first
     * row, under its first id, and only takes the new label.
     *
     * @throws MediaItemRepositoryException
     */
    public function attachUsage(string $usageId, string $mediaItemId, MediaUsage $usage): void;

    /**
     * Forgets that $usage uses image $mediaItemId; nothing happens when it never did.
     *
     * @throws MediaItemRepositoryException
     */
    public function detachUsage(string $mediaItemId, MediaUsage $usage): void;
}
