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
 * หนึ่ง entry เต็มตัว พร้อม `data` เป็นคู่ key/value — แถวในตารางโชว์แค่หัวเรื่อง
 *
 * ทุกป้าย (เหตุการณ์ ชนิดรายการ ชื่อ key ของ data) แปลจาก `audit-entries.*` ซึ่ง
 * `AuditLogTest` บังคับให้ครบทุกภาษาสำหรับทุกค่าที่ handler บันทึก (audit-log.md)
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
 * ผู้ทำ: ชื่อ user, "ระบบ" เมื่อ `actor_id` เป็น null หรือ id ดิบเมื่อ user ถูกลบไปแล้ว
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
 * ชื่อเหตุการณ์ที่แปลแล้ว กับรหัสเดิมตัวเล็กข้างใต้ — รหัสคือสิ่งที่เก็บในฐานข้อมูล
 * และที่คนดูแลระบบใช้ไล่หาในโค้ด คำแปลเปลี่ยนได้ รหัสไม่เปลี่ยน
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
