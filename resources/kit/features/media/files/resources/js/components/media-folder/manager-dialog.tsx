import { Form, router } from '@inertiajs/react';
import { Check, Folder, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import MediaFolderController from '@/actions/App/Http/Controllers/MediaFolderController';
import { ConfirmDialog } from '@/components/dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useConfirmDialog } from '@/hooks/use-dialog';
import { useTranslation } from '@/hooks/use-translation';
import type { MediaFolderOption } from '@/types/media-item';

type Props = {
    open: boolean;
    onOpenChange: (isOpen: boolean) => void;
    folders: MediaFolderOption[];
};

/**
 * Adds, renames and deletes the library's folders. A deleted folder's images stay in the
 * library, in no folder, which the confirm says.
 */
export function MediaFolderManagerDialog({
    open,
    onOpenChange,
    folders,
}: Props) {
    const { t } = useTranslation();
    const [editingId, setEditingId] = useState<string | null>(null);
    const deleteDialog = useConfirmDialog<MediaFolderOption>((folder) => {
        router.delete(MediaFolderController.destroy.url(folder.id), {
            preserveScroll: true,
        });
    });

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{t('media-folders.title')}</DialogTitle>
                        <DialogDescription>
                            {t('media-folders.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...MediaFolderController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-1"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="flex gap-2">
                                    <Input
                                        name="name"
                                        maxLength={60}
                                        required
                                        placeholder={t(
                                            'media-folders.name_placeholder',
                                        )}
                                        aria-label={t('media-folders.name')}
                                    />
                                    <Button type="submit" disabled={processing}>
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Plus className="size-4" />
                                        )}
                                        {t('media-folders.add')}
                                    </Button>
                                </div>
                                <InputError message={errors.name} />
                            </>
                        )}
                    </Form>

                    {folders.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {t('media-folders.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {folders.map((folder) => (
                                <li
                                    key={folder.id}
                                    className="flex items-center gap-2 p-2"
                                    data-test="media-folder-row"
                                >
                                    {editingId === folder.id ? (
                                        <RenameForm
                                            folder={folder}
                                            onDone={() => setEditingId(null)}
                                        />
                                    ) : (
                                        <>
                                            <Folder className="size-4 shrink-0 text-muted-foreground" />
                                            <span className="min-w-0 flex-1 truncate text-sm">
                                                {folder.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t('media-folders.item_count', {
                                                    count: folder.item_count,
                                                })}
                                            </span>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                aria-label={t(
                                                    'media-folders.rename',
                                                )}
                                                onClick={() =>
                                                    setEditingId(folder.id)
                                                }
                                            >
                                                <Pencil className="size-3.5" />
                                            </Button>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="size-7 text-destructive"
                                                aria-label={t(
                                                    'common.action_delete',
                                                )}
                                                onClick={() =>
                                                    deleteDialog.open(folder)
                                                }
                                            >
                                                <Trash2 className="size-3.5" />
                                            </Button>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleteDialog.target !== null}
                onOpenChange={deleteDialog.onOpenChange}
                title={t('media-folders.confirm_delete', {
                    name: deleteDialog.target?.name ?? '',
                })}
                description={t('media-folders.confirm_delete_description', {
                    count: deleteDialog.target?.item_count ?? 0,
                })}
                confirmLabel={t('common.action_delete')}
                onConfirm={deleteDialog.confirm}
                onCancel={deleteDialog.close}
                destructive
            />
        </>
    );
}

type RenameProps = {
    folder: MediaFolderOption;
    onDone: () => void;
};

function RenameForm({ folder, onDone }: RenameProps) {
    const { t } = useTranslation();

    return (
        <Form
            {...MediaFolderController.update.form(folder.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="flex flex-1 flex-col gap-1"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex gap-1">
                        <Input
                            name="name"
                            defaultValue={folder.name}
                            maxLength={60}
                            required
                            autoFocus
                            aria-label={t('media-folders.name')}
                        />
                        <Button
                            type="submit"
                            size="icon"
                            disabled={processing}
                            aria-label={t('common.save')}
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Check className="size-4" />
                            )}
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            onClick={onDone}
                            aria-label={t('common.cancel')}
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                    <InputError message={errors.name} />
                </>
            )}
        </Form>
    );
}
