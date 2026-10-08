/**
 * The only file that formats a number the user reads (numbers.md).
 *
 * An exact value (an amount, a rate, a multiplier) arrives from PHP as a decimal string with its
 * decimals fixed by the value object that sent it: `'1500.50'`, `'5.00'`. It turns into a number
 * here only on its way into `Intl`, so nothing on a page adds floats and shows the drift.
 */

/**
 * How many decimals a decimal string carries: `'5.00'` → 2, `'800'` → 0. The server decides the
 * precision by what it sends, so the page shows exactly that many.
 */
function decimalsOf(value: string): number {
    const fraction = /\.(\d+)$/.exec(value.trim());

    return fraction === null ? 0 : fraction[1].length;
}

/**
 * An amount in the currency's major unit, in the currency the server shares (`currency`).
 *
 * The decimals are the currency's own (THB 2, JPY 0). `narrowSymbol` keeps one currency reading
 * the same in every locale: `en` would otherwise write THB as `THB 1,500.50` and `th` as `฿1,500.50`.
 */
export function formatMoney(
    amount: string,
    locale: string,
    currency: string,
): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
    }).format(Number(amount));
}

/** A plain decimal, such as a multiplier, with exactly the decimals the server sent. */
export function formatDecimal(value: string, locale: string): string {
    const digits = decimalsOf(value);

    return new Intl.NumberFormat(locale, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(Number(value));
}

/**
 * A rate the server sends in percent (`'5.00'` is 5%), with exactly the decimals it sent. The
 * locale places the sign: `5.00%` in `en`, `5,00 %` in `fr`.
 */
export function formatPercent(value: string, locale: string): string {
    const digits = decimalsOf(value);

    return new Intl.NumberFormat(locale, {
        style: 'percent',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(Number(value) / 100);
}

/**
 * Two decimal strings summed as integers at the finer of their two scales, so the total a page
 * shows before submitting is the total the server computes, not `1751.2500000000002`.
 */
function combine(amount: string, other: string, sign: 1 | -1): string | null {
    const scale = Math.max(decimalsOf(amount), decimalsOf(other));
    const factor = 10 ** scale;
    const total =
        Math.round(Number(amount) * factor) +
        sign * Math.round(Number(other) * factor);

    if (!Number.isFinite(total)) {
        return null;
    }

    return (total / factor).toFixed(scale);
}

/** The sum of two amounts as a decimal string, or `null` when either is not a number. */
export function addMoney(amount: string, other: string): string | null {
    return combine(amount, other, 1);
}

/**
 * The difference of two amounts as a decimal string, or `null` when either is not a number.
 *
 * It may be negative on purpose: it is the balance shown before submitting, and clipping it at
 * zero would make an amount larger than the balance look like it fits. The caller decides what a
 * negative result means.
 */
export function subtractMoney(amount: string, other: string): string | null {
    return combine(amount, other, -1);
}

const BYTE_UNITS = ['B', 'KB', 'MB', 'GB'] as const;

/** A file size, where 1024 bytes are 1 KB, as Laravel's validation counts kilobytes. */
export function formatBytes(bytes: number): string {
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const digits = unit === 0 || value >= 100 ? 0 : 1;

    return `${value.toFixed(digits)} ${BYTE_UNITS[unit]}`;
}
