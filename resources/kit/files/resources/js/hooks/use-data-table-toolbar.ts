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

/** รูปวันที่บน URL — ตรง `DateRange::FORMAT` ฝั่ง PHP */
const DAY_FORMAT = 'yyyy-MM-dd';

/** คีย์ chip ของช่วงวันที่สร้าง — ไม่ชนกับคีย์ตัวกรองของโดเมนเพราะโดเมนไม่มีคีย์นี้ */
const CREATED_AT_KEY = 'created_at';

/** คีย์ตัวกรองที่เป็นของโดเมน — ไม่ใช่ sort/direction/per_page และไม่ใช่ search/created_* */
export type DtDomainFilterKey<TQuery> = Exclude<
    keyof TQuery,
    keyof DtQuery | keyof DtFilters
> &
    string;

export type DtFilterOption = {
    value: string;
    /** คีย์คำแปล — ผ่าน `t()` ให้ */
    label: string;
};

/** ตัวกรองแบบเลือกค่าเดียว — วาดเป็น Select ใน dialog และ chip ที่แปลชื่อค่าจาก `options` */
export type DtSelectFilterField<TQuery> = {
    type?: 'select';
    key: DtDomainFilterKey<TQuery>;
    /** คีย์คำแปลของชื่อตัวกรอง */
    label: string;
    options: DtFilterOption[];
};

/**
 * ตัวกรองแบบช่วงวัน — สองคีย์บน URL (`from`/`to` เป็น `Y-m-d` หรือ null) วาดเป็น
 * `DateRangePicker` ตัวเดียวใน dialog และ chip เดียวที่ถอดแล้วล้างทั้งคู่
 */
export type DtDateRangeFilterField<TQuery> = {
    type: 'date-range';
    fromKey: DtDomainFilterKey<TQuery>;
    toKey: DtDomainFilterKey<TQuery>;
    /** คีย์คำแปลของชื่อตัวกรอง */
    label: string;
};

/** ตัวกรองหนึ่งตัวของโดเมน — ไม่มี `type` คือ Select */
export type DtFilterField<TQuery> =
    DtSelectFilterField<TQuery> | DtDateRangeFilterField<TQuery>;

/** ค่าใน dialog ที่ยังไม่ได้ยิง — ยิงครั้งเดียวตอน `apply()` */
export type DtFilterDraft<TQuery> = {
    dateRange: DateRange | undefined;
    /** ค่าต่อคีย์ของโดเมน — ช่วงวันของโดเมนก็อยู่ที่นี่เป็นสองคีย์ ไม่แยกที่เก็บ */
    values: Partial<Record<DtDomainFilterKey<TQuery>, string | null>>;
};

/** เงื่อนไขที่กรองอยู่จริง (จาก `dt.query`) หนึ่งรายการ — toolbar วาดเป็น chip */
export type DtActiveFilter = {
    key: string;
    label: string;
    value: string;
    remove: () => void;
};

export type DtFilterDialog<TQuery> = {
    open: boolean;
    /** เปิด = ตั้ง draft ใหม่จาก `dt.query` ปิด = ทิ้ง draft */
    setOpen: (open: boolean) => void;
    draft: DtFilterDraft<TQuery>;
    setDateRange: (range: DateRange | undefined) => void;
    setValue: (key: DtDomainFilterKey<TQuery>, value: string | null) => void;
    /** เขียนสองคีย์ของช่วงวันของโดเมนจาก picker ในจังหวะเดียว */
    setRange: (
        field: DtDateRangeFilterField<TQuery>,
        range: DateRange | undefined,
    ) => void;
    /** ล้างทุกตัวกรองใน dialog (ช่วงวันที่สร้าง + ทุก field) ยิงครั้งเดียวแล้วปิด — search ไม่เกี่ยว */
    reset: () => void;
    /** ยิงทุกค่าใน draft ครั้งเดียวแล้วปิด */
    apply: () => void;
};

