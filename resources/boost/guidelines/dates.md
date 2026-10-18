# Dates

The domain holds time in a few kit types and asks one port what time it is. A date the user reads is formatted in one file, pinned to one time zone:

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

These live at fixed paths. *Check `kit-files`.* The ones marked *package* ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`). `php artisan kit:install` writes the rest from the kit, and the check fails when one is missing or differs from the kit's copy.
- `app/Domain/Shared/ValueObjects/CalendarDate.php`, `CalendarPeriod.php`, `TimeOfDay.php` and `Duration.php`, and the enum `app/Domain/Shared/Enums/Weekday.php`
- `app/Domain/Shared/Exceptions/InvalidCalendarDateException.php`, `InvalidCalendarPeriodException.php`, `InvalidTimeOfDayException.php` and `InvalidDurationException.php`
- `app/Domain/Shared/Ports/Clock.php`, the port the domain asks the time, with its adapter `app/Infra/Time/SystemClock.php`, bound in `app/Infra/Time/TimeServiceProvider.php`, which `bootstrap/providers.php` registers
- `tests/Unit/Domain/Shared/ValueObjects/CalendarDateTest.php`, `CalendarPeriodTest.php`, `TimeOfDayTest.php` and `DurationTest.php`, and `tests/Feature/Infra/Time/SystemClockTest.php`, which fails while the provider is not registered
- `resources/js/lib/dates.ts`, the only file that formats a date
- *package:* `tests/ESLint/dates.js`, imported by `eslint.config.js` (*check `eslint`*), and the fixtures its proof lints in `tests/ESLint/Fixtures/`
- *package:* `tests/ESLint/Support/rules.js`, which every ESLint kit file reads its overrides and shared helpers through

## Value objects

| Type | Holds | Stored as | Sent to the page as |
|---|---|---|---|
| `CalendarDate` | a day, no time, no zone: a due date, a birthday | a `date` column, read back with `CalendarDate::fromString()` | `toString()`: `'2026-10-05'` |
| `CalendarPeriod` | a run of days, both ends inside it | two `date` columns | its `start` and `end`, each `toString()` |
| `TimeOfDay` | a time on the clock face, to the minute: when a shop opens | a `time` column, or an integer of `->minutes` | `toString()`: `'09:30'` |
| `Duration` | a length of time, in whole seconds: how long an invitation lasts | an integer of `->seconds` | `->seconds` |
| `Weekday` (enum) | a day of the week, valued 1 (Monday) to 7 (Sunday) as ISO numbers it: an opening day | an integer of `->value` | `->value`, which `weekdayName()` names |
| `DateTimeImmutable` | a point in time: when something happened or will | a `timestamp` column | ISO 8601 with its offset |

Each is `final readonly`, compares with `equals()` and its own comparisons, and refuses a value it cannot hold with its own invalid-value exception (exceptions.md). None is ever negative or out of range.

**Do**
- Hold a day in the domain as `CalendarDate`, a run of days as `CalendarPeriod`, a time a rule repeats at as `TimeOfDay`, a length of time as `Duration`, and a day of the week as `Weekday`, which `CalendarDate::weekday()` returns. *Review only.*
- Hold a point in time as a `DateTimeImmutable`. It is immutable already, so the kit gives it no type of its own. *Review only.*
- Build one from what a form posts with `CalendarDate::fromString()`, `CalendarPeriod::fromStrings()` or `TimeOfDay::fromString()`. Each reads the string exactly and refuses anything else, so validate the field first: `date_format:Y-m-d`, `after_or_equal:` for a period's end, `date_format:H:i`. *Review only.*
- Move a day by months with `addMonths()`, which keeps the day or takes the last day of a shorter month: January 31 plus one month is February 28. *Review only.*
- Read the points in time that fall on a day from `startsIn($zone)` up to, not including, `addDays(1)->startsIn($zone)`, in the zone the day is meant in. *Review only.*
- Write one into a log line or an exception message with `toString()` or `format()`. *Review only.*

**Don't**
- Hold a day as a `DateTimeImmutable` at midnight, or a time of day as a date with a made-up day. The zone it carries moves the day, and the made-up day leaks into comparisons. *Review only.*
- Hold a length in days or months as a `Duration`. A day is not always 24 hours across a clock change, and a month has no fixed length: move a `CalendarDate` instead. *Review only.*

**Why:** a day read as midnight in one zone is the day before in a zone west of it, so a due date moves for some users and not others. `DateInterval` cannot be compared or added, so a rule written with one turns into string formats and casts. One type per kind of time puts each of those rules in one place, with a test.

## Now

**Do**
- Ask what time it is through `App\Domain\Shared\Ports\Clock`: `now()` for the moment, `today()` for the day in the app's time zone. Inject it into the handler or the domain service that needs it, and hand the entity the moment or the day it decides on. *Review only.*
- Fix the time in a test with `$this->travelTo()`. `SystemClock` reads Laravel's clock, so the domain sees the time the test chose. *Review only.*

**Don't**
- Read the current time in `app/Domain` or `app/Application` with `new DateTimeImmutable()`, `time()`, `date()`, `now()` or `Carbon::now()`. *Review only.*

**Why:** a rule that reads the clock itself cannot be tested at a time of the test's choosing, and "today" read in UTC is yesterday for a user east of it until morning. One port answers both, in the app's zone.

## Formatting

**Do**
- Format a point in time (`created_at`, `occurred_at`) with `formatDateTime()`, or `formatDate()` when the time adds nothing. Pass the `locale` and `timezone` that `useTranslation()` reads from the shared props. *Review only.*
- Format a calendar day that PHP sends as `Y-m-d` with `formatCalendarDate()`. A calendar day is not a point in time, so it takes no time zone. *Review only.*
- Name a weekday with `weekdayName()`, from the `Weekday` value PHP sends (1 = Monday … 7 = Sunday). *Review only.*
- Send a point in time from PHP as ISO 8601 with its offset (`toIso8601String()`), and a calendar day as `Y-m-d`. Never send text already formatted for one locale. *Review only.*
- Add a new kind of date format to `lib/dates.ts`, pinned to a time zone, rather than formatting it where it is shown. *Review only.*

**Don't**
- Use `Intl.DateTimeFormat` anywhere but `lib/dates.ts`. *ESLint check `intl-date`.*
- Call `toLocaleString()`, `toLocaleDateString()` or `toLocaleTimeString()` anywhere but `lib/dates.ts`. A number's `toLocaleString()` is caught too, because the check cannot tell a number from a date, and it reads the runtime's locale the same way. Format a number through `@/lib/numbers` (numbers.md). *ESLint check `to-locale`.*

**Why:** Inertia renders a page twice, once on the server and once in the browser. A formatter left to the runtime's time zone reads UTC on the server and the viewer's zone in the browser, so the two disagree about the hour and, near midnight, about the day. React then fails to hydrate and throws the server's markup away. A `Y-m-d` day read as a local time moves back one day for every viewer west of UTC. Pinning both in one file means a page cannot get either wrong.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "dates"`, exactly as stack.md describes. The `subject` is the file's path from the project root. Only the user may add an entry.
