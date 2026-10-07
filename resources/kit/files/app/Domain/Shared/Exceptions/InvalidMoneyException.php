<?php

namespace App\Domain\Shared\Exceptions;

/**
 * A value the domain refuses to hold. It is the caller's bug, because the FormRequest should
 * have stopped it, so no entry point catches it (exceptions.md).
 *
 * A balance that would go below zero is not this exception's job: the entity that owns the
 * balance checks it first and throws its own refusal.
 */
final class InvalidMoneyException extends DomainValueException
{
    public const int NEGATIVE_AMOUNT = 100;

    public const int MALFORMED = 101;

    /**
     * @param  array<string, mixed>  $context
     */
    public static function create(string $message, int $code, array $context = []): self
    {
        return new self(
            message: "Invalid money : {$message}.",
            code: $code,
            context: $context,
        );
    }

    public static function negativeAmount(int $amount): self
    {
        return self::create('the amount cannot be negative', self::NEGATIVE_AMOUNT, ['amount' => $amount]);
    }

    public static function malformed(string $amount): self
    {
        return self::create('the amount is not a decimal with at most two decimals', self::MALFORMED, ['amount' => $amount]);
    }
}
