import type { DtFilters } from './data-table';

/**
 * One place an image is used, such as a profile's photo.
 */
export type MediaUsageRow = {
    usable_type: string;
    label: string;
};

/**
 * @see App\Application\Media\UseCases\ListMediaItems\MediaItemListRow::toArray()
 */
export type MediaItemRow = {
    id: string;
    name: string;
    alt_text: string | null;
    folder_id: string | null;
    folder_name: string | null;
    url: string;
    thumbnail_url: string;
    /** the original and its thumbnail together, as the quota counts them */
    size_bytes: number;
    width: number;
    height: number;
    usages: MediaUsageRow[];
    created_at: string;
};

/**
 * @see App\Application\Media\UseCases\ListMediaItems\ListMediaItemsCriteria::toFilters()
 */
export type MediaItemFilters = DtFilters & {
    folder: string | null;
    usage: string | null;
};

/**
 * @see App\Application\Media\MediaFolderOption::toArray()
 */
export type MediaFolderOption = {
    id: string;
    name: string;
    item_count: number;
};

/**
 * What the library uses and what its quota lets it use; `limit_bytes` null means no limit.
 *
 * @see App\Application\Media\MediaQuotaUsage::toArray()
 */
export type MediaQuotaUsage = {
    used_bytes: number;
    limit_bytes: number | null;
};

/**
 * What an upload may hold, from config/media.php.
 *
 * @see App\Http\Controllers\MediaItemController::limits()
 */
export type MediaLimits = {
    max_files: number;
    max_kb: number;
    extensions: string[];
};
