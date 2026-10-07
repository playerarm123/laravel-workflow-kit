<?php

namespace App\Domain\Shared\ValueObjects\Concerns;

/**
 * Reads a decimal string into the integer a value object stores, digit by digit, never through
 * a float (numbers.md).
 *
 * It accepts what Laravel's `decimal:0,{n}` rule accepts: an optional sign, digits on either
 * side of an optional point (`'1500.50'`, `'.5'`, `'5.'`, `'+5'`). Trailing zeros past the scale
 * are dropped (`'1.500'` at scale 2). Anything else is null, and the value object turns that
 * into its own invalid-value exception: the FormRequest should have stopped it.
 */
trait ParsesScaledDecimal
{
    /** The most digits a scaled value may carry and still fit a 64-bit integer. */
    private const int MAX_SCALED_DIGITS = 18;

    private static function scaledInteger(string $value, int $decimals): ?int
    {
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $parts) !== 1) {
            return null;
        }

        $sign = $parts[1];
        $whole = $parts[2];
        $fraction = $parts[3] ?? '';

        if ($whole === '' && $fraction === '') {
            return null;
        }

        $fraction = rtrim($fraction, '0');

        if (strlen($fraction) > $decimals) {
            return null;
        }

        $digits = ltrim($whole.str_pad($fraction, $decimals, '0'), '0');

        if (strlen($digits) > self::MAX_SCALED_DIGITS) {
            return null;
        }

        $scaled = (int) $digits;

        return $sign === '-' ? -$scaled : $scaled;
    }

    /**
     * The decimal string of a scaled integer, with exactly `$decimals` decimals: 150050 at
     * scale 2 is `'1500.50'`. It never passes through a float either.
     */
    private static function decimalString(int $scaled, int $decimals): string
    {
        $digits = str_pad((string) abs($scaled), $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, strlen($digits) - $decimals);
        $fraction = substr($digits, strlen($digits) - $decimals);

        return ($scaled < 0 ? '-' : '').$whole.($decimals > 0 ? '.'.$fraction : '');
    }
}
