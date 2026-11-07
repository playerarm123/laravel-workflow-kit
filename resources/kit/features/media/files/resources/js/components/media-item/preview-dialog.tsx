import { ExternalLink } from 'lucide-react';
import { MediaImage } from '@/components/media-item/image';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ItemDialog } from '@/hooks/use-dialog';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/dates';
import { formatBytes } from '@/lib/numbers';
import type { MediaItemRow } from '@/types/media-item';

type Props = {
    dialog: ItemDialog<MediaItemRow>;
};

/**
 * The image at its stored size, whole, with where it is used: an image something uses cannot
 * be deleted, so this is where staff look first.
 */
export function MediaItemPreviewDialog({ dialog }: Props) {
    const { t, locale, timezone } = useTranslation();
    const mediaItem = dialog.target;

    return (
        <Dialog open={mediaItem !== null} onOpenChange={dialog.onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-4xl">
                {mediaItem !== null && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="truncate">
                                {mediaItem.name}
                            </DialogTitle>
                            <DialogDescription>
                                {t('media-items.dimensions', {
                                    width: mediaItem.width,
                                    height: mediaItem.height,
                                })}{' '}
                                · {formatBytes(mediaItem.size_bytes)} ·{' '}
                                {formatDateTime(
                                    mediaItem.created_at,
                                    locale,
                                    timezone,
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-md bg-muted/40">
                            <MediaImage
                                key={mediaItem.id}
                                src={mediaItem.url}
                                alt={mediaItem.alt_text ?? mediaItem.name}
                                className="max-h-[60vh] w-auto max-w-full object-contain"
                            />
                        </div>

                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('media-items.alt_text')}
                                </dt>
                                <dd>{mediaItem.alt_text ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('media-items.folder')}
                                </dt>
                                <dd>
                                    {mediaItem.folder_name ??
                                        t('media-items.no_folder')}
                                </dd>
                            </div>
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground">
                                    {t('media-items.usages')}
                                </dt>
                                <dd data-test="media-item-usages">
                                    {mediaItem.usages.length === 0 ? (
                                        t('media-items.not_used')
                                    ) : (
                                        <ul className="list-disc pl-5">
                                            {mediaItem.usages.map((usage) => (
                                                <li
                                                    key={`${usage.usable_type}:${usage.label}`}
                                                >
                                                    {t(
                                                        `media-items.usable_types.${usage.usable_type}`,
                                                    )}
                                                    : {usage.label}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </dd>
                            </div>
                        </dl>

                        <DialogFooter>
                            <Button variant="outline" asChild>
                                <a
                                    href={mediaItem.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink className="size-4" />
                                    {t('media-items.open_original')}
                                </a>
                            </Button>
                            <Button onClick={dialog.close}>
                                {t('common.close')}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
