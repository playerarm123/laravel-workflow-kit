<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Domain\Shared\DomainEntity;

final class CrateItemEntity extends DomainEntity
{
    public function __construct(
        private readonly string $id,
        public readonly string $sku,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public static function entityName(): string
    {
        return 'CrateItem';
    }
}
