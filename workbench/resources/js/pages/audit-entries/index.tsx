import { Head, router, setLayoutProps } from '@inertiajs/react';
import { createColumnHelper } from '@tanstack/react-table';
import { ScrollText, SearchX } from 'lucide-react';
import {
    AuditEntryActor,
    AuditEntryDetailDialog,
    AuditEntryEvent,
} from '@/components/audit-entry/detail-dialog';
import { AuditEntryTableToolbar } from '@/components/audit-entry/table-toolbar';
import DataTable from '@/components/dt-table';
import { Badge } from '@/components/ui/badge';
import { useActions } from '@/hooks/use-actions';
import type {
    DataTableFeatures,
    DataTableOptions,
} from '@/hooks/use-data-table';
import useDataTable from '@/hooks/use-data-table';
import { useItemDialog } from '@/hooks/use-dialog';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/dates';
import { index } from '@/routes/audit-entries';
import type {
    AuditEntriesQuery,
    AuditEntryFilters,
    AuditEntryRow,
    Paginated,
    SortState,
} from '@/types';

type Props = {
    auditEntries: Paginated<AuditEntryRow>;
    sort: SortState;
    filters: AuditEntryFilters;
    subjectTypes: string[];
};

export default function Index({
    auditEntries,
    sort,
    filters,
    subjectTypes,
}: Props) {
    const { t, locale, timezone } = useTranslation();
    const actions = useActions<AuditEntryRow>();
    const detailDialog = useItemDialog<AuditEntryRow>();
    const title: string = t('audit-entries.title');

    setLayoutProps({
        breadcrumbs: [{ title, href: index() }],
    });

    const columnHelper = createColumnHelper<DataTableFeatures, AuditEntryRow>();
    const columns = columnHelper.columns([
        columnHelper.accessor('occurred_at', {
            header: 'audit-entries.occurred_at',
            cell: ({ getValue }) =>
                formatDateTime(getValue(), locale, timezone),
        }),
        columnHelper.accessor('actor_name', {
            header: 'audit-entries.actor',
            enableSorting: false,
            cell: ({ row }) => <AuditEntryActor entry={row.original} />,
        }),
        columnHelper.accessor('event', {
            header: 'audit-entries.event',
            enableSorting: false,
            cell: ({ getValue }) => <AuditEntryEvent event={getValue()} />,
        }),
        columnHelper.accessor('subject_type', {
            header: 'audit-entries.subject_type',
            enableSorting: false,
            cell: ({ getValue }) => (
                <Badge variant="secondary">
                    {t(`audit-entries.subject_types.${getValue()}`)}
                </Badge>
            ),
        }),
        columnHelper.accessor('subject_id', {
            header: 'audit-entries.subject_id',
            enableSorting: false,
            cell: ({ getValue }) => (
                <span className="font-mono text-xs text-muted-foreground">
                    {getValue()}
                </span>
            ),
        }),
    ]);

    const dtOptions: DataTableOptions<AuditEntryRow, AuditEntriesQuery> = {
        title,
        description: t('audit-entries.description'),
        columns,
        paginated: auditEntries,
        query: {
            ...filters,
            sort: sort.column,
            direction: sort.direction,
            per_page: auditEntries.per_page,
        },
        visit: (query) => {
            router.get(
                index.url({ query }),
                {},
                { preserveScroll: true, preserveState: true, replace: true },
            );
        },
        perPageOptions: [10, 25, 50],
        emptyState: {
            icon: ScrollText,
            title: t('audit-entries.empty_title'),
            description: t('audit-entries.empty_description'),
        },
        noResultsState: {
            icon: SearchX,
            title: t('audit-entries.no_results_title'),
            description: t('audit-entries.no_results_description'),
        },
        rowAction: {
            view: actions.view.dialog({
                dialog: detailDialog,
                title: t('audit-entries.action_view'),
            }),
        },
    };

    const dt = useDataTable(dtOptions);

    return (
        <>
            <Head title={title} />

            <DataTable
                dt={dt}
                toolbar={
                    <AuditEntryTableToolbar
                        dt={dt}
                        subjectTypes={subjectTypes}
                    />
                }
            />

            <AuditEntryDetailDialog
                entry={detailDialog.target}
                onOpenChange={detailDialog.onOpenChange}
            />
        </>
    );
}
