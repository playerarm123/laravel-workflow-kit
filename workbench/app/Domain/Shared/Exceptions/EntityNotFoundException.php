<?php

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\DomainException;

/**
 * @phpstan-consistent-constructor
 */
class EntityNotFoundException extends DomainException
{
    public static function byId(string $entityId, string $message = 'Entity not found.'): static
    {
        return new static(
            message: $message,
            code: 0,
            context: [
                'entityId' => $entityId,
            ],
        );
    }

    public static function byField(string $column, mixed $value, string $message = 'Entity not found.'): static
    {
        return new static(
            message: $message,
            code: 0,
            context: [
                'criteria' => [
                    'column' => $column,
                    'value' => $value,
                ],
            ],
        );
    }
}
