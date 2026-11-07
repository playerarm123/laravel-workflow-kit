import { Card, CardContent } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/numbers';
import { cn } from '@/lib/utils';
import type { MediaQuotaUsage } from '@/types/media-item';

type Props = {
    storage: MediaQuotaUsage;
};

/**
 * How much the image library uses, and of its quota when it has one, as a bar that turns red
 * past nine tenths.
 */
export function MediaStorageCard({ storage }: Props) {
    const { t } = useTranslation();
    const share =
        storage.limit_bytes === null || storage.limit_bytes === 0
            ? null
            : Math.min(storage.used_bytes / storage.limit_bytes, 1);

    return (
        <Card>
            <CardContent className="space-y-2 p-4" data-test="media-storage">
                <p className="text-sm font-medium">
                    {t('media-items.storage_title')}
                </p>
                <p className="text-sm">
                    {storage.limit_bytes === null
                        ? t('media-items.storage_used', {
                              used: formatBytes(storage.used_bytes),
                          })
                        : t('media-items.storage_used_of', {
                              used: formatBytes(storage.used_bytes),
                              limit: formatBytes(storage.limit_bytes),
                          })}
                </p>
                {share !== null && (
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className={cn(
                                'h-full rounded-full bg-primary',
                                share >= 0.9 && 'bg-destructive',
                            )}
                            style={{ width: `${share * 100}%` }}
                        />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
