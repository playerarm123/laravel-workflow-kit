<?php

namespace App\Domain\Shared\ValueObjects;

final class EmailAddress
{
    private function __construct(
        private readonly string $value,
    ) {}

    public static function from(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
