import { ImagePlus, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MediaImage } from '@/components/media-item/image';
import { MediaPickerDialog } from '@/components/media-item/picker-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { MediaItemRow } from '@/types/media-item';

type Props = {
    /** The input the form posts: the image's id, or empty for none. */
    name: string;
    label: string;
    value: MediaItemRow | null;
    onChange: (mediaItem: MediaItemRow | null) => void;
    error?: string;
};

/**
 * A field of another page's form that holds one library image: a preview, a button that opens
 * the picker and a hidden input with the id, so an uncontrolled `<Form>` posts it unaided.
 */
export function MediaPickerField({
    name,
    label,
    value,
    onChange,
    error,
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <input type="hidden" name={name} value={value?.id ?? ''} />
            <div className="flex items-center gap-3">
                <MediaImage
                    src={value?.thumbnail_url ?? null}
                    alt={value?.alt_text ?? value?.name ?? label}
                    className="size-24 rounded-md border"
                />
                <div className="flex flex-col gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setOpen(true)}
                    >
                        <ImagePlus className="size-4" />
                        {value === null
                            ? t('media-items.choose')
                            : t('media-items.change')}
                    </Button>
                    {value !== null && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onChange(null)}
                        >
                            <X className="size-4" />
                            {t('media-items.clear')}
                        </Button>
                    )}
                </div>
            </div>
            <InputError message={error} />

            <MediaPickerDialog
                open={open}
                onOpenChange={setOpen}
                onPick={onChange}
                pickedId={value?.id}
            />
        </div>
    );
}
