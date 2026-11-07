import { useForm } from '@inertiajs/react';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/media-items';
import type { MediaFolderOption, MediaLimits } from '@/types/media-item';

type Props = {
    open: boolean;
    onOpenChange: (isOpen: boolean) => void;
    folders: MediaFolderOption[];
    limits: MediaLimits;
    /** The folder the grid shows now, which new images go to unless changed. */
    folderId: string | null;
};

/**
 * Uploads images to the library from its page. The queue is the form's state, so the dialog
 * posts with `useForm` rather than an uncontrolled `<Form>`; the server answers with a toast and
 * the grid reloads where it stands.
 */
export function MediaUploadDialog({
    open,
    onOpenChange,
    folders,
    limits,
    folderId,
}: Props) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('media-items.upload_title')}</DialogTitle>
                    <DialogDescription>
                        {t('media-items.upload_description')}
                    </DialogDescription>
                </DialogHeader>
                {open && (
                    <UploadForm
                        folders={folders}
                        limits={limits}
                        folderId={folderId}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

type FormProps = {
    folders: MediaFolderOption[];
    limits: MediaLimits;
    folderId: string | null;
    onDone: () => void;
};

/** Mounted each time the dialog opens, so the queue starts empty. */
function UploadForm({ folders, limits, folderId, onDone }: FormProps) {
    const { t } = useTranslation();
    const form = useForm<MediaUploadValues>({
        images: [],
        folder_id: folderId,
    });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(store.url(), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="space-y-4"
        >
            <MediaUploadFields form={form} folders={folders} limits={limits} />
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={form.processing}
                >
                    {t('common.cancel')}
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || form.data.images.length === 0}
                >
                    {form.processing && <Spinner />}
                    {t('media-items.upload_submit', {
                        count: form.data.images.length,
                    })}
                </Button>
            </DialogFooter>
        </form>
    );
}
