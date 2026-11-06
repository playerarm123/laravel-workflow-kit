<?php

namespace App\Infra\Media;

use App\Application\Media\MediaQuota;
use App\Application\Media\MediaQuotaUsage;
use App\Models\MediaItem;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * MediaQuota that sets no limit, which the kit binds until a project binds its own in its
 * MediaServiceProvider. It still reads what the library uses, for the page to show.
 */
class UnlimitedMediaQuota implements MediaQuota
{
    #[Override]
    public function assertRoomFor(int $addedBytes): void {}

    #[Override]
    public function usage(): MediaQuotaUsage
    {
        return new MediaQuotaUsage(
            usedBytes: (int) MediaItem::query()->sum(DB::raw('size_bytes + thumbnail_size_bytes')),
            limitBytes: null,
        );
    }
}
