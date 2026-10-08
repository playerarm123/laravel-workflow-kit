import { format, isValid, parse } from 'date-fns';
import { useEffect, useRef, useState } from 'react';
import type { DateRange } from 'react-day-picker';
import { formatDateRange } from '@/components/ui/date-range-picker';
import type { DtFilters } from '@/types';
import type { DtQuery } from './use-data-table';
import { DT_QUERY_KEYS } from './use-list-query';
import type { ListQuery } from './use-list-query';
import { useTranslation } from './use-translation';

const SEARCH_DEBOUNCE_MS = 300;

/** The date format on the URL. Matches `DateRange::FORMAT` on the PHP side */
const DAY_FORMAT = 'yyyy-MM-dd';

/** The chip key of the created-at range. It cannot clash with a domain filter key, because no domain has this key */
const CREATED_AT_KEY = 'created_at';

/** A filter key that belongs to the domain: not sort/direction/per_page, and not search/created_* */
export type DtDomainFilterKey<TQuery> = Exclude<
    keyof TQuery,
    keyof DtQuery | keyof DtFilters
> &
    string;

export type DtFilterOption = {
    value: string;
    /** Translation key, passed through `t()` for you */
    label: string;
};

/** A single-value filter: drawn as a Select in the dialog, and a chip that translates the value's name from `options` */
export type DtSelectFilterField<TQuery> = {
    type?: 'select';
    key: DtDomainFilterKey<TQuery>;
    /** Translation key of the filter's name */
    label: string;
    options: DtFilterOption[];
};

/**
 * A date range filter: two keys on the URL (`from`/`to`, each `Y-m-d` or null), drawn as
 * one `DateRangePicker` in the dialog and one chip that clears both when removed.
 */
export type DtDateRangeFilterField<TQuery> = {
    type: 'date-range';
    fromKey: DtDomainFilterKey<TQuery>;
    toKey: DtDomainFilterKey<TQuery>;
    /** Translation key of the filter's name */
    label: string;
};

/** One domain filter. No `type` means a Select */
export type DtFilterField<TQuery> =
    DtSelectFilterField<TQuery> | DtDateRangeFilterField<TQuery>;

/** The dialog's values not sent yet. Sent once, on `apply()` */
export type DtFilterDraft<TQuery> = {
    dateRange: DateRange | undefined;
    /** The value per domain key. A domain date range lives here too, as two keys, with no separate store */
    values: Partial<Record<DtDomainFilterKey<TQuery>, string | null>>;
};

/** One condition really filtering (from `dt.query`). The toolbar draws it as a chip */
export type DtActiveFilter = {
    key: string;
    label: string;
    value: string;
    remove: () => void;
};

export type DtFilterDialog<TQuery> = {
    open: boolean;
    /** Open = reset the draft from `dt.query`; close = discard the draft */
    setOpen: (open: boolean) => void;
    draft: DtFilterDraft<TQuery>;
    setDateRange: (range: DateRange | undefined) => void;
    setValue: (key: DtDomainFilterKey<TQuery>, value: string | null) => void;
    /** Writes both keys of a domain date range from the picker in one go */
    setRange: (
        field: DtDateRangeFilterField<TQuery>,
        range: DateRange | undefined,
    ) => void;
    /** Clears every filter in the dialog (created-at range + every field) in one send, then closes. Search is untouched */
    reset: () => void;
    /** Sends every value in the draft at once, then closes */
    apply: () => void;
};

export type DataTableToolbarInstance<TQuery extends DtFilters> = {
    /** The value being typed. Not the value the server answered with (that is `dt.query.search`) */
    search: string;
    /** Delayed before sending, or every keystroke would be one request */
    setSearch: (value: string) => void;
    dialog: DtFilterDialog<TQuery>;
    /** Chips of the active conditions (without search, which already shows in its box) */
    active: DtActiveFilter[];
    /** Clears every filter, search included, in one send. sort/direction/per_page stay */
    clear: () => void;
    hasFilters: boolean;
};

