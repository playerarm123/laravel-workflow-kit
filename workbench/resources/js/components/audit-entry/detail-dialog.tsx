import { DetailField as Field } from '@/components/detail-field';
import { ViewDialog } from '@/components/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/dates';
import type { AuditEntryRow } from '@/types';

type Props = {
    entry: AuditEntryRow | null;
    onOpenChange: (open: boolean) => void;
};

/**
 * One entry in full, with `data` as key/value pairs. A table row shows only the headline.
 *
 * Every label (the event, the subject type, the data keys) is translated from `audit-entries.*`, which
 * `AuditLogTest` requires in every language for every value a handler records (audit-log.md).
 */
export function AuditEntryDetailDialog({ entry, onOpenChange }: Props) {
    const { t, locale, timezone } = useTranslation();
    const data = Object.entries(entry?.data ?? {});

    return (
        <ViewDialog
            open={entry !== null}
            onOpenChange={onOpenChange}
            title={t('audit-entries.detail_title')}
            description={
                entry === null ? '' : t(`audit-entries.events.${entry.event}`)
            }
        >
            {entry !== null && (
                <dl className="divide-y divide-border">
                    <Field label={t('audit-entries.occurred_at')}>
                        {formatDateTime(entry.occurred_at, locale, timezone)}
                    </Field>
                    <Field label={t('audit-entries.actor')}>
                        <AuditEntryActor entry={entry} />
                    </Field>
                    <Field label={t('audit-entries.event')}>
                        <AuditEntryEvent event={entry.event} />
                    </Field>
                    <Field label={t('audit-entries.subject_type')}>
                        {t(`audit-entries.subject_types.${entry.subject_type}`)}
                    </Field>
                    <Field label={t('audit-entries.subject_id')}>
                        <span className="font-mono text-xs break-all">
                            {entry.subject_id}
                        </span>
                    </Field>
                    {data.length === 0 ? (
                        <Field label={t('audit-entries.data')}>—</Field>
                    ) : (
                        data.map(([key, value]) => (
                            <Field
                                key={key}
                                label={t(`audit-entries.data_keys.${key}`)}
                            >
                                <span className="font-mono text-xs break-all">
                                    {value === null ? '—' : String(value)}
                                </span>
                            </Field>
                        ))
                    )}
                </dl>
            )}
        </ViewDialog>
    );
}

/**
 * The actor: the user's name, "system" when `actor_id` is null, or the raw id once the user is deleted.
 */
export function AuditEntryActor({ entry }: { entry: AuditEntryRow }) {
    const { t } = useTranslation();

    if (entry.actor_id === null) {
        return (
            <span className="text-muted-foreground">
                {t('audit-entries.system')}
            </span>
        );
    }

    return (
        <>
            {entry.actor_name ?? (
                <span className="font-mono text-xs">{entry.actor_id}</span>
            )}
        </>
    );
}

/**
 * The translated event name, with its original code in small print below. The code is what the database stores
 * and what a maintainer searches the code for. The translation may change; the code does not.
 */
export function AuditEntryEvent({ event }: { event: string }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col">
            <span>{t(`audit-entries.events.${event}`)}</span>
            <span className="font-mono text-xs text-muted-foreground">
                {event}
            </span>
        </div>
    );
}
