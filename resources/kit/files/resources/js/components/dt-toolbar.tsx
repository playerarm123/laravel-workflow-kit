import { ListFilter, Search, X } from 'lucide-react';
import { ViewDialog } from '@/components/dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    isDateRangeField,
    rangeOfDraft,
    useDataTableToolbar,
} from '@/hooks/use-data-table-toolbar';
import type {
    DtActiveFilter,
    DtDateRangeFilterField,
    DtFilterDialog,
    DtFilterField,
    DtFilterOption,
} from '@/hooks/use-data-table-toolbar';
import type { ListQuery } from '@/hooks/use-list-query';
import { useTranslation } from '@/hooks/use-translation';
import type { DtFilters } from '@/types';

/** ค่าของรายการ "ทั้งหมด" — radix Select ไม่รับ `''` เป็น value */
const ANY = 'any';

type DtFilterSelectProps = {
    id: string;
    /** `null` คือไม่กรอง */
    value: string | null;
    /** คีย์คำแปลของชื่อตัวกรอง */
    label: string;
    options: DtFilterOption[];
    onChange: (value: string | null) => void;
};

/**
 * Select หนึ่งตัวต่อหนึ่งตัวกรองของโดเมน — รายการแรกคือ "ทั้งหมด" ที่ส่ง `null` กลับ
 * ให้ตรงกับที่ Criteria ฝั่ง PHP ถือ (null = ไม่กรอง)
 */
