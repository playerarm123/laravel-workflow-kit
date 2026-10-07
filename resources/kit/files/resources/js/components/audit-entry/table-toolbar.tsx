import { DataTableToolbar } from '@/components/dt-toolbar';
import type { DataTableInstance } from '@/hooks/use-data-table';
import type { DtFilterField } from '@/hooks/use-data-table-toolbar';
import { useTranslation } from '@/hooks/use-translation';
import type { AuditEntriesQuery, AuditEntryRow } from '@/types';

type Props = {
    dt: DataTableInstance<AuditEntryRow, AuditEntriesQuery>;
    /**
     * ทุก subject type ที่ log เคยบันทึก — มาจาก controller ไม่ใช่ const เพราะไม่ใช่ enum
     * aggregate ใหม่โผล่ในตัวกรองเองตั้งแต่ entry แรกของมัน
     */
    subjectTypes: string[];
};

/** Search, the filter dialog and the chips come from `DataTableToolbar`; this file only names the fields. */
export function AuditEntryTableToolbar({ dt, subjectTypes }: Props) {
    const { t } = useTranslation();

    const fields: DtFilterField<AuditEntriesQuery>[] = [
        {
            key: 'subject_type',
            label: 'audit-entries.filter_subject_type',
            options: subjectTypes.map((subjectType) => ({
                value: subjectType,
                label: `audit-entries.subject_types.${subjectType}`,
            })),
        },
    ];

    return (
        <DataTableToolbar
            dt={dt}
            searchPlaceholder={t('audit-entries.search_placeholder')}
            fields={fields}
        />
    );
}
