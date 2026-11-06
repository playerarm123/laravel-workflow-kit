import { useHttp } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { MediaItemCard } from '@/components/media-item/card';
import { FolderSelect } from '@/components/media-item/folder-select';
import { MediaItemPreviewDialog } from '@/components/media-item/preview-dialog';
import { MediaUploadFields } from '@/components/media-item/upload-fields';
import type { MediaUploadValues } from '@/components/media-item/upload-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useItemDialog } from '@/hooks/use-dialog';
import { useTranslation } from '@/hooks/use-translation';
import { index, store } from '@/routes/media-items';
import type { Paginated } from '@/types';
import type {
    MediaFolderOption,
    MediaItemRow,
    MediaLimits,
} from '@/types/media-item';

const SEARCH_DEBOUNCE_MS = 300;

/**
 * What `MediaItemController::index` answers when asked for JSON.
 */
type PickerPage = {
    mediaItems: Paginated<MediaItemRow>;
    folders: MediaFolderOption[];
    limits: MediaLimits;
    can: { create: boolean };
};

type Props = {
    open: boolean;
    onOpenChange: (isOpen: boolean) => void;
    /** Called with the image the user confirms; the dialog then closes. */
    onPick: (mediaItem: MediaItemRow) => void;
    /** The image picked before the dialog opened, if any. */
    pickedId?: string | null;
};

type Tab = 'library' | 'upload';

/**
 * Picks an image of the library, or uploads one and picks it, from inside another page's form.
 * It asks the library's own url for JSON through `useHttp`, because it is not a page visit, and
 * pages with a "load more" button, because `<InfiniteScroll>` reads page props only.
 */
