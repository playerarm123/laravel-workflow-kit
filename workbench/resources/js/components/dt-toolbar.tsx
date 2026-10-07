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

/** The value of the "all" item: radix Select does not accept `''` as a value */
const ANY = 'any';

type DtFilterSelectProps = {
    id: string;
    /** `null` means no filter */
    value: string | null;
    /** Translation key of the filter's name */
    label: string;
    options: DtFilterOption[];
    onChange: (value: string | null) => void;
};

/**
 * One Select per domain filter. The first item is "all", which sends `null` back
 * to match what the PHP Criteria holds (null = no filter).
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
 * One domain date range. A single picker writes two draft keys through `dialog.setRange`,
 * in the same shape as the created-at range at the top of the dialog.
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
 * The filter dialog: created-at range + domain Selects/date ranges. Edits go into a draft, sent once on
 * "Apply filters". Closing without applying discards the draft (the hook resets it from the query on next open).
 * "Clear" sends null for every filter in the dialog at once and closes, with no need to press "Apply filters".
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
    /** The table page's `useDataTable()` or the grid page's `useListQuery()` */
    dt: ListQuery<TQuery>;
    /** `false` hides the search box, for a page whose backend has no `SEARCHABLE_COLUMNS`. The filter button stays */
    search?: boolean;
    searchPlaceholder?: string;
    /** Domain filters: a Select or a date range in the dialog, and a chip when set */
    fields?: DtFilterField<TQuery>[];
};

/**
 * The basic search/filter bar of every list page: a search box (debounced, off with `search={false}`) +
 * a button that opens the filter dialog (created-at range + domain Selects/date ranges), then shows the active
 * conditions as chips, removable one at a time.
 * Everything goes through `dt.fetch`; there is no router here.
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
