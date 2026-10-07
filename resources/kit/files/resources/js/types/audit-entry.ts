import type { DtQuery } from '@/hooks/use-data-table';
import type { DtFilters } from './data-table';

/**
 * @see App\Application\Audit\UseCases\ListAuditEntries\AuditEntryListRow::toArray()
 */
export type AuditEntryRow = {
    id: string;
    /** `actor_id` null = the system acted; `actor_name` is also null for a user that is gone */
    occurred_at: string;
    actor_id: string | null;
    actor_name: string | null;
    event: string;
    subject_type: string;
    subject_id: string;
    /** ids, enum values, amounts and codes only — never personal data (audit-log.md) */
    data: Record<string, string | number | boolean | null>;
};

/**
 * @see App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesCriteria::toFilters()
 */
export type AuditEntryFilters = DtFilters & {
    subject_type: string | null;
};

/** The whole query on the list page's URL: what the page hands useDataTable and the toolbar reads back. */
export type AuditEntriesQuery = AuditEntryFilters & DtQuery;
