<?php

namespace App\Infra\Media;

use App\Application\Media\MediaFolderDirectory;
use App\Application\Media\MediaQuota;
use App\Application\Media\MediaStorage;
use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsQuery;
use App\Domain\Media\MediaFolder\MediaFolderRepository;
use App\Domain\Media\MediaItem\MediaItemRepository;
use App\Infra\Persistence\Eloquent\Queries\EloquentListMediaItemsQuery;
use App\Infra\Persistence\Eloquent\Repositories\EloquentMediaFolderRepository;
use App\Infra\Persistence\Eloquent\Repositories\EloquentMediaItemRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the image library's ports to their adapters. The kit writes it once and the project owns
 * it, so a project binds its own adapter here: a MediaQuota that reads its plans, say, in place of
 * UnlimitedMediaQuota.
 */
class MediaServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        MediaItemRepository::class => EloquentMediaItemRepository::class,
        MediaFolderRepository::class => EloquentMediaFolderRepository::class,
        MediaStorage::class => InterventionMediaStorage::class,
        MediaQuota::class => UnlimitedMediaQuota::class,
        MediaFolderDirectory::class => EloquentMediaFolderDirectory::class,
        ListMediaItemsQuery::class => EloquentListMediaItemsQuery::class,
    ];
}
