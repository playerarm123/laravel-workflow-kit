import { X } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import InputError from '@/components/input-error';
import { Dropzone } from '@/components/media-item/dropzone';
import { FolderSelect } from '@/components/media-item/folder-select';
import { MediaImage } from '@/components/media-item/image';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/numbers';
import type { MediaFolderOption, MediaLimits } from '@/types/media-item';

/**
 * What an upload posts: the files as `images[]` and the folder they go to. The keys are those
 * `StoreMediaItemRequest` validates.
 */
export type MediaUploadValues = {
    images: File[];
    folder_id: string | null;
};

/** The part of `useForm` or `useHttp` the fields need; both hooks give it. */
export type MediaUploadState = {
    data: MediaUploadValues;
    setData: <K extends keyof MediaUploadValues>(
        key: K,
        value: MediaUploadValues[K],
    ) => void;
    /** Keyed as Laravel reports them, such as `images.0`. */
    errors: Partial<Record<string, string>>;
    processing: boolean;
};

type Props = {
    form: MediaUploadState;
    folders: MediaFolderOption[];
    limits: MediaLimits;
};

/**
 * The queue of files to upload, with a preview of each, and the folder they go to. The files
 * live in the form's state rather than in a file input, because dropped files cannot be put
 * back into one.
 */
export function MediaUploadFields({ form, folders, limits }: Props) {
    const { t } = useTranslation();
    const { images } = form.data;

    const previews = useMemo(
        () => images.map((file) => URL.createObjectURL(file)),
        [images],
    );

    useEffect(
        () => () => previews.forEach((url) => URL.revokeObjectURL(url)),
        [previews],
    );

    const addFiles = (files: File[]) => {
        const room = Math.max(limits.max_files - images.length, 0);

        form.setData('images', [...images, ...files.slice(0, room)]);
    };

    const errorOf = (index: number): string | undefined =>
        form.errors[`images.${index}`];

    return (
        <div className="space-y-4">
            <Dropzone
                accept={limits.extensions.map((ext) => `.${ext}`).join(',')}
                disabled={form.processing || images.length >= limits.max_files}
                onFiles={addFiles}
                hint={t('media-items.drop_hint')}
            />
            <p className="text-xs text-muted-foreground">
                {t('media-items.drop_limits', {
                    types: limits.extensions
                        .map((ext) => ext.toUpperCase())
                        .join(', '),
                    size: formatBytes(limits.max_kb * 1024),
                    max: limits.max_files,
                })}
            </p>
            <InputError message={form.errors.images} />

            {images.length > 0 && (
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {images.map((file, index) => (
                        <li
                            key={previews[index]}
                            className="relative overflow-hidden rounded-lg border bg-card"
                        >
                            <MediaImage
                                src={previews[index] ?? null}
                                alt={file.name}
                                className="aspect-square w-full"
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="secondary"
                                className="absolute top-1 right-1 size-7"
                                aria-label={t('media-items.remove_file', {
                                    name: file.name,
                                })}
                                disabled={form.processing}
                                onClick={() =>
                                    form.setData(
                                        'images',
                                        images.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <X className="size-3.5" />
                            </Button>
                            <div className="p-2 text-xs">
                                <p className="truncate" title={file.name}>
                                    {file.name}
                                </p>
                                <p className="text-muted-foreground">
                                    {formatBytes(file.size)}
                                </p>
                                <InputError message={errorOf(index)} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <div className="grid gap-2">
                <Label htmlFor="media-upload-folder">
                    {t('media-items.folder')}
                </Label>
                <FolderSelect
                    id="media-upload-folder"
                    folders={folders}
                    value={form.data.folder_id}
                    onChange={(folderId) => form.setData('folder_id', folderId)}
                    disabled={form.processing}
                />
                <InputError message={form.errors.folder_id} />
            </div>
        </div>
    );
}
