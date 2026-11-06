import { Head, InfiniteScroll, router, setLayoutProps } from '@inertiajs/react';
import { FolderCog, FolderInput, Upload, X } from 'lucide-react';
import { useState } from 'react';
import { DeleteConfirmDialog } from '@/components/dialog';
import Heading from '@/components/heading';
import { MediaFolderManagerDialog } from '@/components/media-folder/manager-dialog';
import { MediaItemCard } from '@/components/media-item/card';
import { MediaItemEditDialog } from '@/components/media-item/edit-dialog';
import { MediaItemMoveDialog } from '@/components/media-item/move-dialog';
import { MediaItemPreviewDialog } from '@/components/media-item/preview-dialog';
import { MediaStorageCard } from '@/components/media-item/storage-card';
import { MediaItemTableToolbar } from '@/components/media-item/table-toolbar';
import { MediaUploadDialog } from '@/components/media-item/upload-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useConfirmDialog, useItemDialog } from '@/hooks/use-dialog';
import { useListQuery } from '@/hooks/use-list-query';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, index } from '@/routes/media-items';
import type { Paginated } from '@/types';
import type {
    MediaFolderOption,
    MediaItemFilters,
    MediaItemRow,
    MediaLimits,
    MediaQuotaUsage,
} from '@/types/media-item';

type Props = {
    mediaItems: Paginated<MediaItemRow>;
    filters: MediaItemFilters;
    storage: MediaQuotaUsage;
    folders: MediaFolderOption[];
    limits: MediaLimits;
    can: {
        create: boolean;
        update: boolean;
        move: boolean;
        delete: boolean;
        createMediaFolder: boolean;
    };
};

export default function Index({
    mediaItems,
    filters,
    storage,
    folders,
    limits,
    can,
}: Props) {
    const { t } = useTranslation();
    const title: string = t('media-items.title');

    setLayoutProps({
        breadcrumbs: [{ title, href: index() }],
    });

    const [isUploading, setIsUploading] = useState(false);
    const [isManagingFolders, setIsManagingFolders] = useState(false);
    const [isMoving, setIsMoving] = useState(false);
    const [selectedIds, setSelectedIds] = useState<string[]>([]);

    const previewDialog = useItemDialog<MediaItemRow>();
    const editDialog = useItemDialog<MediaItemRow>();
    const deleteDialog = useConfirmDialog<MediaItemRow>((mediaItem) => {
        router.delete(destroy.url(mediaItem.id), { preserveScroll: true });
    });

    /** A grid has no sort or page size, so the query is the filters alone; a new visit starts the scroll prop again from page one. */
    const list = useListQuery<MediaItemFilters>({
        query: filters,
        visit: (query) => {
            router.get(
                index.url({ query }),
                {},
                { preserveState: true, replace: true },
            );
        },
    });

    const toggleSelected = (mediaItem: MediaItemRow) =>
        setSelectedIds((ids) =>
            ids.includes(mediaItem.id)
                ? ids.filter((id) => id !== mediaItem.id)
                : [...ids, mediaItem.id],
        );

    /** The folder the grid shows, which an upload goes to unless changed there. */
    const currentFolderId =
        filters.folder !== null && filters.folder !== 'none'
            ? filters.folder
            : null;

    return (
        <>
            <Head title={title} />

            <div className="space-y-6 px-4 py-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={title}
                        description={t('media-items.description')}
                        variant="small"
                    />
                    <div className="flex flex-wrap gap-2">
                        {can.createMediaFolder && (
                            <Button
                                variant="outline"
                                onClick={() => setIsManagingFolders(true)}
                            >
                                <FolderCog className="size-4" />
                                {t('media-items.manage_folders')}
                            </Button>
                        )}
                        {can.create && (
                            <Button onClick={() => setIsUploading(true)}>
                                <Upload className="size-4" />
                                {t('media-items.upload')}
                            </Button>
                        )}
                    </div>
                </div>

                <MediaStorageCard storage={storage} />

                <MediaItemTableToolbar dt={list} folders={folders} />

                {can.move && selectedIds.length > 0 && (
                    <div
                        className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-2 text-sm"
                        data-test="media-selection-bar"
                    >
                        <span className="px-2">
                            {t('media-items.selected', {
                                count: selectedIds.length,
                            })}
                        </span>
                        <Button size="sm" onClick={() => setIsMoving(true)}>
                            <FolderInput className="size-4" />
                            {t('media-items.move')}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setSelectedIds([])}
                        >
                            <X className="size-4" />
                            {t('media-items.clear_selection')}
                        </Button>
                    </div>
                )}

                <InfiniteScroll
                    data="mediaItems"
                    buffer={300}
                    loading={<Spinner className="mx-auto my-4" />}
                >
                    {mediaItems.data.length === 0 ? (
                        <Card>
                            <CardContent className="p-8 text-center">
                                <p className="font-medium">
                                    {list.hasFilters
                                        ? t('media-items.no_results_title')
                                        : t('media-items.empty_title')}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {list.hasFilters
                                        ? t(
                                              'media-items.no_results_description',
                                          )
                                        : t('media-items.empty_description')}
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                            {mediaItems.data.map((mediaItem) => (
                                <MediaItemCard
                                    key={mediaItem.id}
                                    mediaItem={mediaItem}
                                    selected={selectedIds.includes(
                                        mediaItem.id,
                                    )}
                                    onToggleSelected={
                                        can.move ? toggleSelected : undefined
                                    }
                                    onPreview={previewDialog.open}
                                    onEdit={
                                        can.update ? editDialog.open : undefined
                                    }
                                    onDelete={
                                        can.delete
                                            ? deleteDialog.open
                                            : undefined
                                    }
                                />
                            ))}
                        </div>
                    )}
                </InfiniteScroll>
            </div>

            <MediaItemPreviewDialog dialog={previewDialog} />
            <MediaItemEditDialog dialog={editDialog} folders={folders} />
            <DeleteConfirmDialog
                dialog={deleteDialog}
                title={t('media-items.confirm_delete', {
                    name: deleteDialog.target?.name ?? '',
                })}
            />
            <MediaUploadDialog
                open={isUploading}
                onOpenChange={setIsUploading}
                folders={folders}
                limits={limits}
                folderId={currentFolderId}
            />
            <MediaFolderManagerDialog
                open={isManagingFolders}
                onOpenChange={setIsManagingFolders}
                folders={folders}
            />
            <MediaItemMoveDialog
                open={isMoving}
                onOpenChange={setIsMoving}
                ids={selectedIds}
                folders={folders}
                onMoved={() => {
                    setIsMoving(false);
                    setSelectedIds([]);
                }}
            />
        </>
    );
}
