<?php

namespace App\Models;

use App\Models\Concerns\KeyedByUuid;
use App\Policies\MediaItemPolicy;
use Database\Factories\MediaItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $folder_id
 * @property string $name
 * @property string|null $alt_text
 * @property string $disk
 * @property string $path
 * @property string $thumbnail_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $thumbnail_size_bytes
 * @property int $width
 * @property int $height
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['folder_id', 'name', 'alt_text', 'disk', 'path', 'thumbnail_path', 'mime_type', 'size_bytes', 'thumbnail_size_bytes', 'width', 'height'])]
#[UsePolicy(MediaItemPolicy::class)]
class MediaItem extends Model
{
    /** @use HasFactory<MediaItemFactory> */
    use HasFactory, KeyedByUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'thumbnail_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /**
     * @return HasMany<MediaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }
}
