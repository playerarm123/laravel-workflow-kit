import { Link } from '@inertiajs/react';
import type {
    Column,
    Header,
    HeaderGroup,
    ReactTable,
    Row,
    RowData,
} from '@tanstack/react-table';
import { FlexRender, Subscribe } from '@tanstack/react-table';
import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Pagination,
    PaginationContent,
    PaginationItem,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    DataTableFeatures,
    DataTableInstance,
    EmptyState,
} from '@/hooks/use-data-table';
import { useTranslation } from '@/hooks/use-translation';
import type { DtRowData, PaginationMeta } from '@/types';
import { sortIcon } from './icons';

const DEFAULT_PER_PAGE_OPTIONS = [10, 25, 50];

function DtEmptyState({ props }: { props?: EmptyState }) {
    const { t } = useTranslation();
    const Icon = props?.icon ?? TriangleAlert;
    const title = props?.title ?? t('data_table.not_found_title');
    const description =
        props?.description ?? t('data_table.not_found_description');

    return (
        <div className="p-8 text-center">
            <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
                <Icon className="size-7 text-muted-foreground" />
            </div>
            <p className="font-medium">{title}</p>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
        </div>
    );
}

type DtBulkbarProps<TData extends RowData> = {
    table: ReactTable<DataTableFeatures, TData>;
    actions?: (selectedRows: Row<DataTableFeatures, TData>[]) => ReactNode;
};

/**
 * The bar that appears above the table when rows are selected. The actions belong to each domain,
 * so the page passes them in through `actions`; this holds only the counter and the clear button.
 *
 * `selector` returns only the count, not the whole rowSelection: swapping selected rows at the same count
 * does not redraw the bar, and `selectedRows` is read only when it really draws, so it is always the latest set.
 */
function DtBulkbar<TData extends RowData>({
    table,
    actions,
}: DtBulkbarProps<TData>) {
    const { t } = useTranslation();

    return (
        <Subscribe
            source={table.atoms.rowSelection}
            selector={(rowSelection) => Object.keys(rowSelection).length}
        >
            {(count) =>
                count === 0 ? null : (
                    <div className="flex flex-col gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm font-medium">
                            {t('common.selected_count', { count })}
                        </p>

                        <div className="flex flex-wrap items-center gap-2">
                            {actions?.(table.getSelectedRowModel().rows)}

                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => table.resetRowSelection()}
                            >
                                {t('common.clear_selection')}
                            </Button>
                        </div>
                    </div>
                )
            }
        </Subscribe>
    );
}

type DtTableHeadProps<TData extends RowData> = {
    header: Header<DataTableFeatures, TData, unknown>;
};

function DtTableHead<TData extends RowData>({
    header,
}: DtTableHeadProps<TData>) {
    const column = header.column as unknown as Column<DataTableFeatures, TData>;
    const definedHeader = column.columnDef.header;
    const { t } = useTranslation();
    const label =
        typeof definedHeader === 'string' ? (
            t(definedHeader)
        ) : (
            <FlexRender header={header} />
        );

    return (
        <TableHead
            className={column.id === 'actions' ? 'text-right' : undefined}
        >
            {column.getCanSort() ? (
                <Button
                    variant="ghost"
                    size="sm"
                    className="-ml-2"
                    onClick={column.getToggleSortingHandler()}
                >
                    {label}
                    {sortIcon(column.getIsSorted())}
                </Button>
            ) : (
                label
            )}
        </TableHead>
    );
}