export function MediaPickerDialog({
    open,
    onOpenChange,
    onPick,
    pickedId = null,
}: Props) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{t('media-items.picker_title')}</DialogTitle>
                    <DialogDescription>
                        {t('media-items.picker_description')}
                    </DialogDescription>
                </DialogHeader>
                {/* Mounted again on each open, so its search, page and pick start clean. */}
                {open && (
                    <PickerBody
                        initialPickedId={pickedId}
                        onPick={(mediaItem) => {
                            onPick(mediaItem);
                            onOpenChange(false);
                        }}
                        onCancel={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

type BodyProps = {
    initialPickedId: string | null;
    onPick: (mediaItem: MediaItemRow) => void;
    onCancel: () => void;
};

function PickerBody({ initialPickedId, onPick, onCancel }: BodyProps) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<Tab>('library');
    const [search, setSearch] = useState('');
    const [folder, setFolder] = useState<string | null>(null);
    const [items, setItems] = useState<MediaItemRow[]>([]);
    const [nextPage, setNextPage] = useState<number | null>(null);
    const [meta, setMeta] = useState<Omit<PickerPage, 'mediaItems'> | null>(
        null,
    );
    const [picked, setPicked] = useState<MediaItemRow | null>(null);
    const previewDialog = useItemDialog<MediaItemRow>();

    const list = useHttp<Record<string, never>, PickerPage>({});
    const upload = useHttp<MediaUploadValues, { ids: string[] }>({
        images: [],
        folder_id: null,
    });

    /** The latest `list`, so the effect below calls it without listing the hook in its deps. */
    const listRef = useRef(list);

    useEffect(() => {
        listRef.current = list;
    });

    const load = (page: number, term: string, folderId: string | null) =>
        listRef.current.get(
            index.url({
                query: {
                    search: term === '' ? undefined : term,
                    folder: folderId ?? undefined,
                    page: page === 1 ? undefined : page,
                },
            }),
            {
                headers: { Accept: 'application/json' },
                onSuccess: (answer) => {
                    setMeta({
                        folders: answer.folders,
                        limits: answer.limits,
                        can: answer.can,
                    });
                    setItems((current) =>
                        page === 1
                            ? answer.mediaItems.data
                            : [...current, ...answer.mediaItems.data],
                    );
                    setNextPage(
                        answer.mediaItems.next_page_url === null
                            ? null
                            : answer.mediaItems.current_page + 1,
                    );
                },
            },
        );

    /** Loads at once on mount, then waits for the typing to stop; a new search starts at page one. */
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            void load(1, '', null);

            return;
        }

        const timer = setTimeout(
            () => void load(1, search, folder),
            SEARCH_DEBOUNCE_MS,
        );

        return () => clearTimeout(timer);
    }, [search, folder]);

    const submitUpload = () => {
        void upload.post(store.url(), {
            headers: { Accept: 'application/json' },
            onSuccess: ({ ids }) => {
                upload.setData('images', []);
                setTab('library');
                setSearch('');
                setFolder(null);
                /** The newest come first, so page one holds the upload; pick its first image. */
                void listRef.current.get(index.url(), {
                    headers: { Accept: 'application/json' },
                    onSuccess: (answer) => {
                        setItems(answer.mediaItems.data);
                        setNextPage(
                            answer.mediaItems.next_page_url === null
                                ? null
                                : answer.mediaItems.current_page + 1,
                        );
                        setPicked(
                            answer.mediaItems.data.find(
                                (mediaItem) => mediaItem.id === ids[0],
                            ) ?? null,
                        );
                    },
                });
            },
        });
    };

    const pickedItem =
        picked ??
        items.find((mediaItem) => mediaItem.id === initialPickedId) ??
        null;

    return (
        <>
            {meta?.can.create === true && (
                <div className="flex gap-1 border-b">
                    {(['library', 'upload'] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            className={
                                tab === value
                                    ? 'border-b-2 border-primary px-3 py-2 text-sm font-medium'
                                    : 'px-3 py-2 text-sm text-muted-foreground hover:text-foreground'
                            }
                            onClick={() => setTab(value)}
                        >
                            {t(`media-items.tab_${value}`)}
                        </button>
                    ))}
                </div>
            )}

            {tab === 'upload' && meta !== null ? (
                <form
                    className="min-h-0 space-y-4 overflow-y-auto"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submitUpload();
                    }}
                >
                    <MediaUploadFields
                        form={upload}
                        folders={meta.folders}
                        limits={meta.limits}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setTab('library')}
                            disabled={upload.processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                upload.processing ||
                                upload.data.images.length === 0
                            }
                        >
                            {upload.processing && <Spinner />}
                            {t('media-items.upload_submit', {
                                count: upload.data.images.length,
                            })}
                        </Button>
                    </DialogFooter>
                </form>
            ) : (
                <>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder={t(
                                    'media-items.search_placeholder',
                                )}
                                aria-label={t('common.search')}
                                className="pl-9"
                                autoFocus
                            />
                        </div>
                        <div className="sm:w-56">
                            <FolderSelect
                                id="media-picker-folder"
                                folders={meta?.folders ?? []}
                                value={folder}
                                onChange={setFolder}
                            />
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto pr-1">
                        {list.processing && items.length === 0 ? (
                            <Spinner className="mx-auto my-8" />
                        ) : items.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                {search === '' && folder === null
                                    ? t('media-items.empty_title')
                                    : t('media-items.no_results_title')}
                            </p>
                        ) : (
                            <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                                {items.map((mediaItem) => (
                                    <MediaItemCard
                                        key={mediaItem.id}
                                        mediaItem={mediaItem}
                                        selected={
                                            pickedItem?.id === mediaItem.id
                                        }
                                        onPick={setPicked}
                                        onPreview={previewDialog.open}
                                    />
                                ))}
                            </div>
                        )}
                        {nextPage !== null && (
                            <div className="flex justify-center py-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={list.processing}
                                    onClick={() =>
                                        void load(nextPage, search, folder)
                                    }
                                >
                                    {list.processing && <Spinner />}
                                    {t('media-items.load_more')}
                                </Button>
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCancel}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={pickedItem === null}
                            onClick={() => {
                                if (pickedItem !== null) {
                                    onPick(pickedItem);
                                }
                            }}
                        >
                            {t('media-items.pick')}
                        </Button>
                    </DialogFooter>

                    <MediaItemPreviewDialog dialog={previewDialog} />
                </>
            )}
        </>
    );
}
