<?php

namespace App\Models;

use App\Models\Concerns\KeyedByUuid;
use Database\Factories\MediaUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $media_item_id
 * @property string $usable_type
 * @property string $usable_id
 * @property string $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['media_item_id', 'usable_type', 'usable_id', 'label'])]
class MediaUsage extends Model
{
    /** @use HasFactory<MediaUsageFactory> */
    use HasFactory, KeyedByUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