type DtTableProps<TData extends RowData> = {
    rows: Row<DataTableFeatures, TData>[];
    headerGroups: HeaderGroup<DataTableFeatures, TData>[];
};
function DtTable<TData extends RowData>({
    rows,
    headerGroups,
}: DtTableProps<TData>) {
    return (
        <>
            <Table>
                <TableHeader>
                    {headerGroups.map((group) => (
                        <TableRow key={group.id}>
                            {group.headers.map((header) => (
                                <DtTableHead key={header.id} header={header} />
                            ))}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.id}>
                            {row.getAllCells().map((cell) => (
                                <TableCell key={cell.id}>
                                    <FlexRender cell={cell} />
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </>
    );
}

type DtPaginationProps = {
    paginated: PaginationMeta;
    onPerPageChange?: (perPage: number) => void;
    perPageOptions?: number[];
};

function DtPagination({
    paginated,
    onPerPageChange,
    perPageOptions = DEFAULT_PER_PAGE_OPTIONS,
}: DtPaginationProps) {
    const { t } = useTranslation();
    const { prev_page_url, links, next_page_url, from, to, total, last_page } =
        paginated;

    const hasPages = last_page > 1;

    if (!hasPages && onPerPageChange === undefined) {
        return null;
    }

    const pageLinks = links.slice(1, -1);

    return (
        <div className="flex flex-col items-center justify-between gap-4 sm:flex-row">
            <div className="flex items-center gap-3">
                {onPerPageChange !== undefined && (
                    <Select
                        value={String(paginated.per_page)}
                        onValueChange={(value) =>
                            onPerPageChange(Number(value))
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-auto"
                            aria-label={t('common.rows_per_page')}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {perPageOptions.map((option) => (
                                <SelectItem key={option} value={String(option)}>
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

                <p className="text-sm text-muted-foreground">
                    {t('common.showing_range', {
                        from: from ?? 0,
                        to: to ?? 0,
                        total,
                    })}
                </p>
            </div>

            {hasPages && (
                <Pagination className="mx-0 w-auto justify-end">
                    <PaginationContent>
                        <PaginationItem>
                            <Button
                                asChild={prev_page_url !== null}
                                variant="ghost"
                                size="sm"
                                disabled={prev_page_url === null}
                            >
                                {prev_page_url !== null ? (
                                    <Link href={prev_page_url} preserveScroll>
                                        {t('common.previous')}
                                    </Link>
                                ) : (
                                    <span>{t('common.previous')}</span>
                                )}
                            </Button>
                        </PaginationItem>

                        {pageLinks.map((link) => (
                            <PaginationItem key={link.label}>
                                <Button
                                    asChild={link.url !== null}
                                    variant={link.active ? 'outline' : 'ghost'}
                                    size="icon"
                                >
                                    {link.url !== null ? (
                                        <Link
                                            href={link.url}
                                            preserveScroll
                                            aria-current={
                                                link.active ? 'page' : undefined
                                            }
                                        >
                                            {link.label}
                                        </Link>
                                    ) : (
                                        <span>{link.label}</span>
                                    )}
                                </Button>
                            </PaginationItem>
                        ))}

                        <PaginationItem>
                            <Button
                                asChild={next_page_url !== null}
                                variant="ghost"
                                size="sm"
                                disabled={next_page_url === null}
                            >
                                {next_page_url !== null ? (
                                    <Link href={next_page_url} preserveScroll>
                                        {t('common.next')}
                                    </Link>
                                ) : (
                                    <span>{t('common.next')}</span>
                                )}
                            </Button>
                        </PaginationItem>
                    </PaginationContent>
                </Pagination>
            )}
        </div>
    );
}

export type DataTableProps<TData extends DtRowData> = {
    dt: DataTableInstance<TData>;
    /** Page-level buttons (e.g. create): placed right of the heading, on the same row */
    headerActions?: ReactNode;
    /** The domain's search/filter bar: placed between the heading and the bulk bar */
    toolbar?: ReactNode;
    bulkActions?: (selectedRows: Row<DataTableFeatures, TData>[]) => ReactNode;
};

export default function DataTable<TData extends DtRowData>({
    dt,
    headerActions,
    toolbar,
    bulkActions,
}: DataTableProps<TData>) {
    const { table } = dt;
    const {
        title,
        description,
        emptyState,
        paginated,
        onPerPageChange,
        perPageOptions,
    } = table.options.meta!;
    const rows = table.getRowModel().rows;
    const headerGroups = table.getHeaderGroups();

    return (
        <>
            <div className="space-y-6 px-4 py-6">
                <section className="dt-header">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <Heading title={title} description={description} />
                        {headerActions}
                    </div>
                    {toolbar}
                    <DtBulkbar table={table} actions={bulkActions} />
                </section>
                <section className="dt-body overflow-hidden rounded-lg border border-border">
                    {rows.length > 0 ? (
                        <DtTable rows={rows} headerGroups={headerGroups} />
                    ) : (
                        <DtEmptyState props={emptyState} />
                    )}
                </section>
                <section className="dt-footer">
                    <DtPagination
                        paginated={paginated}
                        onPerPageChange={onPerPageChange}
                        perPageOptions={perPageOptions}
                    />
                </section>
            </div>
        </>
    );
}