export type DataTableToolbarInstance<TQuery extends DtFilters> = {
    /** ค่าที่กำลังพิมพ์ — ไม่ใช่ค่าที่ server ตอบ (นั่นคือ `dt.query.search`) */
    search: string;
    /** หน่วงก่อนยิง ไม่งั้นพิมพ์หนึ่งตัวอักษรเท่ากับหนึ่ง request */
    setSearch: (value: string) => void;
    dialog: DtFilterDialog<TQuery>;
    /** chip ของเงื่อนไขที่กรองอยู่ (ไม่รวม search ที่เห็นในช่องอยู่แล้ว) */
    active: DtActiveFilter[];
    /** ล้างทุกตัวกรองรวม search ในการยิงครั้งเดียว — sort/direction/per_page คงเดิม */
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

/** ช่วงวันของโดเมนที่อยู่ใน draft — ให้ picker อ่านค่าจากสองคีย์ที่เก็บเป็นสตริง */
export function rangeOfDraft<TQuery>(
    draft: DtFilterDraft<TQuery>,
    field: DtDateRangeFilterField<TQuery>,
): DateRange | undefined {
    return rangeOfDays(draft.values[field.fromKey], draft.values[field.toKey]);
}

/** ทุกคีย์บน URL ที่ field หนึ่งตัวเป็นเจ้าของ — Select หนึ่งคีย์ ช่วงวันสองคีย์ */
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
 * ทุกคีย์ที่ไม่ใช่ sort/direction/per_page คือตัวกรอง — ล้างตาม convention เดียวกับที่
 * `hasActiveFilters` อ่าน: `search` เป็น `''` ที่เหลือเป็น `null`
 */
function clearedFilters<TQuery extends object>(query: TQuery): Partial<TQuery> {
    return Object.fromEntries(
        Object.keys(query)
            .filter((key) => !DT_QUERY_KEYS.has(key))
            .map((key) => [key, key === 'search' ? '' : null]),
    ) as Partial<TQuery>;
}

/**
 * state ฝั่ง client ของแถบค้นหา/กรอง: คำค้นที่ยังไม่ได้ยิง กับ draft ของ dialog ตัวกรอง
 * ส่วนค่าที่ใช้จริงอยู่บน URL (`dt.query`) — hook ยิงผ่าน `dt.fetch` เท่านั้น ไม่มี router ของตัวเอง
 *
 * เมื่อ `dt.query.search` เปลี่ยนจากทางอื่น (กด back, ล้างตัวกรอง) ให้ค่าบนจอตามไปด้วยโดยรีเซ็ต
 * state ตอน render — ไม่ใช่ใน effect (eslint ห้าม setState ใน effect) ยกเว้นเมื่อค่าที่
 * server ตอบคือค่าที่เราเพิ่งยิงไปเอง: ตอนนั้นผู้ใช้อาจพิมพ์ต่อไปแล้ว ห้ามดึงกลับ
 * draft ไม่ต้อง resync เพราะตั้งใหม่จาก query ทุกครั้งที่เปิด dialog
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
     * timer อ่าน `fetch` ล่าสุดตอนที่มันยิง ไม่ใช่ตอนที่ตั้ง — ถ้าผู้ใช้ใช้ตัวกรองระหว่างรอ
     * debounce ค่าใหม่จะไม่หายไปกับ query เก่าที่ปิดไว้ใน closure
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
     * ล้าง = ยิงทันที ไม่ใช่แค่ล้าง draft — ปุ่มที่เปลี่ยนหน้าจอแต่ไม่เปลี่ยนข้อมูลคือบั๊กในสายตาผู้ใช้
     * ต้องส่ง `null` ให้ทุกคีย์อย่างชัดเจน (ไม่ใช่ทิ้งคีย์) เพราะ `dt.fetch` merge กับ query เดิม
     * คีย์ที่หายไปจะคงค่าเก่าไว้
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
