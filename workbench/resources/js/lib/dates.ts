/**
 * The only file that formats a date the user reads (dates.md).
 *
 * Every formatter pins its time zone. Inertia renders a page on the server and again in the
 * browser; a formatter left to the runtime's zone gives the two different text, and React then
 * fails to hydrate and throws the server's markup away.
 */

/**
 * The short name of an ISO weekday, 1 = Monday … 7 = Sunday.
 *
 * 2024-01-01 is a Monday, so the day is added to it directly. The date is built and read as UTC;
 * otherwise a viewer west of UTC sees the day before.
 */
export function weekdayName(day: number, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        weekday: 'short',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(2024, 0, day)));
}

/**
 * A point in time (ISO 8601, `created_at`) as a date and a time, in the business time zone
 * the server shares (`timezone`), never the viewer's.
 */
export function formatDateTime(
    value: string,
    locale: string,
    timeZone: string,
): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(new Date(value));
}

/**
 * The day of a point in time, without the time. It is pinned like `formatDateTime`, because
 * near midnight two time zones name different days.
 */
export function formatDate(
    value: string,
    locale: string,
    timeZone: string,
): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeZone,
    }).format(new Date(value));
}

/**
 * A calendar day the server sends as `Y-m-d`, which is not a point in time. `new Date()` reads
 * it as midnight UTC, so it is written out as UTC too; otherwise a viewer west of UTC sees the
 * day before.
 */
export function formatCalendarDate(value: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeZone: 'UTC',
    }).format(new Date(value));
}
