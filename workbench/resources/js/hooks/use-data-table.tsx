import type {
    ColumnDef,
    ColumnHelper,
    ReactTable,
    RowSelectionState,
    SortingState,
    Updater,
} from '@tanstack/react-table';
import {
    tableFeatures,
    rowSortingFeature,
    rowSelectionFeature,
    useTable,
    createColumnHelper,
    Subscribe,
} from '@tanstack/react-table';
import { MoreHorizontal } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { ItemButton } from '@/components/buttons';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    DtRowData,
    PaginationMeta,
    SortDirection,
    Paginated,
} from '@/types';
import { isActionVisible } from './use-actions';
import type { Action } from './use-actions';
import { useListQuery } from './use-list-query';
import type { ListQuery, ListQueryOptions } from './use-list-query';
import { useTranslation } from './use-translation';

type TableMeta = {
    emptyState?: EmptyState;
    title: string;
    description?: string;
    t: (key: string) => string;
    paginated: PaginationMeta;
    onPerPageChange?: (perPage: number) => void;
    perPageOptions?: number[];
};
const features = tableFeatures({
    rowSortingFeature,
    rowSelectionFeature,
    tableMeta: {} as TableMeta,
});

type RowActionCellProps<TData extends DtRowData> = {
    data: TData;
    action: RowAction<TData>;
};

type SelectColumnParams<TData extends DtRowData> = {
    columnHelper: ColumnHelper<DataTableFeatures, TData>;
};

type ActionColumnParams<TData extends DtRowData> = SelectColumnParams<TData> & {
    rowAction: RowAction<TData>;
};

type WithActionColumnParams<TData extends DtRowData> = {
    rowAction?: RowAction<TData>;
    columns: ColumnDef<DataTableFeatures, TData>[];
};

export type EmptyState = {
    icon?: LucideIcon;
    title?: string;
    description?: string;
};

export type DataTableFeatures = typeof features;

/**
 * The part of the query string every list page shares. Keys match the real parameter names on the URL.
 * A page extends it with its own `{Aggregate}Filters`: `CompanyUserFilters & DtQuery`
 */
export type DtQuery = {
    sort: string;
    direction: SortDirection;
    per_page: number;
};

/**
 * Row buttons: `view`/`edit` are shown directly, while `extra` (domain-specific items) followed by
 * `delete` go into the ⋯ menu. The table only calls `click`; the dialog that follows (view dialog, delete confirm)
 * is rendered by the page.
 */
export type RowAction<TData extends DtRowData> = {
    view?: Action<TData>;
    edit?: Action<TData>;
    extra?: Action<TData>[];
    delete?: Action<TData>;
};

/**
 * `query` is the current value from props: `{ ...filters, sort, direction, per_page }`. `visit`
 * is the one way out (see `ListQueryOptions`); sorting and page size go through it too.
 */
export type DataTableOptions<
    TItem extends DtRowData,
    TQuery extends DtQuery = DtQuery,
> = ListQueryOptions<TQuery> & {
    title: string;
    description?: string;
    columns: ColumnDef<DataTableFeatures, TItem>[];
    paginated: Paginated<TItem>;
    /** Given = shows the page-size picker. Must match `PageSize` on the PHP side (10/25/50) */
    perPageOptions?: number[];
    /** No data at all (no filter yet) */
    emptyState?: EmptyState;
    /** Filtered to nothing. The hook switches by `hasFilters`; when absent it falls back to `emptyState` */
    noResultsState?: EmptyState;
    rowAction?: RowAction<TItem>;
};

export type DataTableInstance<
    TItem extends DtRowData,
    TQuery extends DtQuery = DtQuery,
> = ListQuery<TQuery> & {
    table: ReactTable<DataTableFeatures, TItem>;
    rowAction?: RowAction<TItem>;
};

function createSelectColumn<TData extends DtRowData>({
    columnHelper,
}: SelectColumnParams<TData>) {
    return columnHelper.display({
        id: 'select',
        header: ({ table }) => (
            <Subscribe source={table.atoms.rowSelection}>
                {() => (
                    <Checkbox
                        checked={
                            table.getIsAllPageRowsSelected() ||
                            (table.getIsSomePageRowsSelected() &&
                                'indeterminate')
                        }
                        onCheckedChange={(checked) =>
                            table.toggleAllPageRowsSelected(checked === true)
                        }
                        aria-label={table.options.meta?.t('common.select_all')}
                    />
                )}
            </Subscribe>
        ),
        cell: ({ row, table }) => (
            <Subscribe
                source={table.atoms.rowSelection}
                selector={(rowSelection) => rowSelection[row.id]}
            >
                {(selected) => (
                    <Checkbox
                        checked={selected === true}
                        onCheckedChange={(checked) =>
                            row.toggleSelected(checked === true)
                        }
                        aria-label={table.options.meta?.t('common.select_row')}
                    />
                )}
            </Subscribe>
        ),
    });
}

