<?php

namespace App\Domain\Media\MediaFolder;

use App\Domain\Media\MediaFolder\Exceptions\MediaFolderNotFoundException;
use App\Domain\Media\MediaFolder\Exceptions\MediaFolderRepositoryException;

interface MediaFolderRepository
{
    /**
     * @throws MediaFolderRepositoryException
     */
    public function save(MediaFolderEntity $entity): void;

    /**
     * Rewrite an aggregate that already exists — the row is never inserted.
     *
     * @throws MediaFolderNotFoundException
     * @throws MediaFolderRepositoryException
     */
    public function update(MediaFolderEntity $entity): void;

    /**
     * @throws MediaFolderRepositoryException
     */
    public function findById(string $id): ?MediaFolderEntity;

    /**
     * @throws MediaFolderNotFoundException
     * @throws MediaFolderRepositoryException
     */
    public function getById(string $id): MediaFolderEntity;

    /**
     * Deletes the folder. Its images stay in the library, in no folder.
     *
     * @throws MediaFolderNotFoundException
     * @throws MediaFolderRepositoryException
     */
    public function delete(MediaFolderEntity $entity): void;

    /**
     * Whether another folder has the name already, ignoring case.
     *
     * @throws MediaFolderRepositoryException
     */
    public function nameTaken(string $name, ?string $exceptFolderId = null): bool;

    /**
     * How many images the folder holds, which the audit entry of its delete records.
     *
     * @throws MediaFolderRepositoryException
     */
    public function countItems(string $folderId): int;
}
