# Stack

The stack is fixed so every project reads and is written the same way. Use only what this file names, on the version line it names, and use the installed version's API — confirm with `composer show <package>` or `package.json` before relying on one.

Enforced by `tests/Architecture/StackTest.php` (`php artisan test --testsuite=Architecture`). The spec in `stackSpec()` is the machine-checked copy of this file: change the two together, in the same commit.

## Required packages, locked to a major
**Do** keep every package below declared and installed on its line. `13` means any 13.x; a 0.x line is locked at the minor (`0.1`) because 0.x minors break like majors.

| Area | Packages |
|---|---|
| Runtime | php 8.4 (`composer.json` constraint starts at 8.4) |
| Framework | laravel/framework 13 |
| Server ↔ client | inertiajs/inertia-laravel 3 · @inertiajs/react 3 · @inertiajs/vite 3 |
| Auth | laravel/fortify 1 · @laravel/passkeys 0.2 |
| DTOs (Command / Result / Data) | spatie/laravel-data 4 |
| Routes → TypeScript | laravel/wayfinder 0.1 · @laravel/vite-plugin-wayfinder 0.1 |
| Logs, monitoring, error tracking | laravel/nightwatch 1 |
| Tests | pestphp/pest 5 · pestphp/pest-plugin-laravel 5 · pestphp/pest-plugin-browser 5 · playwright 1 |
| PHP quality | larastan/larastan 3 · laravel/pint 1 |
| JS quality | eslint 9 · prettier 3 · prettier-plugin-tailwindcss 0.6 |
| UI | react 19 · react-dom 19 · typescript 5 · vite 8 · tailwindcss 4 · @tailwindcss/vite 4 · laravel-vite-plugin 3 |
| Components | shadcn 4 · radix-ui 1 (the single package, not `@radix-ui/*`) |
| Icons | lucide-react 1, and `components.json` `"iconLibrary": "lucide"` |
| Rule kit (dev) | playerarm123/laravel-workflow-kit 0.1, which ships these guidelines and their skills through Boost (`boost.json` `"packages"`) |

**Don't** move a package to a new major on your own. A major upgrade changes this file, `stackSpec()` and the code in one reviewed change.

**Why:** the version decides which API is correct. A locked line keeps generated code valid for the code already there.

## One library per need
**Do** reach for the library listed for a need. The ones not marked required are installed only when a project needs them, and then only on the listed line.

| Need | Use | Never |
|---|---|---|
| Merging class names | `cn()` from `@/lib/utils` (clsx 2 + tailwind-merge 3), the alias shadcn generates against | the `cn` package, classnames |
| Tables | @tanstack/react-table 9 | ag-grid-*, react-data-table-component |
| Diagrams | @xyflow/react 12 (React Flow), laid out with @dagrejs/dagre 3 | reactflow (its old name), dagre (the unscoped, unmaintained one), elkjs, mermaid, cytoscape, jointjs |
| Toasts | sonner 2 | react-toastify, react-hot-toast |
| Dates in JS | date-fns 4 | moment, dayjs, luxon |
| Icons | lucide-react | @phosphor-icons/react, react-icons, @heroicons/react, @tabler/icons-react |
| UI primitives | radix-ui through shadcn | @radix-ui/*, @headlessui/react, @mui/*, @chakra-ui/*, antd, @mantine/* |
| HTTP from the client | Inertia router, `<Form>`, `useHttp` | axios, ky, swr, @tanstack/react-query |
| Frontend | React on Inertia | livewire/livewire, vue, svelte, alpinejs, jquery |
| Logs / monitoring / errors | laravel/nightwatch | sentry/sentry-laravel, bugsnag/*, spatie/laravel-flare, opcodesio/log-viewer |
| Image processing | intervention/image 4 with the GD driver | spatie/image, imagine/imagine |
| Excel / CSV import-export | maatwebsite/excel 4 (Laravel Excel) | spatie/simple-excel, rap2hpoutre/fast-excel, phpoffice/phpspreadsheet required directly |
| PDF | mpdf/mpdf 8, with the Thai font shipped in the project | barryvdh/laravel-dompdf, dompdf/dompdf, spatie/laravel-pdf, spatie/browsershot, knplabs/knp-snappy, barryvdh/laravel-snappy |

**Why:** a second library for the same need splits the codebase into two idioms, and an agent copies whichever one it read last. PDF tools that drive a headless browser cannot run on the deploy targets (see deployment.md).

## Optional capabilities
**Do** add these only when a feature needs them, after the user approves. Install exactly what is listed.

| Capability | Use | Notes |
|---|---|---|
| Faster cache / queue / session | Redis through the **phpredis** extension (`REDIS_CLIENT=phpredis`) | `database` stays the default driver; an environment switches to redis through its own env. Never predis/predis, memcached, dynamodb or sqs. |
| Realtime broadcasting | Pusher is the pre-approved option: pusher/pusher-php-server 7 · laravel-echo 2 · pusher-js 8 | Not mandatory. Another broadcaster needs approval like any new dependency. |
| Queue dashboard / dedicated workers | laravel/horizon 5 | Only on a redis queue, for features that need supervised workers of their own. |
| Notifications: mail | Laravel Notifications `mail` channel | The provider is chosen by `MAIL_MAILER` only. Never call a mail provider's SDK from code. |
| Notifications: in-app | `database` channel | Laravel's `notifications` table. |
| Notifications: realtime | `broadcast` channel | Through the project's broadcaster. |
| Notifications: Slack | laravel/slack-notification-channel 3 | |
| Notifications: Telegram | laravel-notification-channels/telegram 8 | |
| Notifications: LINE | A custom notification channel in the infrastructure layer that calls the LINE Messaging API through Laravel's HTTP client | LINE Notify is shut down. Never linecorp/line-bot-sdk or other LINE packages. |

**Audit log** is not optional and uses no package. Every project ships the `AuditLog` port, its database adapter and the `audit_entries` table, and every handler that writes records through it inside its own transaction (audit-log.md). Never spatie/laravel-activitylog or owen-it/laravel-auditing: repositories write with upsert/insert, which fires no model events, so event-based auditing misses most writes.

## Database
**Do** declare the engine with `DB_CONNECTION` in `.env.example`: `pgsql`, `mysql` or `mariadb`. Set the same value in `phpunit.xml`, so tests run on the engine production runs.

**Don't** test on sqlite. **Don't** use an engine-specific feature (deferrable constraints, generated columns, partial indexes, …) unless it belongs to the declared engine. `jsonb()` is not one: Laravel writes it as `json` on mysql/mariadb. The shape of the schema itself is migrations.md.

**Why:** behaviour that differs between engines — constraints, json, collation — is exactly what a different test engine hides.

## Adding any other dependency
**Do** ask the user before installing a package this file does not list, and update this file and `stackSpec()` in the same change once it is approved.

## Stepping outside this file
**Do** record an approved exception in `rule-overrides.json` at the project root:

```json
{ "overrides": [
  { "rule": "stack", "check": "forbidden", "subject": "moment",
    "reason": "A vendored chart library pins moment as a peer dependency",
    "approved_by": "Tech lead", "date": "2026-10-02" }
] }
```

`check` and `subject` are the two names a failing test prints as `[stack:<check>] <subject>`. An entry exempts only that pair. An entry missing a field, with a reason under 20 characters or a date that is not `Y-m-d` exempts nothing and fails `RuleOverridesTest`.

**Don't** add or edit an override yourself. Only the user may, and only with a reason. When a rule blocks you, stop and ask.
