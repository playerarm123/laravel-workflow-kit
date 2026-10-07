<?php

namespace App\Infra\Logging\EntityPayloads;

use App\Domain\Shared\DomainEntity;
use App\Infra\Logging\HasLogPayload;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

/**
 * @phpstan-consistent-constructor คลาสลูกทุกตัวต้องคง __construct(DomainEntity) ไว้ ไม่งั้น from() พัง
 */
abstract class EntityLogPayload implements HasLogPayload, Jsonable, JsonSerializable
{
    public function __construct(
        protected readonly DomainEntity $entity,
    ) {}

    public static function from(DomainEntity $entity): static
    {
        return new static($entity);
    }

    /**
     * @return array<string, mixed>
     */
    final public function payload(): array
    {
        return [
            'id' => $this->entity->id(),
            ...$this->map($this->entity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function map(DomainEntity $entity): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload();
    }

    public function toJson($options = 0): string
    {
        return json_encode(
            $this,
            $options | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
