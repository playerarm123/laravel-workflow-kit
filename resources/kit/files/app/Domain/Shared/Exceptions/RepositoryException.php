<?php

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\DomainException;
use Throwable;

/**
 * Every way a repository can fail, as one exception per aggregate (`{Aggregate}RepositoryException`)
 * so callers catch the aggregate they asked about, never a framework class.
 *
 * @phpstan-consistent-constructor คลาสลูกต้องคงลายเซ็นของ DomainException ไว้ ไม่งั้น factory ทุกตัวพัง
 */
abstract class RepositoryException extends DomainException
{
    const int SAVE_FAILED_CODE = 100;

    const int RECONSTITUTE_FAILED_CODE = 101;

    const int DELETE_FAILED_CODE = 102;

    const int QUERY_FAILED_CODE = 103;

    const int NOT_CLONABLE_CODE = 104;

    abstract public static function entityName(): string;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function saveFailed(Throwable $previous, array $payload = [], string $message = 'Failed to save entity to repository.'): static
    {
        return new static(
            message: $message,
            code: static::SAVE_FAILED_CODE,
            context: [
                'payload' => $payload,
            ],
            previous: $previous,
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function reconstituteFailed(string $entityId, string $message, ?Throwable $previous = null, array $payload = []): static
    {
        $entityName = static::entityName();

        return new static(
            message: "{$entityName} Entity [{$entityId}] cannot be reconstitute data : {$message}",
            code: static::RECONSTITUTE_FAILED_CODE,
            context: [
                'payload' => $payload,
            ],
            previous: $previous,
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function deleteFailed(Throwable $previous, array $payload = []): static
    {
        return new static(
            message: 'Failed to delete entity from repository.',
            code: static::DELETE_FAILED_CODE,
            context: [
                'payload' => $payload,
            ],
            previous: $previous,
        );
    }

    /**
     * A read the database refused — a broken query or a lost connection, never "no row".
     */
    public static function queryFailed(Throwable $previous): static
    {
        $entityName = static::entityName();

        return new static(
            message: "Failed to read {$entityName} from repository.",
            code: static::QUERY_FAILED_CODE,
            previous: $previous,
        );
    }

    /**
     * The aggregate does not implement ClonableAggregate, so nothing may copy it.
     */
    public static function notClonable(string $entityId): static
    {
        $entityName = static::entityName();

        return new static(
            message: "{$entityName} Entity [{$entityId}] cannot be cloned.",
            code: static::NOT_CLONABLE_CODE,
            context: [
                'entityId' => $entityId,
            ],
        );
    }
}
