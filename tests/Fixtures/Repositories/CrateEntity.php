<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Domain\Shared\AggregateRoot;

final class CrateEntity extends AggregateRoot
{
    /**
     * @param  list<CrateItemEntity>  $items
     */
    public function __construct(
        private readonly string $id,
        public readonly string $label,
        public readonly array $items = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public static function entityName(): string
    {
        return 'Crate';
    }
}
