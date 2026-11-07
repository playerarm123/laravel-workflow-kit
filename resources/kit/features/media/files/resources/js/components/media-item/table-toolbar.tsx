import { DataTableToolbar } from '@/components/dt-toolbar';
import type { DtFilterField } from '@/hooks/use-data-table-toolbar';
import type { ListQuery } from '@/hooks/use-list-query';
import { useTranslation } from '@/hooks/use-translation';
import type { MediaFolderOption, MediaItemFilters } from '@/types/media-item';

type Props = {
    dt: ListQuery<MediaItemFilters>;
    folders: MediaFolderOption[];
};

/** Search, the filter dialog and the chips come from `DataTableToolbar`; this file only names the fields. */
export function MediaItemTableToolbar({ dt, folders }: Props) {
    const { t } = useTranslation();

    const fields: DtFilterField<MediaItemFilters>[] = [
        {
            key: 'folder',
            label: 'media-items.filter_folder',
            options: [
                { value: 'none', label: 'media-items.no_folder' },
                ...folders.map((folder) => ({
                    value: folder.id,
                    label: folder.name,
                })),
            ],
        },
        {
            key: 'usage',
            label: 'media-items.filter_usage',
            options: [
                { value: 'in_use', label: 'media-items.usage.in_use' },
                { value: 'unused', label: 'media-items.usage.unused' },
            ],
        },
    ];

    return (
        <DataTableToolbar
            dt={dt}
            searchPlaceholder={t('media-items.search_placeholder')}
            fields={fields}
        />
    );
}
