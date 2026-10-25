<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Domain\Shared\DomainEntity;
use App\Infra\Logging\EntityPayloads\EntityLogPayload;

final class FixtureLogPayload extends EntityLogPayload
{
    protected function map(DomainEntity $entity): array
    {
        return [];
    }
}
