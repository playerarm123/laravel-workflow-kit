import { Check, Eye, Folder, Pencil, Trash2 } from 'lucide-react';
import { MediaImage } from '@/components/media-item/image';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/numbers';
import { cn } from '@/lib/utils';
import type { MediaItemRow } from '@/types/media-item';

type Props = {
    mediaItem: MediaItemRow;
    /** The picker's mode: the whole thumbnail picks the image. */
    onPick?: (mediaItem: MediaItemRow) => void;
    /** The library's selection for a bulk move: a checkbox on the card. */
    onToggleSelected?: (mediaItem: MediaItemRow) => void;
    selected?: boolean;
    onPreview?: (mediaItem: MediaItemRow) => void;
    onEdit?: (mediaItem: MediaItemRow) => void;
    onDelete?: (mediaItem: MediaItemRow) => void;
};

/**
 * One image of the library: a square thumbnail, its name, folder and size, and how many places
 * use it. A button shows only when the page passes its handler, which it gates with `can`.
 * Clicking the thumbnail picks it in the picker and opens it large in the library.
 */
export function MediaItemCard({
    mediaItem,
    onPick,
    onToggleSelected,
    selected = false,
    onPreview,
    onEdit,
    onDelete,
}: Props) {
    const { t } = useTranslation();
    const picking = onPick !== undefined;
    const onThumbnailClick = picking ? onPick : onPreview;
    const showPreviewButton = picking && onPreview !== undefined;
    const usageCount = mediaItem.usages.length;

    const thumbnail = (
        <MediaImage
            src={mediaItem.thumbnail_url}
            alt={mediaItem.alt_text ?? mediaItem.name}
            className="aspect-square w-full"
        />
    );

    return (
        <div
            data-test="media-item-card"
            className={cn(
                'group relative flex flex-col overflow-hidden rounded-lg border bg-card text-card-foreground transition-shadow',
                picking && 'hover:shadow-md',
                selected && 'ring-2 ring-primary',
            )}
        >
            {onThumbnailClick !== undefined ? (
                <button
                    type="button"
                    className={cn(
                        'text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        !picking && 'cursor-zoom-in',
                    )}
                    onClick={() => onThumbnailClick(mediaItem)}
                    aria-label={
                        picking
                            ? t('media-items.pick')
                            : t('media-items.preview')
                    }
                    {...(picking ? { 'aria-pressed': selected } : {})}
                >
                    {thumbnail}
                </button>
            ) : (
                thumbnail
            )}

            {picking && selected && (
                <span className="absolute top-2 left-2 rounded-full bg-primary p-1 text-primary-foreground">
                    <Check className="size-3" />
                </span>
            )}

            {onToggleSelected !== undefined && (
                <div className="absolute top-2 left-2 rounded bg-card/90 p-1 shadow-sm">
                    <Checkbox
                        checked={selected}
                        onCheckedChange={() => onToggleSelected(mediaItem)}
                        aria-label={t('media-items.select_image', {
                            name: mediaItem.name,
                        })}
                    />
                </div>
            )}

            {(showPreviewButton ||
                onEdit !== undefined ||
                onDelete !== undefined) && (
                <div className="absolute top-2 right-2 flex gap-1 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
                    {showPreviewButton && (
                        <Button
                            type="button"
                            size="icon"
                            variant="secondary"
                            className="size-7"
                            aria-label={t('media-items.preview')}
                            onClick={() => onPreview(mediaItem)}
                        >
                            <Eye className="size-3.5" />
                        </Button>
                    )}
                    {onEdit !== undefined && (
                        <Button
                            type="button"
                            size="icon"
                            variant="secondary"
                            className="size-7"
                            aria-label={t('common.action_edit')}
                            onClick={() => onEdit(mediaItem)}
                        >
                            <Pencil className="size-3.5" />
                        </Button>
                    )}
                    {onDelete !== undefined && (
                        <Button
                            type="button"
                            size="icon"
                            variant="destructive"
                            className="size-7"
                            aria-label={t('common.action_delete')}
                            onClick={() => onDelete(mediaItem)}
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    )}
                </div>
            )}

            <div className="space-y-1 p-2">
                <p
                    className="truncate text-sm font-medium"
                    title={mediaItem.name}
                >
                    {mediaItem.name}
                </p>
                <p className="flex items-center gap-1 truncate text-xs text-muted-foreground">
                    {mediaItem.folder_name !== null && (
                        <>
                            <Folder className="size-3 shrink-0" />
                            <span className="truncate">
                                {mediaItem.folder_name}
                            </span>
                            <span>·</span>
                        </>
                    )}
                    <span>{formatBytes(mediaItem.size_bytes)}</span>
                </p>
                {usageCount > 0 && (
                    <Badge variant="secondary">
                        {t('media-items.used_in', { count: usageCount })}
                    </Badge>
                )}
            </div>
        </div>
    );
}
