import { Form } from '@inertiajs/react';
import { useState } from 'react';
import MediaItemController from '@/actions/App/Http/Controllers/MediaItemController';
import InputError from '@/components/input-error';
import { FolderSelect } from '@/components/media-item/folder-select';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ItemDialog } from '@/hooks/use-dialog';
import { useTranslation } from '@/hooks/use-translation';
import type { MediaFolderOption, MediaItemRow } from '@/types/media-item';

type Props = {
    dialog: ItemDialog<MediaItemRow>;
    folders: MediaFolderOption[];
};

/**
 * Renames an image, changes its alt text, or moves it to another folder. The file is not in
 * the form: a new picture is a new upload.
 */
export function MediaItemEditDialog({ dialog, folders }: Props) {
    const { t } = useTranslation();
    const mediaItem = dialog.target;

    return (
        <Dialog open={mediaItem !== null} onOpenChange={dialog.onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('media-items.edit_title')}</DialogTitle>
                    <DialogDescription>{mediaItem?.name}</DialogDescription>
                </DialogHeader>
                {mediaItem !== null && (
                    <EditForm
                        key={mediaItem.id}
                        mediaItem={mediaItem}
                        folders={folders}
                        onDone={dialog.close}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

type FormProps = {
    mediaItem: MediaItemRow;
    folders: MediaFolderOption[];
    onDone: () => void;
};

function EditForm({ mediaItem, folders, onDone }: FormProps) {
    const { t } = useTranslation();
    const [folderId, setFolderId] = useState(mediaItem.folder_id);

    return (
        <Form
            {...MediaItemController.update.form(mediaItem.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <MediaImage
                        src={mediaItem.thumbnail_url}
                        alt={mediaItem.alt_text ?? mediaItem.name}
                        className="mx-auto size-32 rounded-md border"
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="media-edit-name">
                            {t('media-items.name')}
                        </Label>
                        <Input
                            id="media-edit-name"
                            name="name"
                            defaultValue={mediaItem.name}
                            maxLength={150}
                            required
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="media-edit-alt">
                            {t('media-items.alt_text')}
                        </Label>
                        <Input
                            id="media-edit-alt"
                            name="alt_text"
                            defaultValue={mediaItem.alt_text ?? ''}
                            maxLength={255}
                            placeholder={t('media-items.alt_text_placeholder')}
                        />
                        <InputError message={errors.alt_text} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="media-edit-folder">
                            {t('media-items.folder')}
                        </Label>
                        <FolderSelect
                            id="media-edit-folder"
                            name="folder_id"
                            folders={folders}
                            value={folderId}
                            onChange={setFolderId}
                        />
                        <InputError message={errors.folder_id} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
