# Numbers

An exact number (an amount, a rate, a multiplier) never becomes a float on its way. It travels as a decimal string, and the page formats it in one file:

```
database: an integer in minor units                      150050 satang, 500 basis points (migrations.md)
  → value object → to…(): string, its decimals fixed     '1500.50', '5.00'
  → Row / Result / FormValues / props
    → useTranslation(): { locale, currency }             the shared props
    → @/lib/numbers: formatMoney (value, locale, currency)
                     formatPercent | formatDecimal (value, locale)
                     addMoney | subtractMoney (value, other)
    → the text on the page

form input '1500.50' → FormRequest (decimal:0,2) → Command: the string or the value object
  → value object ::from…('1500.50') → the integer the database stores
```

Enforced by the package's `tests/Architecture/NumbersTest.php` and by the package's ESLint rules in `tests/ESLint/numbers.js`, which `eslint.config.js` imports from `vendor/` and spreads in. The spec in `numbersSpec()` is the machine-checked copy of this file: change the two together. The package's `tests/Architecture/NumbersEslintTest.php` proves the ESLint rules against fixtures. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Kit files

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`). `php artisan kit:install` writes the rest from the kit, and the check fails when one is missing or differs from the kit's copy.
- `app/Domain/Shared/ValueObjects/Money.php` and `Percent.php`, with `Concerns/ParsesScaledDecimal.php`, which reads a decimal string into their integer
- `app/Domain/Shared/Exceptions/InvalidMoneyException.php` and `InvalidPercentException.php`
- `tests/Unit/Domain/Shared/ValueObjects/MoneyTest.php` and `PercentTest.php`
- `resources/js/lib/numbers.ts`, the only file that formats a number
- *package:* `tests/ESLint/numbers.js`, imported by `eslint.config.js` (*check `eslint`*), and the fixtures its proof lints in `tests/ESLint/Fixtures/`
- *package:* `tests/ESLint/Support/rules.js`, which every ESLint kit file reads its overrides and shared helpers through

## Value objects

`Money` holds an amount as an integer in minor units, and `Percent` holds a rate in basis points. Neither is ever negative, and neither carries a currency: a project keeps one, `config('app.currency')`, and every amount it stores is in it.

**Do**
- Hold every amount in the domain as `Money` and every rate as `Percent`. Store `->amount` and `->basisPoints` in an integer column (migrations.md), and read them back with `Money::from()` and `Percent::fromBasisPoints()`. *Review only.*
- Build one from what a form posts with `Money::fromMajorUnit('1500.50')` or `Percent::fromPercent('5.25')`. Both read the string digit by digit and refuse anything that is not a decimal of at most two decimals with an invalid-value exception, so validate the field with `decimal:0,2` (and `min:` / `max:`) first. *Review only.*
- Send one to the page with `toMajorUnit()` or `toPercent()`, and write one into a log line or an exception message with `format()`. *Review only.*
- Let the entity that owns a balance refuse a change that would take it below zero, with a refusal of its own, before `Money` would refuse it as an invalid value (exceptions.md). *Review only.*
- Give any other exact quantity (a multiplier, a weight) a value object of its own in the same shape: an integer at a fixed scale, `from…(string)` through `ParsesScaledDecimal`, and a `to…(): string`. *Review only.*

**Don't**
- Hand a value object a float, or turn its integer into one to compute: rates and multipliers apply in integers (`Percent::applyTo()`). *Review only.*

**Why:** a float rounds `0.29 × 100` to `28.999999999999996`, so a cast to int loses a satang that nobody can explain. Reading the string exactly also refuses `'abc'` instead of storing it as zero.

## On the wire

**Do**
- Send an exact number as a decimal string in the unit the user reads, with its decimals fixed by the value object that holds it: `'1500.50'` for an amount, `'5.00'` for a rate in percent. The number of decimals the server sends is the precision the page shows. *Review only.*
- Take it back the same way. A form posts the decimal string, the FormRequest validates it with `decimal:0,2`, and the Command carries the string or the value object built from it. The value object alone turns it into the integer the database stores (migrations.md). *Review only.*
- Share the currency as the `currency` prop, read from `config('app.currency')`, and return it from `useTranslation()` beside `locale` and `timezone`. *Check `shared-props`.*

**Don't**
- Carry a `float` in a payload: a property of a Command, a Result, a Data, an item nested in one, a Criteria, a list Row or a FormValues, or a `float` in the `@return array{…}` of its `toArray()`. *Check `no-float`.*
- Send an integer in minor units (`150050`, `500`) and leave the page to divide it. Every page then repeats the scale, and one of them gets it wrong. *Review only.*

**Why:** a float cannot hold 0.10 exactly, so an amount that passes through one comes out a satang off, and JSON keeps whatever drift it picked up. A decimal string cannot be added by accident, in PHP or in TypeScript. The value object is then the one place that knows the scale, on the way in and on the way out.

## Formatting

**Do**
- Format an amount with `formatMoney()`, passing the `locale` and `currency` that `useTranslation()` reads. The decimals are the currency's own. *Review only.*
- Format a rate in percent with `formatPercent()`, and any other exact decimal (a multiplier) with `formatDecimal()`. Both show the decimals the string carries. *Review only.*
- Add or subtract amounts on the page, such as a balance shown before submitting, with `addMoney()` and `subtractMoney()`. They count in integers at the strings' own scale, so the page shows what the server will compute. *Review only.*
- Add a new kind of number format to `lib/numbers.ts` rather than formatting it where it is shown. *Review only.*

**Don't**
- Use `Intl.NumberFormat` anywhere but `lib/numbers.ts`. *ESLint check `intl-number`.*
- Call `toFixed()` anywhere but `lib/numbers.ts`. It rounds a float and ignores the locale. *ESLint check `to-fixed`.*
- Do arithmetic on an amount with `Number()` or `parseFloat()` outside the kit. *Review only.*

A number's `toLocaleString()` is already caught by dates.md's `to-locale`.

**Why:** a page that formats on its own copies the scale, the decimals and the currency into one more place. The copies drift: one page shows `5%`, the next `5.00%`, and a third forgets the currency when the locale changes.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "numbers"`, exactly as stack.md describes. The `subject` is the class for `no-float`, and the file's path from the project root for an ESLint check. Only the user may add an entry.
