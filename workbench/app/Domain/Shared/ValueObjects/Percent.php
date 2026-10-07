<?php

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidPercentException;
use App\Domain\Shared\ValueObjects\Concerns\ParsesScaledDecimal;

/**
 * A rate, held as an integer in basis points (500 = 5%), never as a float
 * (numbers.md). It is never negative.
 */
final readonly class Percent
{
    use ParsesScaledDecimal;

    /** Basis points per percent, as a power of ten: 2 means 100. */
    private const int DECIMALS = 2;

    private const int BASIS_POINTS_PER_WHOLE = 10_000;

    private function __construct(
        public int $basisPoints,
    ) {
        if ($basisPoints < 0) {
            throw InvalidPercentException::negative($basisPoints);
        }
    }

    /** A rate in basis points, as the database stores it. */
    public static function fromBasisPoints(int $basisPoints): self
    {
        return new self($basisPoints);
    }

    /**
     * A rate in percent, as a form posts it and a page reads it: `'5.25'`.
     *
     * It reads the string exactly. A string that is not a decimal, or carries more decimals than
     * a basis point holds, is an invalid value: validate the field with `decimal:0,2`.
     */
    public static function fromPercent(string $percent): self
    {
        $basisPoints = self::scaledInteger($percent, self::DECIMALS);

        if ($basisPoints === null) {
            throw InvalidPercentException::malformed($percent);
        }

        return new self($basisPoints);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->basisPoints + $other->basisPoints);
    }

    public function subtract(self $other): self
    {
        return new self($this->basisPoints - $other->basisPoints);
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }

    public function greaterThan(self $other): bool
    {
        return $this->basisPoints > $other->basisPoints;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->basisPoints >= $other->basisPoints;
    }

    public function lessThan(self $other): bool
    {
        return $this->basisPoints < $other->basisPoints;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->basisPoints <= $other->basisPoints;
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    public function isPositive(): bool
    {
        return $this->basisPoints > 0;
    }

    /**
     * The rate in percent as a decimal string, its decimals fixed: 500 is `'5.00'`.
     *
     * This is what a Row, a Result or a FormValues sends to the page, which formats it with
     * `formatPercent()`.
     */
    public function toPercent(): string
    {
        return self::decimalString($this->basisPoints, self::DECIMALS);
    }

    /**
     * This rate of an amount, rounded half up to the minor unit, in integers only: 5% of 10.01
     * baht is 0.50 baht.
     */
    public function applyTo(Money $money): Money
    {
        $scaled = $money->amount * $this->basisPoints;

        return Money::from(intdiv($scaled + intdiv(self::BASIS_POINTS_PER_WHOLE, 2), self::BASIS_POINTS_PER_WHOLE));
    }

    /** The rate for a log line or an exception message: `'5.00%'`. */
    public function format(): string
    {
        return $this->toPercent().'%';
    }
}
