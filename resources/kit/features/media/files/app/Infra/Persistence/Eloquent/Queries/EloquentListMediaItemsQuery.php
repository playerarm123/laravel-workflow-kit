<?php

namespace App\Infra\Persistence\Eloquent\Queries;

use App\Application\Media\MediaUsageFilter;
use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsCriteria;
use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsQuery;
use App\Application\Media\UseCases\ListMediaItems\MediaItemListRow;
use App\Infra\Media\MediaItemRows;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Override;

/**
 * See list-queries.md for the shape this adapter must take. The library lists newest first
 * only, so there is no sort enum to match.
 */
class EloquentListMediaItemsQuery extends EloquentListQuery implements ListMediaItemsQuery
{
    /**
     * Qualified columns the free-text search runs across — literals only.
     *
     * @var list<literal-string>
     */
    private const SEARCHABLE_COLUMNS = [
        'media_items.name',
        'media_items.alt_text',
    ];

    public function __construct(
        protected MediaItemRows $rows,
    ) {}

    #[Override]
    public function paginate(ListMediaItemsCriteria $criteria): LengthAwarePaginator
    {
        $query = MediaItem::query()
            ->with(['folder', 'usages' => fn (HasMany $usages) => $usages->orderBy('created_at')->orderBy('id')])
            ->tap(fn (Builder $query) => $this->applySearch($query, $criteria->search, self::SEARCHABLE_COLUMNS))
            ->when($criteria->folder === ListMediaItemsCriteria::NO_FOLDER, fn (Builder $query) => $query->whereNull('media_items.folder_id'))
            ->when($criteria->folder !== null && $criteria->folder !== ListMediaItemsCriteria::NO_FOLDER, fn (Builder $query) => $query->where('media_items.folder_id', $criteria->folder))
            ->when($criteria->usage === MediaUsageFilter::InUse, fn (Builder $query) => $query->whereHas('usages'))
            ->when($criteria->usage === MediaUsageFilter::Unused, fn (Builder $query) => $query->whereDoesntHave('usages'))
            ->when(! $criteria->createdAt->isEmpty(), fn (Builder $query) => $this->applyCreatedBetween(
                $query,
                $criteria->createdAt,
                'media_items.created_at',
            ))
            ->orderByDesc('media_items.created_at');

        return $this->paginateRows($query, $criteria->perPage, fn (MediaItem $mediaItem): MediaItemListRow => $this->rows->toRow($mediaItem));
    }
}
