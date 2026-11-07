import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import type { MediaFolderOption } from '@/types/media-item';

/** A Select item cannot hold an empty value, so "no folder" travels as this until it is posted. */
const NO_FOLDER = 'none';

type Props = {
    id: string;
    /** The input the form posts: the folder's id, or empty for no folder. */
    name?: string;
    folders: MediaFolderOption[];
    value: string | null;
    onChange: (folderId: string | null) => void;
    disabled?: boolean;
};

/**
 * Picks one folder of the library, or none.
 */
export function FolderSelect({
    id,
    name,
    folders,
    value,
    onChange,
    disabled = false,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            {name !== undefined && (
                <input type="hidden" name={name} value={value ?? ''} />
            )}
            <Select
                value={value ?? NO_FOLDER}
                onValueChange={(next) =>
                    onChange(next === NO_FOLDER ? null : next)
                }
                disabled={disabled}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NO_FOLDER}>
                        {t('media-items.no_folder')}
                    </SelectItem>
                    {folders.map((folder) => (
                        <SelectItem key={folder.id} value={folder.id}>
                            {folder.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </>
    );
}