export type UseDataTableToolbarOptions<TQuery> = {
    fields: DtFilterField<TQuery>[];
};

export function isDateRangeField<TQuery>(
    field: DtFilterField<TQuery>,
): field is DtDateRangeFilterField<TQuery> {
    return field.type === 'date-range';
}

function parseDay(value: string | null | undefined): Date | undefined {
    if (value === null || value === undefined || value === '') {
        return undefined;
    }

    const day = parse(value, DAY_FORMAT, new Date());

    return isValid(day) ? day : undefined;
}

function formatDay(day: Date | undefined): string | null {
    return day === undefined ? null : format(day, DAY_FORMAT);
}

function rangeOfDays(
    from: string | null | undefined,
    to: string | null | undefined,
): DateRange | undefined {
    const start = parseDay(from);
    const end = parseDay(to);

    return start === undefined && end === undefined
        ? undefined
        : { from: start, to: end };
}

function rangeOf(query: DtFilters): DateRange | undefined {
    return rangeOfDays(query.created_from, query.created_to);
}

/** A domain date range in the draft, so the picker reads its value from the two keys stored as strings */
export function rangeOfDraft<TQuery>(
    draft: DtFilterDraft<TQuery>,
    field: DtDateRangeFilterField<TQuery>,
): DateRange | undefined {
    return rangeOfDays(draft.values[field.fromKey], draft.values[field.toKey]);
}

/** Every URL key a field owns: a Select has one key, a date range two */
function keysOf<TQuery>(
    field: DtFilterField<TQuery>,
): DtDomainFilterKey<TQuery>[] {
    return isDateRangeField(field) ? [field.fromKey, field.toKey] : [field.key];
}

function draftOf<TQuery extends DtFilters>(
    query: TQuery,
    fields: DtFilterField<TQuery>[],
): DtFilterDraft<TQuery> {
    return {
        dateRange: rangeOf(query),
        values: Object.fromEntries(
            fields
                .flatMap(keysOf)
                .map((key) => [key, (query[key] as string | null) ?? null]),
        ) as DtFilterDraft<TQuery>['values'],
    };
}

/**
 * Every key that is not sort/direction/per_page is a filter. Cleared by the same convention
 * `hasActiveFilters` reads: `search` becomes `''`, the rest `null`.
 */
function clearedFilters<TQuery extends object>(query: TQuery): Partial<TQuery> {
    return Object.fromEntries(
        Object.keys(query)
            .filter((key) => !DT_QUERY_KEYS.has(key))
            .map((key) => [key, key === 'search' ? '' : null]),
    ) as Partial<TQuery>;
}

/**
 * The client-side state of the search/filter bar: the search term not sent yet, and the filter dialog's draft.
 * The values in use live on the URL (`dt.query`). The hook sends only through `dt.fetch` and has no router of its own.
 *
 * When `dt.query.search` changes some other way (back button, clearing filters), the screen follows by resetting
 * state during render, not in an effect (eslint forbids setState in an effect), except when the value
 * the server answered with is the one we just sent ourselves: the user may have kept typing, so never pull it back.
 * The draft needs no resync, because it is reset from the query every time the dialog opens.
 */
