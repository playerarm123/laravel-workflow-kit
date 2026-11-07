<?php

namespace App\Application\Media\UseCases\ListMediaItems;

use App\Application\Concerns\DateRange;
use App\Application\Concerns\PageSize;
use App\Application\Media\MediaUsageFilter;
use Illuminate\Support\Str;

/**
 * Lists the images of the library, newest first. A grid of thumbnails shows more per screen than
 * a table, so a page holds 25 unless asked otherwise.
 *
 * Nothing here rejects: an unknown filter or page size falls back, so a hand edited URL still
 * lists. A folder id that names no folder lists nothing.
 */
final class ListMediaItemsHandler
{
    private const PageSize DEFAULT_PAGE_SIZE = PageSize::TwentyFive;

    public function __construct(
        protected ListMediaItemsQuery $query,
    ) {}

    public function __invoke(ListMediaItemsCommand $command): ListMediaItemsResult
    {
        $criteria = new ListMediaItemsCriteria(
            search: trim($command->search),
            folder: $command->folder === ListMediaItemsCriteria::NO_FOLDER || Str::isUuid($command->folder) ? $command->folder : null,
            usage: MediaUsageFilter::tryFrom($command->usage),
            createdAt: DateRange::fromInput($command->createdFrom, $command->createdTo),
            perPage: (PageSize::tryFrom((int) $command->perPage) ?? self::DEFAULT_PAGE_SIZE)->value,
        );

        return new ListMediaItemsResult(
            mediaItems: $this->query->paginate($criteria),
            filters: $criteria->toFilters(),
        );
    }
}
