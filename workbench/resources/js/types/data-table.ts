export type DtRowData = {
    id: string;
};

/**
 * The filters every list page shares. The keys match `search` + `DateRange::toFilters()` in
 * `Criteria::toFilters()` on the PHP side. A date is `YYYY-MM-DD` (a day in the app's timezone), or
 * `null` when it does not filter. A page extends it with its domain filters: `DtFilters & { role: … }`
 *
 * @see app/Application/Concerns/DateRange.php
 */
export type DtFilters = {
    search: string;
    created_from: string | null;
    created_to: string | null;
};
