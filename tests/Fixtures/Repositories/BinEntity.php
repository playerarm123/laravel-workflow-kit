<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Domain\Shared\AggregateRoot;

final class BinEntity extends AggregateRoot
{
    public function __construct(
        private readonly string $id,
        public readonly string $label,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public static function entityName(): string
    {
        return 'Bin';
    }
}
