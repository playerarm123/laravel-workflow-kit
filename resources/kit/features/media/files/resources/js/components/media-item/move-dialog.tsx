import { Form } from '@inertiajs/react';
import { useState } from 'react';
import MediaItemBulkMoveController from '@/actions/App/Http/Controllers/MediaItemBulkMoveController';
import InputError from '@/components/input-error';
import { FolderSelect } from '@/components/media-item/folder-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { MediaFolderOption } from '@/types/media-item';

type Props = {
    open: boolean;
    onOpenChange: (isOpen: boolean) => void;
    ids: string[];
    folders: MediaFolderOption[];
    /** Called once the move landed, so the page clears its selection. */
    onMoved: () => void;
};

/**
 * Moves the selected images into one folder, or out of every folder. The server skips an
 * image already there and says how many moved.
 */
export function MediaItemMoveDialog({
    open,
    onOpenChange,
    ids,
    folders,
    onMoved,
}: Props) {
    const { t } = useTranslation();
    const [folderId, setFolderId] = useState<string | null>(null);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('media-items.move_title')}</DialogTitle>
                    <DialogDescription>
                        {t('media-items.move_description', {
                            count: ids.length,
                        })}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...MediaItemBulkMoveController.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={onMoved}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {ids.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="ids[]"
                                    value={id}
                                />
                            ))}
                            <div className="grid gap-2">
                                <Label htmlFor="media-move-folder">
                                    {t('media-items.folder')}
                                </Label>
                                <FolderSelect
                                    id="media-move-folder"
                                    name="folder_id"
                                    folders={folders}
                                    value={folderId}
                                    onChange={setFolderId}
                                />
                                <InputError
                                    message={errors.folder_id ?? errors.ids}
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    {t('common.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('media-items.move_submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
