<?php

namespace App\Infra\Logging\EntityPayloads;

use App\Domain\Media\MediaFolder\MediaFolderEntity;
use App\Domain\Shared\DomainEntity;

final class MediaFolderLogPayload extends EntityLogPayload
{
    /**
     * Nothing beyond the id: the name is typed by a user and stays out (repositories.md).
     */
    protected function map(DomainEntity $entity): array
    {
        /** @var MediaFolderEntity $entity */

        return [];
    }
}
