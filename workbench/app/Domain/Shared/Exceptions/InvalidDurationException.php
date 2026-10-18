<?php

namespace App\Domain\Shared\Exceptions;

/**
 * A length of time the domain refuses to hold. It is the caller's bug, because the FormRequest
 * or the entity that computes it should have stopped it, so no entry point catches it
 * (exceptions.md).
 */
final class InvalidDurationException extends DomainValueException
{
    public const int NEGATIVE = 100;

    /**
     * @param  array<string, mixed>  $context
     */
    public static function create(string $message, int $code, array $context = []): self
    {
        return new self(
            message: "Invalid duration : {$message}.",
            code: $code,
            context: $context,
        );
    }

    public static function negative(int $seconds): self
    {
        return self::create('the duration cannot be negative', self::NEGATIVE, ['seconds' => $seconds]);
    }
}