export function useDataTableToolbar<TQuery extends DtFilters>(
    dt: ListQuery<TQuery>,
    { fields }: UseDataTableToolbarOptions<TQuery>,
): DataTableToolbarInstance<TQuery> {
    const { t, locale } = useTranslation();
    const { query, fetch, hasFilters } = dt;
    const [search, setSearchState] = useState(query.search);
    const [requestedSearch, setRequestedSearch] = useState(query.search);
    const [syncedSearch, setSyncedSearch] = useState(query.search);

    if (syncedSearch !== query.search) {
        setSyncedSearch(query.search);

        if (query.search !== requestedSearch) {
            setSearchState(query.search);
            setRequestedSearch(query.search);
        }
    }

    /**
     * The timer reads the latest `fetch` when it fires, not when it is set. If the user applies a filter while
     * waiting on the debounce, the new value is not lost to an old query captured in the closure.
     */
    const fetchRef = useRef(fetch);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => {
        fetchRef.current = fetch;
    });

    useEffect(() => () => clearTimeout(timer.current), []);

    const setSearch = (value: string): void => {
        setSearchState(value);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            setRequestedSearch(value);
            fetchRef.current({ search: value } as Partial<TQuery>);
        }, SEARCH_DEBOUNCE_MS);
    };

    const [open, setOpenState] = useState(false);
    const [draft, setDraft] = useState<DtFilterDraft<TQuery>>(() =>
        draftOf(query, fields),
    );

    const setOpen = (next: boolean): void => {
        if (next) {
            setDraft(draftOf(query, fields));
        }

        setOpenState(next);
    };

    const apply = (): void => {
        fetch({
            created_from: formatDay(draft.dateRange?.from),
            created_to: formatDay(draft.dateRange?.to),
            ...draft.values,
        } as Partial<TQuery>);
        setOpenState(false);
    };

    /**
     * Clear = send at once, not just clear the draft. A button that changes the screen but not the data is a bug to the user.
     * Send `null` for every key explicitly (rather than dropping the key), because `dt.fetch` merges with the current query:
     * a missing key keeps its old value.
     */
    const reset = (): void => {
        const cleared = Object.fromEntries(
            fields.flatMap(keysOf).map((key) => [key, null]),
        ) as DtFilterDraft<TQuery>['values'];

        setDraft({ dateRange: undefined, values: cleared });
        fetch({
            created_from: null,
            created_to: null,
            ...cleared,
        } as Partial<TQuery>);
        setOpenState(false);
    };

    const dialog: DtFilterDialog<TQuery> = {
        open,
        setOpen,
        draft,
        setDateRange: (dateRange) =>
            setDraft((current) => ({ ...current, dateRange })),
        setValue: (key, value) =>
            setDraft((current) => ({
                ...current,
                values: { ...current.values, [key]: value },
            })),
        setRange: (field, range) =>
            setDraft((current) => ({
                ...current,
                values: {
                    ...current.values,
                    [field.fromKey]: formatDay(range?.from),
                    [field.toKey]: formatDay(range?.to),
                },
            })),
        reset,
        apply,
    };

    const active: DtActiveFilter[] = [];
    const createdRange = rangeOf(query);

    if (createdRange !== undefined) {
        active.push({
            key: CREATED_AT_KEY,
            label: t('data_table.created_between'),
            value: formatDateRange(createdRange.from, createdRange.to, locale),
            remove: () =>
                fetch({
                    created_from: null,
                    created_to: null,
                } as Partial<TQuery>),
        });
    }

    for (const field of fields) {
        if (isDateRangeField(field)) {
            const range = rangeOfDays(
                query[field.fromKey] as string | null,
                query[field.toKey] as string | null,
            );

            if (range === undefined) {
                continue;
            }

            active.push({
                key: field.fromKey,
                label: t(field.label),
                value: formatDateRange(range.from, range.to, locale),
                remove: () =>
                    fetch({
                        [field.fromKey]: null,
                        [field.toKey]: null,
                    } as Partial<TQuery>),
            });

            continue;
        }

        const value = query[field.key];

        if (value === null || value === undefined || value === '') {
            continue;
        }

        const option = field.options.find(
            (candidate) => candidate.value === value,
        );

        active.push({
            key: field.key,
            label: t(field.label),
            value: option === undefined ? String(value) : t(option.label),
            remove: () => fetch({ [field.key]: null } as Partial<TQuery>),
        });
    }

    const clear = (): void => {
        clearTimeout(timer.current);
        setSearchState('');
        setRequestedSearch('');
        fetch(clearedFilters(query));
    };

    return {
        search,
        setSearch,
        dialog,
        active,
        clear,
        hasFilters,
    };
}