function RowActionCell<TData extends DtRowData>({
    data,
    action,
}: RowActionCellProps<TData>) {
    const { t } = useTranslation();
    const {
        view: viewAction,
        edit: editAction,
        extra = [],
        delete: deleteAction,
    } = action;

    const dropdownActions = [
        ...extra,
        ...(deleteAction ? [deleteAction] : []),
    ].filter((item) => isActionVisible(item, data));

    return (
        <div className="flex items-center justify-end gap-1">
            {viewAction && (
                <ItemButton
                    title={viewAction.title}
                    icon={viewAction.icon}
                    visible={isActionVisible(viewAction, data)}
                    disabled={viewAction.disabled}
                    iconOnly={viewAction.iconOnly}
                    variant={viewAction.variant}
                    onClick={() => viewAction.click(data)}
                />
            )}
            {editAction && (
                <ItemButton
                    title={editAction.title}
                    icon={editAction.icon}
                    visible={isActionVisible(editAction, data)}
                    disabled={editAction.disabled}
                    iconOnly={editAction.iconOnly}
                    variant={editAction.variant}
                    onClick={() => editAction.click(data)}
                />
            )}

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('common.actions')}
                    >
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>{t('common.actions')}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {dropdownActions.map((item) => (
                        <DropdownMenuItem
                            key={item.title}
                            disabled={item.disabled}
                            onSelect={() => item.click(data)}
                            variant={item.variant}
                        >
                            <item.icon />
                            {item.title}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function createActionColumn<TData extends DtRowData>({
    columnHelper,
    rowAction,
}: ActionColumnParams<TData>) {
    return columnHelper.display({
        id: 'actions',
        header: 'common.actions',
        cell: ({ row }) => (
            <RowActionCell action={rowAction} data={row.original} />
        ),
    });
}

function withActionColumn<TData extends DtRowData>({
    rowAction,
    columns,
}: WithActionColumnParams<TData>) {
    const columnHelper = createColumnHelper<DataTableFeatures, TData>();
    const selectColumn = createSelectColumn<TData>({ columnHelper });
    let finalColumns = [selectColumn, ...columns];

    if (rowAction) {
        const actionColumnParams: ActionColumnParams<TData> = {
            columnHelper,
            rowAction: rowAction,
        };

        finalColumns = [
            selectColumn,
            ...columns,
            createActionColumn(actionColumnParams),
        ];
    }

    return finalColumns;
}

export default function useDataTable<
    TItem extends DtRowData,
    TQuery extends DtQuery = DtQuery,
>(
    dtOptions: DataTableOptions<TItem, TQuery>,
): DataTableInstance<TItem, TQuery> {
    const {
        paginated,
        query,
        visit,
        columns,
        emptyState,
        noResultsState,
        title,
        description,
        perPageOptions,
        rowAction,
    } = dtOptions;
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const { t } = useTranslation();
    const { fetch, hasFilters } = useListQuery({ query, visit });

    /**
     * Clear the selection when the data set changes (page, filters, sort, page size). A visit uses
     * preserveState, so the component is not unmounted for us. Clear during render, not in an effect,
     * because resetting state when a prop changes is the pattern React itself recommends, while setState
     * in an effect causes a nested render and eslint forbids it.
     */
    const selectionScope = JSON.stringify([paginated.current_page, query]);
    const [renderedScope, setRenderedScope] = useState(selectionScope);

    if (renderedScope !== selectionScope) {
        setRenderedScope(selectionScope);
        setRowSelection({});
    }

    /** Turn the URL query into TanStack's sorting state shape. The URL owns the state, not the table */
    const sorting: SortingState = [
        { id: query.sort, desc: query.direction === 'desc' },
    ];

    const meta: TableMeta = {
        emptyState: hasFilters ? (noResultsState ?? emptyState) : emptyState,
        title,
        description,
        t,
        paginated,
        onPerPageChange: perPageOptions
            ? (per_page) => fetch({ per_page } as Partial<TQuery>)
            : undefined,
        perPageOptions,
    };

    const tableColumns = withActionColumn({ rowAction, columns });

    const table = useTable({
        features,
        data: paginated.data,
        columns: tableColumns,
        manualSorting: true,
        enableSortingRemoval: false,
        state: { sorting, rowSelection },
        meta,
        getRowId: (row) => row.id,
        onRowSelectionChange: setRowSelection,
        onSortingChange: (updater: Updater<SortingState>) => {
            const next =
                typeof updater === 'function' ? updater(sorting) : updater;
            const [target] = next;
            fetch({
                sort: target.id,
                direction: target.desc ? 'desc' : 'asc',
            } as Partial<TQuery>);
        },
    });

    return {
        table,
        rowAction,
        query,
        fetch,
        hasFilters,
    };
}
