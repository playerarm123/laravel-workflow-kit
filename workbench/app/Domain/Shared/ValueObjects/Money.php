<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidMoneyException;
use App\Domain\Shared\ValueObjects\Concerns\ParsesScaledDecimal;

/**
 * An amount of money, held as an integer in minor units (satang, cents), never as a float
 * (numbers.md).
 *
 * It carries no currency. A project keeps one currency, named by `config('app.currency')` and
 * shared with the page, and every amount it stores is in it. A project that needs several
 * currencies stores the currency beside every amount, which this class does not do.
 *
 * It is never negative. A balance that would go below zero is refused first by the entity that
 * owns it, with a refusal of its own (exceptions.md).
 */
final readonly class Money
{
    use ParsesScaledDecimal;

    /** Minor units per major unit, as a power of ten: 2 means 100 satang to the baht. */
    private const int DECIMALS = 2;

    private function __construct(
        public int $amount,
    ) {
        if ($amount < 0) {
            throw InvalidMoneyException::negativeAmount($amount);
        }
    }

    /** An amount in minor units, as the database stores it. */
    public static function from(int $amount): self
    {
        return new self($amount);
    }

    /**
     * An amount in major units, as a form posts it and a page reads it: `'1500.50'`.
     *
     * It reads the string exactly. A string that is not a decimal, or carries more decimals than
     * a minor unit holds, is an invalid value: validate the field with `decimal:0,2`.
     */
    public static function fromMajorUnit(string $amount): self
    {
        $scaled = self::scaledInteger($amount, self::DECIMALS);

        if ($scaled === null) {
            throw InvalidMoneyException::malformed($amount);
        }

        return new self($scaled);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->amount + $other->amount);
    }

    public function subtract(self $other): self
    {
        return new self($this->amount - $other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        return $this->amount > $other->amount;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->amount >= $other->amount;
    }

    public function lessThan(self $other): bool
    {
        return $this->amount < $other->amount;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->amount <= $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * The amount in major units as a decimal string, its decimals fixed: 500 baht is `'500.00'`.
     *
     * This is what a Row, a Result or a FormValues sends to the page, which formats it with
     * `formatMoney()`. It is a string, so nobody adds it up by accident: arithmetic goes through
     * `add()` and `subtract()`.
     */
    public function toMajorUnit(): string
    {
        return self::decimalString($this->amount, self::DECIMALS);
    }

    /**
     * The amount for a log line or an exception message, with thousands separators:
     * `'1,500.50'`. A page never shows it; it formats `toMajorUnit()` in the viewer's locale.
     */
    public function format(): string
    {
        [$whole, $fraction] = explode('.', $this->toMajorUnit());

        return number_format((int) $whole).'.'.$fraction;
    }
}
