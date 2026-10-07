# Dates

A date the user reads is formatted in one file, pinned to one time zone:

```
PHP row / props
  a point in time   → toIso8601String()   'created_at' => '2026-10-05T14:30:00+07:00'
  a calendar day    → 'Y-m-d'             'due_date'   => '2026-10-05'
    → useTranslation(): { locale, timezone }               the shared props (list-pages.md)
    → @/lib/dates: formatDateTime | formatDate (value, locale, timezone)
                   formatCalendarDate (value, locale)       read and written as UTC
                   weekdayName (1–7, locale)
    → the text on the page, the same on the server and in the browser
```

Enforced by the package's `tests/Architecture/DatesTest.php` and by the package's ESLint rules in `tests/ESLint/dates.js`, which `eslint.config.js` imports from `vendor/` and spreads in. The spec in `datesSpec()` is the machine-checked copy of this file: change the two together. The package's `tests/Architecture/DatesEslintTest.php` proves the ESLint rules against fixtures. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Kit files

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`). Copy the rest into a new project as they are.
- `resources/js/lib/dates.ts`, the only file that formats a date
- *package:* `tests/ESLint/dates.js`, imported by `eslint.config.js` (*check `eslint`*), and the fixtures its proof lints in `tests/ESLint/Fixtures/`
- *package:* `tests/ESLint/Support/rules.js`, which every ESLint kit file reads its overrides and shared helpers through

## Formatting

**Do**
- Format a point in time (`created_at`, `occurred_at`) with `formatDateTime()`, or `formatDate()` when the time adds nothing. Pass the `locale` and `timezone` that `useTranslation()` reads from the shared props. *Review only.*
- Format a calendar day that PHP sends as `Y-m-d` with `formatCalendarDate()`. A calendar day is not a point in time, so it takes no time zone. *Review only.*
- Name an ISO weekday (1 = Monday … 7 = Sunday) with `weekdayName()`. *Review only.*
- Send a point in time from PHP as ISO 8601 with its offset (`toIso8601String()`), and a calendar day as `Y-m-d`. Never send text already formatted for one locale. *Review only.*
- Add a new kind of date format to `lib/dates.ts`, pinned to a time zone, rather than formatting it where it is shown. *Review only.*

**Don't**
- Use `Intl.DateTimeFormat` anywhere but `lib/dates.ts`. *ESLint check `intl-date`.*
- Call `toLocaleString()`, `toLocaleDateString()` or `toLocaleTimeString()` anywhere but `lib/dates.ts`. A number's `toLocaleString()` is caught too, because the check cannot tell a number from a date, and it reads the runtime's locale the same way. Format a number through `@/lib/numbers` (numbers.md). *ESLint check `to-locale`.*

**Why:** Inertia renders a page twice, once on the server and once in the browser. A formatter left to the runtime's time zone reads UTC on the server and the viewer's zone in the browser, so the two disagree about the hour and, near midnight, about the day. React then fails to hydrate and throws the server's markup away. A `Y-m-d` day read as a local time moves back one day for every viewer west of UTC. Pinning both in one file means a page cannot get either wrong.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "dates"`, exactly as stack.md describes. The `subject` is the file's path from the project root. Only the user may add an entry.