function DtFilterSelect({
    id,
    value,
    label,
    options,
    onChange,
}: DtFilterSelectProps) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{t(label)}</Label>
            <Select
                value={value ?? ANY}
                onValueChange={(next) => onChange(next === ANY ? null : next)}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ANY}>{t('common.all')}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {t(option.label)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

type DtFilterRangeProps<TQuery> = {
    field: DtDateRangeFilterField<TQuery>;
    dialog: DtFilterDialog<TQuery>;
};

/**
 * ช่วงวันของโดเมนหนึ่งตัว — picker เดียวเขียนสองคีย์ของ draft ผ่าน `dialog.setRange`
 * รูปเดียวกับช่วงวันที่สร้างที่อยู่บนสุดของ dialog
 */
function DtFilterRange<TQuery>({ field, dialog }: DtFilterRangeProps<TQuery>) {
    const { t, locale } = useTranslation();

    return (
        <div className="grid gap-2">
            <Label>{t(field.label)}</Label>
            <DateRangePicker
                value={rangeOfDraft(dialog.draft, field)}
                onChange={(range) => dialog.setRange(field, range)}
                placeholder={t('data_table.any_day')}
                clearLabel={t('common.clear')}
                locale={locale}
                className="w-full"
            />
        </div>
    );
}

type DtFilterDialogProps<TQuery extends DtFilters> = {
    dialog: DtFilterDialog<TQuery>;
    fields: DtFilterField<TQuery>[];
};

/**
 * dialog ตัวกรอง: ช่วงวันที่สร้าง + Select/ช่วงวันของโดเมน แก้ใน draft แล้วยิงครั้งเดียวตอนกด
 * "ใช้ตัวกรอง" — ปิดโดยไม่กดคือทิ้ง draft (hook ตั้งใหม่จาก query ตอนเปิดครั้งถัดไป)
 * ปุ่ม "ล้าง" ยิง null ให้ทุกตัวกรองใน dialog ทันทีแล้วปิด ไม่ต้องกด "ใช้ตัวกรอง" ซ้ำ
 */
function DtFilterDialogContent<TQuery extends DtFilters>({
    dialog,
    fields,
}: DtFilterDialogProps<TQuery>) {
    const { t, locale } = useTranslation();

    return (
        <ViewDialog
            open={dialog.open}
            onOpenChange={dialog.setOpen}
            title={t('data_table.filters')}
            footer={
                <>
                    <Button variant="ghost" onClick={dialog.reset}>
                        {t('common.clear')}
                    </Button>
                    <Button onClick={dialog.apply}>
                        {t('data_table.apply_filters')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <div className="grid gap-2">
                    <Label>{t('data_table.created_between')}</Label>
                    <DateRangePicker
                        value={dialog.draft.dateRange}
                        onChange={dialog.setDateRange}
                        placeholder={t('data_table.any_day')}
                        clearLabel={t('common.clear')}
                        locale={locale}
                        className="w-full"
                    />
                </div>

                {fields.map((field) =>
                    isDateRangeField(field) ? (
                        <DtFilterRange
                            key={field.fromKey}
                            field={field}
                            dialog={dialog}
                        />
                    ) : (
                        <DtFilterSelect
                            key={field.key}
                            id={`dt-filter-${field.key}`}
                            value={dialog.draft.values[field.key] ?? null}
                            label={field.label}
                            options={field.options}
                            onChange={(value) =>
                                dialog.setValue(field.key, value)
                            }
                        />
                    ),
                )}
            </div>
        </ViewDialog>
    );
}

function DtActiveFilterChip({ filter }: { filter: DtActiveFilter }) {
    const { t } = useTranslation();

    return (
        <Badge variant="outline" className="h-7 gap-1.5 pr-1 pl-2.5">
            <span className="text-muted-foreground">{filter.label}:</span>
            <span>{filter.value}</span>
            <Button
                variant="ghost"
                size="icon"
                className="size-5 rounded-full"
                aria-label={t('data_table.remove_filter', {
                    filter: filter.label,
                })}
                onClick={filter.remove}
            >
                <X className="size-3" />
            </Button>
        </Badge>
    );
}

export type DataTableToolbarProps<TQuery extends DtFilters> = {
    /** `useDataTable()` ของหน้า table หรือ `useListQuery()` ของหน้า grid */
    dt: ListQuery<TQuery>;
    /** `false` ซ่อนช่องค้นหา — หน้าที่ backend ไม่มี `SEARCHABLE_COLUMNS` ปุ่มตัวกรองยังอยู่ */
    search?: boolean;
    searchPlaceholder?: string;
    /** ตัวกรองของโดเมน — Select หรือช่วงวันใน dialog และ chip เมื่อมีค่า */
    fields?: DtFilterField<TQuery>[];
};

/**
 * แถบค้นหา/กรองพื้นฐานของทุกหน้า list: ช่องค้นหา (debounce, ปิดได้ด้วย `search={false}`) +
 * ปุ่มเปิด dialog ตัวกรอง (ช่วงวันที่สร้าง + Select/ช่วงวันของโดเมน) แล้วโชว์เงื่อนไขที่กรองอยู่
 * เป็น chip ถอดได้ทีละตัว
 * ทุกการยิงไปทาง `dt.fetch` ไม่มี router ที่นี่
 */
export function DataTableToolbar<TQuery extends DtFilters>({
    dt,
    search = true,
    searchPlaceholder,
    fields = [],
}: DataTableToolbarProps<TQuery>) {
    const { t } = useTranslation();
    const toolbar = useDataTableToolbar(dt, { fields });

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                {search && (
                    <div className="relative w-full sm:max-w-xs">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={toolbar.search}
                            onChange={(event) =>
                                toolbar.setSearch(event.target.value)
                            }
                            placeholder={
                                searchPlaceholder ?? t('common.search')
                            }
                            aria-label={t('common.search')}
                            className="pl-9"
                        />
                    </div>
                )}

                <Button
                    variant="outline"
                    onClick={() => toolbar.dialog.setOpen(true)}
                >
                    <ListFilter className="size-4" />
                    {t('data_table.filters')}
                    {toolbar.active.length > 0 && (
                        <Badge variant="secondary">
                            {toolbar.active.length}
                        </Badge>
                    )}
                </Button>

                {toolbar.hasFilters && (
                    <Button variant="ghost" size="sm" onClick={toolbar.clear}>
                        <X className="size-4" />
                        {t('common.clear_filters')}
                    </Button>
                )}
            </div>

            {toolbar.active.length > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    {toolbar.active.map((filter) => (
                        <DtActiveFilterChip key={filter.key} filter={filter} />
                    ))}
                </div>
            )}

            <DtFilterDialogContent dialog={toolbar.dialog} fields={fields} />
        </div>
    );
}
