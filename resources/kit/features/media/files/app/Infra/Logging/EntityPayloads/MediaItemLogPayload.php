<?php

namespace App\Infra\Logging\EntityPayloads;

use App\Domain\Media\MediaItem\MediaItemEntity;
use App\Domain\Shared\DomainEntity;

final class MediaItemLogPayload extends EntityLogPayload
{
    /**
     * Ids, sizes and the mime type only: the name and alt text are typed by a user and stay out
     * (repositories.md).
     */
    protected function map(DomainEntity $entity): array
    {
        /** @var MediaItemEntity $entity */

        return [
            'folder_id' => $entity->folderId(),
            'mime_type' => $entity->file()->mimeType(),
            'size_bytes' => $entity->file()->sizeBytes(),
        ];
    }
}
